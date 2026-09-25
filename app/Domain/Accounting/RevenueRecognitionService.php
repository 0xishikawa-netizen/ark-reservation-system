<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Enums\Accounting\RevenueContractKind;
use App\Enums\Accounting\RevenueRecognitionContractStatus;
use App\Models\Membership;
use App\Models\MembershipReservationUsage;
use App\Models\RevenueAllocation;
use App\Models\RevenueRecognitionContract;
use App\Models\TicketReservationUsage;
use App\Models\TicketWallet;
use App\Models\Visit;
use App\Models\VisitTreatment;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevenueRecognitionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function createTicketContract(TicketWallet $wallet, int $contractAmount, string $operationKey, array $attributes = [], ?Authenticatable $actor = null): RevenueRecognitionContract
    {
        return $this->createContract(
            RevenueContractKind::Ticket,
            ['ticket_wallet_id' => $wallet->getKey()],
            $contractAmount,
            $operationKey,
            $attributes,
            $actor,
        );
    }

    /** @param array<string, mixed> $attributes */
    public function createMembershipContract(Membership $membership, int $contractAmount, string $operationKey, array $attributes, ?Authenticatable $actor = null): RevenueRecognitionContract
    {
        if (! isset($attributes['period_start'], $attributes['period_end'])) {
            throw ValidationException::withMessages(['period_start' => '月額配賦には対象期間が必要です。']);
        }

        return $this->createContract(
            RevenueContractKind::Membership,
            ['membership_id' => $membership->getKey()],
            $contractAmount,
            $operationKey,
            $attributes,
            $actor,
        );
    }

    public function allocate(
        RevenueRecognitionContract $contract,
        int $amount,
        string $recognizedOn,
        string $operationKey,
        ?Visit $visit = null,
        ?VisitTreatment $treatment = null,
        ?TicketReservationUsage $ticketUsage = null,
        ?MembershipReservationUsage $membershipUsage = null,
        bool $isRemainder = false,
        ?Authenticatable $actor = null,
    ): RevenueAllocation {
        return DB::transaction(function () use ($contract, $amount, $recognizedOn, $operationKey, $visit, $treatment, $ticketUsage, $membershipUsage, $isRemainder, $actor): RevenueAllocation {
            $locked = RevenueRecognitionContract::query()->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();
            $existing = RevenueAllocation::query()->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ((int) $existing->revenue_recognition_contract_id !== (int) $locked->getKey()
                    || (int) $existing->amount !== $amount) {
                    throw ValidationException::withMessages(['operation_key' => '操作キーが別内容の収益配賦で使用されています。']);
                }

                return $existing;
            }
            if ($locked->status !== RevenueRecognitionContractStatus::Active || $amount < 0) {
                throw ValidationException::withMessages(['contract' => '有効な配賦契約と0円以上の金額が必要です。']);
            }
            $this->validateUsage($locked, $ticketUsage, $membershipUsage);

            $allocated = (int) RevenueAllocation::query()
                ->where('revenue_recognition_contract_id', $locked->getKey())->lockForUpdate()->sum('amount');
            if ($allocated + $amount > (int) $locked->contract_amount) {
                throw ValidationException::withMessages(['amount' => '配賦合計が元契約金額を超えます。']);
            }

            $allocationNo = (int) RevenueAllocation::query()
                ->where('revenue_recognition_contract_id', $locked->getKey())->max('allocation_no') + 1;
            $allocation = RevenueAllocation::query()->create([
                'revenue_recognition_contract_id' => $locked->getKey(),
                'visit_id' => $visit?->getKey(),
                'visit_treatment_id' => $treatment?->getKey(),
                'ticket_reservation_usage_id' => $ticketUsage?->getKey(),
                'membership_reservation_usage_id' => $membershipUsage?->getKey(),
                'recognized_on' => $recognizedOn,
                'amount' => $amount,
                'allocation_no' => $allocationNo,
                'is_remainder' => $isRemainder,
                'operation_key' => $operationKey,
            ]);
            $this->audit->log('revenue_allocation.recorded', $allocation, '施術日基準売上を配賦', $actor);

            return $allocation;
        });
    }

    public function close(RevenueRecognitionContract $contract, ?Authenticatable $actor = null): RevenueRecognitionContract
    {
        return DB::transaction(function () use ($contract, $actor): RevenueRecognitionContract {
            $locked = RevenueRecognitionContract::query()->whereKey($contract->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === RevenueRecognitionContractStatus::Closed) {
                return $locked;
            }
            $total = (int) RevenueAllocation::query()
                ->where('revenue_recognition_contract_id', $locked->getKey())->lockForUpdate()->sum('amount');
            if ($total !== (int) $locked->contract_amount) {
                throw ValidationException::withMessages(['allocations' => '配賦合計が元契約金額と一致しません。']);
            }
            $locked->forceFill(['status' => RevenueRecognitionContractStatus::Closed])->save();
            $this->audit->log('revenue_contract.closed', $locked, '収益配賦契約を完了', $actor);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $anchor @param array<string, mixed> $attributes */
    private function createContract(RevenueContractKind $kind, array $anchor, int $amount, string $operationKey, array $attributes, ?Authenticatable $actor): RevenueRecognitionContract
    {
        if ($amount < 0 || trim($operationKey) === '') {
            throw ValidationException::withMessages(['contract_amount' => '元契約金額と操作キーを指定してください。']);
        }

        return DB::transaction(function () use ($kind, $anchor, $amount, $operationKey, $attributes, $actor): RevenueRecognitionContract {
            $contract = RevenueRecognitionContract::query()->firstOrCreate(['operation_key' => $operationKey], [
                ...$attributes,
                ...$anchor,
                'kind' => $kind,
                'contract_amount' => $amount,
                'status' => RevenueRecognitionContractStatus::Active,
            ]);
            foreach ($anchor as $key => $value) {
                if ((int) $contract->{$key} !== (int) $value) {
                    throw ValidationException::withMessages(['operation_key' => '操作キーが別契約で使用されています。']);
                }
            }
            if ($contract->kind !== $kind || (int) $contract->contract_amount !== $amount) {
                throw ValidationException::withMessages(['operation_key' => '操作キーが別内容の契約で使用されています。']);
            }
            if ($contract->wasRecentlyCreated) {
                $this->audit->log('revenue_contract.created', $contract, '収益配賦契約を作成', $actor);
            }

            return $contract;
        });
    }

    private function validateUsage(
        RevenueRecognitionContract $contract,
        ?TicketReservationUsage $ticketUsage,
        ?MembershipReservationUsage $membershipUsage,
    ): void {
        if ($contract->kind === RevenueContractKind::Ticket) {
            if ($ticketUsage === null || $membershipUsage !== null || (int) $ticketUsage->ticket_wallet_id !== (int) $contract->ticket_wallet_id) {
                throw ValidationException::withMessages(['ticket_usage' => '回数券配賦と利用実績が一致しません。']);
            }

            return;
        }
        if ($membershipUsage === null || $ticketUsage !== null || (int) $membershipUsage->membership_id !== (int) $contract->membership_id) {
            throw ValidationException::withMessages(['membership_usage' => '月額配賦と利用実績が一致しません。']);
        }
    }
}
