<?php

declare(strict_types=1);

namespace App\Domain\Ticket;

use App\Enums\Ticket\TicketTransactionType;
use App\Enums\Ticket\TicketWalletStatus;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Models\Customer;
use App\Models\Staff;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class TicketLedgerService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function append(
        TicketWallet $wallet,
        TicketTransactionType $type,
        int $delta,
        string $dedupeKey,
        ?int $reservationId = null,
        ?int $staffId = null,
        ?string $reason = null,
    ): TicketTransaction {
        $existing = TicketTransaction::query()
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $wallet,
                $type,
                $delta,
                $dedupeKey,
                $reservationId,
                $staffId,
                $reason,
            ): TicketTransaction {
                $locked = TicketWallet::query()
                    ->whereKey($wallet->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->validateDelta($type, $delta);

                $currentAvailable = (int) $locked->transactions()->sum('delta');
                $newAvailable = $currentAvailable + $delta;

                if ($newAvailable < 0) {
                    if ($type === TicketTransactionType::ReserveHold) {
                        throw new InsufficientTicketBalanceException;
                    }

                    throw ValidationException::withMessages([
                        'ticket' => __('messages.ticket.insufficient_balance'),
                    ]);
                }

                $transaction = TicketTransaction::query()->create([
                    'ticket_wallet_id' => $locked->getKey(),
                    'type' => $type,
                    'delta' => $delta,
                    'reservation_id' => $reservationId,
                    'staff_id' => $staffId,
                    'reason' => $reason,
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                ]);

                $changes = ['balance' => $newAvailable];

                if ($locked->status !== TicketWalletStatus::Expired) {
                    $changes['status'] = $newAvailable > 0
                        ? TicketWalletStatus::Active
                        : TicketWalletStatus::Exhausted;
                }

                $locked->update($changes);

                return $transaction;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = TicketTransaction::query()
                ->where('dedupe_key', $dedupeKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    public function available(TicketWallet $wallet): int
    {
        return (int) $wallet->transactions()->sum('delta');
    }

    public function held(TicketWallet $wallet): int
    {
        $holds = $wallet->transactions()
            ->where('type', TicketTransactionType::ReserveHold->value)
            ->count();
        $releases = $wallet->transactions()
            ->where('type', TicketTransactionType::ReserveRelease->value)
            ->count();

        return $holds - $releases;
    }

    public function total(TicketWallet $wallet): int
    {
        return $this->available($wallet) + $this->held($wallet);
    }

    /** @return array{available: int, held: int, total: int} */
    public function summary(TicketWallet $wallet): array
    {
        $available = $this->available($wallet);
        $held = $this->held($wallet);

        return [
            'available' => $available,
            'held' => $held,
            'total' => $available + $held,
        ];
    }

    public function grant(
        Customer $customer,
        TicketProduct $product,
        ?int $count,
        string $operationKey,
        ?string $reason = null,
        ?Authenticatable $actor = null,
    ): TicketWallet {
        $this->validateReason($reason);

        $dedupeKey = "grant:{$operationKey}";
        $existing = TicketTransaction::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('type', TicketTransactionType::Grant->value)
            ->first();

        if ($existing !== null) {
            return TicketWallet::query()->findOrFail($existing->ticket_wallet_id);
        }

        $grantCount = $count ?? (int) $product->total_count;

        if ($grantCount <= 0) {
            throw ValidationException::withMessages([
                'count' => __('messages.ticket.grant_positive'),
            ]);
        }

        return DB::transaction(function () use (
            $customer,
            $product,
            $grantCount,
            $dedupeKey,
            $reason,
            $actor,
        ): TicketWallet {
            $wallet = TicketWallet::query()->create([
                'customer_id' => $customer->getKey(),
                'ticket_product_id' => $product->getKey(),
                'purchased_count' => $grantCount,
                'balance' => 0,
                'expires_at' => today()->addDays((int) $product->validity_days),
                'status' => TicketWalletStatus::Active,
            ]);

            $transaction = $this->append(
                wallet: $wallet,
                type: TicketTransactionType::Grant,
                delta: $grantCount,
                dedupeKey: $dedupeKey,
                staffId: $this->actorId($actor),
                reason: $reason,
            );

            if ((int) $transaction->ticket_wallet_id !== (int) $wallet->getKey()) {
                $wallet->delete();

                return TicketWallet::query()->findOrFail($transaction->ticket_wallet_id);
            }

            $wallet->refresh();

            $this->auditLogger->log(
                'ticket.granted',
                $wallet,
                "回数券付与 wallet#{$wallet->id} +{$grantCount}（理由: {$reason}）",
                $actor,
            );

            return $wallet;
        });
    }

    public function revoke(
        TicketWallet $wallet,
        int $count,
        string $operationKey,
        string $reason,
        ?Authenticatable $actor = null,
    ): TicketTransaction {
        $this->validateReason($reason);

        if ($count <= 0) {
            throw ValidationException::withMessages([
                'count' => __('messages.ticket.revoke_positive'),
            ]);
        }

        $transaction = $this->append(
            wallet: $wallet,
            type: TicketTransactionType::Revoke,
            delta: -$count,
            dedupeKey: "revoke:{$operationKey}",
            staffId: $this->actorId($actor),
            reason: $reason,
        );

        if ($transaction->wasRecentlyCreated) {
            $this->auditLogger->log(
                'ticket.revoked',
                $wallet,
                "回数券取消 wallet#{$wallet->id} -{$count}（理由: {$reason}）",
                $actor,
            );
        }

        return $transaction;
    }

    public function adjust(
        TicketWallet $wallet,
        int $delta,
        string $operationKey,
        string $reason,
        ?Authenticatable $actor = null,
    ): TicketTransaction {
        $this->validateReason($reason);

        if ($delta === 0) {
            throw ValidationException::withMessages([
                'delta' => __('messages.ticket.adjust_nonzero'),
            ]);
        }

        $transaction = $this->append(
            wallet: $wallet,
            type: TicketTransactionType::Adjust,
            delta: $delta,
            dedupeKey: "adjust:{$operationKey}",
            staffId: $this->actorId($actor),
            reason: $reason,
        );

        if ($transaction->wasRecentlyCreated) {
            $this->auditLogger->log(
                'ticket.adjusted',
                $wallet,
                "回数券調整 wallet#{$wallet->id} {$this->signed($delta)}（理由: {$reason}）",
                $actor,
            );
        }

        return $transaction;
    }

    private function validateDelta(TicketTransactionType $type, int $delta): void
    {
        $valid = match ($type) {
            TicketTransactionType::ReserveHold,
            TicketTransactionType::Consume => $delta === -1,
            TicketTransactionType::ReserveRelease => $delta === 1,
            TicketTransactionType::Grant,
            TicketTransactionType::Purchase => $delta > 0,
            TicketTransactionType::Revoke,
            TicketTransactionType::Expire => $delta < 0,
            TicketTransactionType::Adjust => $delta !== 0,
        };

        if (! $valid) {
            throw new InvalidArgumentException("{$type->value} の delta が不正です。");
        }
    }

    /** @throws ValidationException */
    private function validateReason(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('messages.common.reason_required'),
            ]);
        }
    }

    private function actorId(?Authenticatable $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        $actorId = (int) $actor->getAuthIdentifier();

        return Staff::query()->whereKey($actorId)->exists() ? $actorId : null;
    }

    private function signed(int $delta): string
    {
        return $delta > 0 ? "+{$delta}" : (string) $delta;
    }
}
