<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Ticket\TicketLedgerService;
use App\Enums\Accounting\CheckoutLineItemType;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Enums\Accounting\TenderAllocationCategory;
use App\Enums\Ticket\TicketReservationUsageStatus;
use App\Enums\Ticket\TicketTransactionType;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\CheckoutTenderAllocation;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\StaffRevenueAllocation;
use App\Models\TaxCategory;
use App\Models\TicketProduct;
use App\Models\TicketReservationUsage;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\Visit;
use App\Models\VisitTreatmentStaff;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CheckoutService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TicketLedgerService $tickets,
    ) {}

    /**
     * 来店を伴わない会計（物販のみ・回数券購入のみ等）。架空Visitは作らない。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createStoreDraft(?Customer $customer, string $saleDate, array $attributes = [], ?Authenticatable $actor = null): Checkout
    {
        return DB::transaction(function () use ($customer, $saleDate, $attributes, $actor): Checkout {
            $checkout = Checkout::query()->create([
                ...$attributes,
                'visit_id' => null,
                'customer_id' => $customer?->getKey(),
                'sale_date' => $saleDate,
                'status' => CheckoutStatus::Draft,
            ]);
            $this->audit->log('checkout.created', $checkout, '来店なし会計の下書きを作成', $actor);

            return $checkout;
        });
    }

    /** 下書き会計の明細・支払・配分を空にする（入力画面の保存で全体を置き換えるため）。 */
    public function clearDraft(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = $this->lockDraft($checkout);
            $lineIds = CheckoutLine::query()->where('checkout_id', $locked->getKey())->pluck('id');
            StaffRevenueAllocation::query()->whereIn('checkout_line_id', $lineIds)->get()->each->delete();
            $tenderIds = CheckoutTender::query()->where('checkout_id', $locked->getKey())->pluck('id');
            CheckoutTenderAllocation::query()->whereIn('checkout_tender_id', $tenderIds)->get()->each->delete();
            CheckoutTender::query()->where('checkout_id', $locked->getKey())->get()->each->delete();
            CheckoutLine::query()->where('checkout_id', $locked->getKey())->get()->each->delete();

            return $locked;
        });
    }

    /**
     * 支払内訳を施術等／物販へ明示配分する（Task 11-20）。配分合計は支払額と一致しなければならない。
     *
     * @param  array<string, int>  $amounts  allocation_category => 金額
     */
    public function allocateTender(CheckoutTender $tender, array $amounts, ?Authenticatable $actor = null): void
    {
        DB::transaction(function () use ($tender, $amounts, $actor): void {
            $lockedTender = CheckoutTender::query()->whereKey($tender->getKey())->lockForUpdate()->firstOrFail();
            $this->lockDraft($lockedTender->checkout);
            $sum = 0;
            foreach ($amounts as $category => $amount) {
                if (TenderAllocationCategory::tryFrom((string) $category) === null || (int) $amount < 0) {
                    throw ValidationException::withMessages(['tender_allocations' => __('messages.checkout_entry.tender_allocation_invalid')]);
                }
                $sum += (int) $amount;
            }
            if ($sum !== (int) $lockedTender->amount) {
                throw ValidationException::withMessages(['tender_allocations' => __('messages.checkout_entry.tender_allocation_mismatch')]);
            }
            CheckoutTenderAllocation::query()->where('checkout_tender_id', $lockedTender->getKey())->get()->each->delete();
            foreach ($amounts as $category => $amount) {
                if ((int) $amount > 0) {
                    CheckoutTenderAllocation::query()->create([
                        'checkout_tender_id' => $lockedTender->getKey(),
                        'allocation_category' => $category,
                        'amount' => (int) $amount,
                    ]);
                }
            }
            $this->audit->log('checkout.tender_allocated', $lockedTender, '支払を施術等／物販へ配分', $actor);
        });
    }

    /** 下書き会計のヘッダー合計を明細snapshotの合計へ揃える。 */
    public function syncTotals(Checkout $checkout): Checkout
    {
        return DB::transaction(function () use ($checkout): Checkout {
            $locked = $this->lockDraft($checkout);
            $lines = CheckoutLine::query()->where('checkout_id', $locked->getKey())->get();
            $locked->forceFill([
                'subtotal_amount' => (int) $lines->sum('net_amount'),
                'tax_amount' => (int) $lines->sum('tax_amount'),
                'total_amount' => (int) $lines->sum('gross_amount'),
            ])->save();

            return $locked;
        });
    }

    /** 会計の購入者。来店会計は来店顧客、来店なし会計は会計の顧客。 */
    public function customerIdFor(Checkout $checkout): ?int
    {
        $customerId = $checkout->visit_id !== null
            ? Visit::query()->whereKey($checkout->visit_id)->value('customer_id')
            : $checkout->customer_id;

        return $customerId === null ? null : (int) $customerId;
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(Visit $visit, array $attributes = [], ?Authenticatable $actor = null): Checkout
    {
        return DB::transaction(function () use ($visit, $attributes, $actor): Checkout {
            Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $checkout = Checkout::query()->firstOrCreate(
                ['visit_id' => $visit->getKey()],
                [...$attributes, 'status' => CheckoutStatus::Draft],
            );
            if ($checkout->wasRecentlyCreated) {
                $this->audit->log('checkout.created', $checkout, '会計下書きを作成', $actor);
            }

            return $checkout;
        });
    }

    /**
     * 金額と税額は会計側で確定した値を受け取りsnapshotする。税計算の丸め方式はここで推測しない。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addLine(Checkout $checkout, array $attributes, ?TaxCategory $taxCategory = null, ?Authenticatable $actor = null): CheckoutLine
    {
        return DB::transaction(function () use ($checkout, $attributes, $taxCategory, $actor): CheckoutLine {
            $locked = $this->lockDraft($checkout);
            $this->validateLineAmounts($attributes);

            $values = [
                ...$attributes,
                'checkout_id' => $locked->getKey(),
                'tax_category_id' => $taxCategory?->getKey(),
                'tax_category_code_snapshot' => $attributes['tax_category_code_snapshot'] ?? $taxCategory?->code,
                'tax_category_name_snapshot' => $attributes['tax_category_name_snapshot'] ?? $taxCategory?->name,
            ];
            $operationKey = $attributes['operation_key'] ?? null;
            $line = is_string($operationKey) && $operationKey !== ''
                ? CheckoutLine::query()->firstOrCreate(['operation_key' => $operationKey], $values)
                : CheckoutLine::query()->create($values);
            if ((int) $line->checkout_id !== (int) $locked->getKey()
                || (int) $line->gross_amount !== (int) $attributes['gross_amount']) {
                throw ValidationException::withMessages(['operation_key' => __('messages.checkout.operation_key_line_conflict')]);
            }
            if ($line->wasRecentlyCreated) {
                $this->audit->log('checkout.line_added', $line, '会計明細を追加', $actor);
            }

            return $line;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function addTender(
        Checkout $checkout,
        PaymentMethod $method,
        int $amount,
        array $attributes = [],
        ?Payment $payment = null,
        ?Authenticatable $actor = null,
    ): CheckoutTender {
        return DB::transaction(function () use ($checkout, $method, $amount, $attributes, $payment, $actor): CheckoutTender {
            $locked = $this->lockDraft($checkout);
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => __('messages.checkout.tender_amount_positive')]);
            }
            if ($payment !== null && (int) $payment->customer_id !== (int) $this->customerIdFor($locked)) {
                throw ValidationException::withMessages(['payment_id' => __('messages.checkout.payment_customer_mismatch')]);
            }

            $values = [
                ...$attributes,
                'checkout_id' => $locked->getKey(),
                'payment_method_id' => $method->getKey(),
                'payment_id' => $payment?->getKey(),
                'amount' => $amount,
                'status' => CheckoutTenderStatus::Received,
                'received_at' => $attributes['received_at'] ?? now(),
            ];
            $operationKey = $attributes['operation_key'] ?? null;
            $tender = is_string($operationKey) && $operationKey !== ''
                ? CheckoutTender::query()->firstOrCreate(['operation_key' => $operationKey], $values)
                : CheckoutTender::query()->create($values);
            if ((int) $tender->checkout_id !== (int) $locked->getKey() || (int) $tender->amount !== $amount) {
                throw ValidationException::withMessages(['operation_key' => __('messages.checkout.operation_key_tender_conflict')]);
            }
            if ($tender->wasRecentlyCreated) {
                $this->audit->log('checkout.tender_added', $tender, '支払明細を追加', $actor);
            }

            return $tender;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function allocateStaff(
        CheckoutLine $line,
        Staff $staff,
        int $amount,
        array $attributes = [],
        ?VisitTreatmentStaff $treatmentStaff = null,
        ?Authenticatable $actor = null,
    ): StaffRevenueAllocation {
        return DB::transaction(function () use ($line, $staff, $amount, $attributes, $treatmentStaff, $actor): StaffRevenueAllocation {
            $lockedLine = CheckoutLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            $this->lockDraft($lockedLine->checkout);
            if ($amount < 0) {
                throw ValidationException::withMessages(['allocated_amount' => __('messages.checkout.staff_allocation_non_negative')]);
            }
            if ($treatmentStaff !== null && (int) $treatmentStaff->staff_id !== (int) $staff->getKey()) {
                throw ValidationException::withMessages(['visit_treatment_staff_id' => __('messages.checkout.treatment_staff_mismatch')]);
            }

            $allocation = StaffRevenueAllocation::query()->updateOrCreate(
                ['checkout_line_id' => $lockedLine->getKey(), 'staff_id' => $staff->getKey()],
                [
                    ...$attributes,
                    'visit_treatment_staff_id' => $treatmentStaff?->getKey(),
                    'staff_name_snapshot' => $attributes['staff_name_snapshot'] ?? $staff->display_name,
                    'allocated_amount' => $amount,
                ],
            );
            $this->audit->log('checkout.staff_allocated', $allocation, 'スタッフ売上を配分', $actor);

            return $allocation;
        });
    }

    public function finalize(Checkout $checkout, ?Authenticatable $actor = null): Checkout
    {
        return DB::transaction(function () use ($checkout, $actor): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === CheckoutStatus::Finalized) {
                return $locked;
            }
            if ($locked->status !== CheckoutStatus::Draft) {
                throw ValidationException::withMessages(['checkout' => __('messages.checkout.only_draft_finalizable')]);
            }

            $lines = CheckoutLine::query()->where('checkout_id', $locked->getKey())->lockForUpdate()->get();
            $tenders = CheckoutTender::query()->where('checkout_id', $locked->getKey())->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => __('messages.checkout.lines_required')]);
            }
            foreach ($lines as $line) {
                $this->validateLineAmounts($line->getAttributes());
                if ($line->is_staff_allocatable && (int) $line->allocations()->lockForUpdate()->sum('allocated_amount') !== (int) $line->gross_amount) {
                    throw ValidationException::withMessages(['staff_allocations' => __('messages.checkout.staff_allocation_mismatch')]);
                }
            }

            $subtotal = (int) $lines->sum('net_amount');
            $tax = (int) $lines->sum('tax_amount');
            $total = (int) $lines->sum('gross_amount');
            if ($subtotal !== (int) $locked->subtotal_amount || $tax !== (int) $locked->tax_amount || $total !== (int) $locked->total_amount) {
                throw ValidationException::withMessages(['totals' => __('messages.checkout.header_total_mismatch')]);
            }
            if ((int) $tenders->where('status', CheckoutTenderStatus::Received)->sum('amount') !== $total) {
                throw ValidationException::withMessages(['tenders' => __('messages.checkout.tender_total_mismatch')]);
            }

            $this->assertTenderAllocations($lines, $tenders);

            $locked->forceFill(['status' => CheckoutStatus::Finalized, 'finalized_at' => now()])->save();
            $this->grantPurchasedTickets($locked, $lines, $actor);
            $this->audit->log('checkout.finalized', $locked, '会計を確定', $actor);

            return $locked->refresh();
        });
    }

    public function void(Checkout $checkout, string $reason, ?Authenticatable $actor = null): Checkout
    {
        return DB::transaction(function () use ($checkout, $reason, $actor): Checkout {
            $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === CheckoutStatus::Voided) {
                return $locked;
            }
            if ($locked->status !== CheckoutStatus::Finalized || trim($reason) === '') {
                throw ValidationException::withMessages(['checkout' => __('messages.checkout.finalized_and_reason_required')]);
            }
            $reason = trim($reason);
            $this->revokePurchasedTickets($locked, $reason, $actor);
            $locked->forceFill(['status' => CheckoutStatus::Voided, 'voided_at' => now(), 'void_reason' => $reason])->save();
            $this->audit->log('checkout.voided', $locked, '会計を取消: '.$reason, $actor);

            return $locked->refresh();
        });
    }

    /**
     * 回数券購入明細は確定時に既存の回数券台帳へ付与する（数量分、明細IDで冪等）。
     *
     * @param  Collection<int, CheckoutLine>  $lines
     */
    private function grantPurchasedTickets(Checkout $checkout, $lines, ?Authenticatable $actor): void
    {
        $ticketLines = $lines->filter(fn (CheckoutLine $line): bool => $line->item_type === CheckoutLineItemType::Ticket->value);
        if ($ticketLines->isEmpty()) {
            return;
        }
        $customerId = $this->customerIdFor($checkout);
        $customer = $customerId === null ? null : Customer::query()->find($customerId);
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => __('messages.checkout_entry.customer_required_for_ticket')]);
        }
        foreach ($ticketLines as $line) {
            $product = TicketProduct::query()->find($line->ticket_product_id);
            if ($product === null) {
                throw ValidationException::withMessages(['lines' => __('messages.checkout_entry.ticket_product_missing')]);
            }
            for ($index = 1; $index <= (int) $line->quantity; $index++) {
                $this->tickets->grant(
                    $customer,
                    $product,
                    null,
                    "checkout-line:{$line->getKey()}:{$index}",
                    __('messages.checkout_entry.ticket_grant_reason', ['id' => $checkout->getKey()]),
                    $actor,
                );
            }
        }
    }

    /** この会計で付与した未使用回数券だけを追記型台帳で取り消す。 */
    private function revokePurchasedTickets(Checkout $checkout, string $voidReason, ?Authenticatable $actor): void
    {
        $ticketLines = CheckoutLine::query()
            ->where('checkout_id', $checkout->getKey())
            ->where('item_type', CheckoutLineItemType::Ticket->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'quantity']);
        $grantKeys = [];

        foreach ($ticketLines as $line) {
            for ($index = 1; $index <= (int) $line->quantity; $index++) {
                $grantKeys[] = "grant:checkout-line:{$line->getKey()}:{$index}";
            }
        }

        if ($grantKeys === []) {
            return;
        }

        $walletIds = TicketTransaction::query()
            ->where('type', TicketTransactionType::Grant->value)
            ->whereIn('dedupe_key', $grantKeys)
            ->orderBy('ticket_wallet_id')
            ->lockForUpdate()
            ->pluck('ticket_wallet_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($walletIds->isEmpty()) {
            return;
        }

        $wallets = TicketWallet::query()
            ->whereIn('id', $walletIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $inUse = TicketReservationUsage::query()
            ->whereIn('ticket_wallet_id', $walletIds->all())
            ->whereIn('status', [
                TicketReservationUsageStatus::Held->value,
                TicketReservationUsageStatus::Consumed->value,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->first() !== null;

        if ($inUse) {
            throw ValidationException::withMessages([
                'checkout' => __('messages.checkout_entry.void_ticket_in_use'),
            ]);
        }

        foreach ($wallets as $wallet) {
            $available = $this->tickets->available($wallet);

            if ($available > 0) {
                $this->tickets->revoke(
                    $wallet,
                    $available,
                    "checkout-void:{$checkout->getKey()}:{$wallet->getKey()}",
                    __('messages.checkout.void_ticket_reason', [
                        'id' => $checkout->getKey(),
                        'reason' => $voidReason,
                    ]),
                    $actor,
                );
            }
        }
    }

    /**
     * 配分が1件でもあれば、支払ごと・配分先ごとの合計が一致することを検証する。
     * 配分が無い会計（Task 11-20以前・外部取込）は「配分未記録」のまま確定を許可し、推測で配分しない。
     *
     * @param  Collection<int, CheckoutLine>  $lines
     * @param  Collection<int, CheckoutTender>  $tenders
     */
    private function assertTenderAllocations($lines, $tenders): void
    {
        $received = $tenders->where('status', CheckoutTenderStatus::Received);
        $allocations = CheckoutTenderAllocation::query()->whereIn('checkout_tender_id', $received->pluck('id'))->lockForUpdate()->get();
        if ($allocations->isEmpty()) {
            // 施術等と物販が混在し支払が複数ある会計は、配分を推測できないため明示入力を必須にする。
            $categories = $lines->map(fn (CheckoutLine $line): TenderAllocationCategory => TenderAllocationCategory::forLineType($line->item_type))->unique();
            if ($categories->count() > 1 && $received->count() > 1) {
                throw ValidationException::withMessages(['tender_allocations' => __('messages.checkout_entry.tender_allocation_required')]);
            }

            return;
        }
        foreach ($received as $tender) {
            if ((int) $allocations->where('checkout_tender_id', $tender->id)->sum('amount') !== (int) $tender->amount) {
                throw ValidationException::withMessages(['tender_allocations' => __('messages.checkout_entry.tender_allocation_mismatch')]);
            }
        }
        foreach (TenderAllocationCategory::cases() as $category) {
            $lineTotal = (int) $lines->filter(fn (CheckoutLine $line): bool => TenderAllocationCategory::forLineType($line->item_type) === $category)->sum('gross_amount');
            $allocated = (int) $allocations->filter(fn (CheckoutTenderAllocation $row): bool => $row->allocation_category === $category)->sum('amount');
            if ($lineTotal !== $allocated) {
                throw ValidationException::withMessages(['tender_allocations' => __('messages.checkout_entry.tender_allocation_category_mismatch')]);
            }
        }
    }

    private function lockDraft(Checkout $checkout): Checkout
    {
        $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== CheckoutStatus::Draft) {
            throw ValidationException::withMessages(['checkout' => __('messages.checkout.finalized_locked')]);
        }

        return $locked;
    }

    /** @param array<string, mixed> $attributes */
    private function validateLineAmounts(array $attributes): void
    {
        foreach (['quantity', 'unit_amount', 'net_amount', 'tax_amount', 'gross_amount'] as $key) {
            if (! array_key_exists($key, $attributes) || (int) $attributes[$key] < ($key === 'quantity' ? 1 : 0)) {
                throw ValidationException::withMessages([$key => __('messages.checkout_entry.amount_invalid')]);
            }
        }
        if ((int) $attributes['net_amount'] + (int) $attributes['tax_amount'] !== (int) $attributes['gross_amount']) {
            throw ValidationException::withMessages(['gross_amount' => __('messages.checkout.tax_total_mismatch')]);
        }
    }
}
