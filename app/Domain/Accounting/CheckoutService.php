<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\CheckoutTenderStatus;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\StaffRevenueAllocation;
use App\Models\TaxCategory;
use App\Models\Visit;
use App\Models\VisitTreatmentStaff;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CheckoutService
{
    public function __construct(private readonly AuditLogger $audit) {}

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
                throw ValidationException::withMessages(['operation_key' => '操作キーが別内容の会計明細で使用されています。']);
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
                throw ValidationException::withMessages(['amount' => '支払金額は1円以上で指定してください。']);
            }
            if ($payment !== null && (int) $payment->customer_id !== (int) $locked->visit()->value('customer_id')) {
                throw ValidationException::withMessages(['payment_id' => '外部決済と会計の顧客が一致しません。']);
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
                throw ValidationException::withMessages(['operation_key' => '操作キーが別内容の支払明細で使用されています。']);
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
                throw ValidationException::withMessages(['allocated_amount' => '売上配分額は0円以上で指定してください。']);
            }
            if ($treatmentStaff !== null && (int) $treatmentStaff->staff_id !== (int) $staff->getKey()) {
                throw ValidationException::withMessages(['visit_treatment_staff_id' => '担当実績とスタッフが一致しません。']);
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
                throw ValidationException::withMessages(['checkout' => '下書き会計だけを確定できます。']);
            }

            $lines = CheckoutLine::query()->where('checkout_id', $locked->getKey())->lockForUpdate()->get();
            $tenders = CheckoutTender::query()->where('checkout_id', $locked->getKey())->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => '会計明細がありません。']);
            }
            foreach ($lines as $line) {
                $this->validateLineAmounts($line->getAttributes());
                if ($line->is_staff_allocatable && (int) $line->allocations()->lockForUpdate()->sum('allocated_amount') !== (int) $line->gross_amount) {
                    throw ValidationException::withMessages(['staff_allocations' => 'スタッフ売上配分の合計が対象明細の税込金額と一致しません。']);
                }
            }

            $subtotal = (int) $lines->sum('net_amount');
            $tax = (int) $lines->sum('tax_amount');
            $total = (int) $lines->sum('gross_amount');
            if ($subtotal !== (int) $locked->subtotal_amount || $tax !== (int) $locked->tax_amount || $total !== (int) $locked->total_amount) {
                throw ValidationException::withMessages(['totals' => '会計ヘッダーと明細の合計が一致しません。']);
            }
            if ((int) $tenders->where('status', CheckoutTenderStatus::Received)->sum('amount') !== $total) {
                throw ValidationException::withMessages(['tenders' => '有効な支払明細の合計が会計総額と一致しません。']);
            }

            $locked->forceFill(['status' => CheckoutStatus::Finalized, 'finalized_at' => now()])->save();
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
                throw ValidationException::withMessages(['checkout' => '確定済み会計と取消理由が必要です。']);
            }
            $locked->forceFill(['status' => CheckoutStatus::Voided, 'voided_at' => now(), 'void_reason' => trim($reason)])->save();
            $this->audit->log('checkout.voided', $locked, '会計を取消: '.trim($reason), $actor);

            return $locked->refresh();
        });
    }

    private function lockDraft(Checkout $checkout): Checkout
    {
        $locked = Checkout::query()->whereKey($checkout->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== CheckoutStatus::Draft) {
            throw ValidationException::withMessages(['checkout' => '確定済み会計は変更できません。']);
        }

        return $locked;
    }

    /** @param array<string, mixed> $attributes */
    private function validateLineAmounts(array $attributes): void
    {
        foreach (['quantity', 'unit_amount', 'net_amount', 'tax_amount', 'gross_amount'] as $key) {
            if (! array_key_exists($key, $attributes) || (int) $attributes[$key] < ($key === 'quantity' ? 1 : 0)) {
                throw ValidationException::withMessages([$key => '数量・金額が不正です。']);
            }
        }
        if ((int) $attributes['net_amount'] + (int) $attributes['tax_amount'] !== (int) $attributes['gross_amount']) {
            throw ValidationException::withMessages(['gross_amount' => '税抜額と税額の合計が税込額と一致しません。']);
        }
    }
}
