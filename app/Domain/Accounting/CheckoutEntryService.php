<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Business\TaxRateService;
use App\Domain\Reservation\ReservationService;
use App\Domain\Visit\VisitCompletionService;
use App\Domain\Visit\VisitFactService;
use App\Enums\Accounting\CheckoutLineItemType;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Accounting\TenderAllocationCategory;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Visit\VisitStatus;
use App\Models\Checkout;
use App\Models\CheckoutLine;
use App\Models\CheckoutTender;
use App\Models\Customer;
use App\Models\MembershipPlan;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxCategory;
use App\Models\TicketProduct;
use App\Models\Visit;
use App\Models\VisitStaffNomination;
use App\Models\VisitTreatment;
use App\Models\VisitTreatmentStaff;
use App\Support\Audit\AuditLogger;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 管理画面「来店・会計」の入力を既存Fact Service（VisitFact / Checkout / VisitCompletion）へ渡す窓口。
 * 下書きは画面の状態で丸ごと置き換え、確定・完了・取消は既存の不変条件・監査をそのまま通す。
 */
final class CheckoutEntryService
{
    public function __construct(
        private readonly VisitFactService $visitFacts,
        private readonly CheckoutService $checkouts,
        private readonly VisitCompletionService $completion,
        private readonly ReservationService $reservations,
        private readonly TaxRateService $taxRates,
        private readonly TaxAmountCalculator $tax,
        private readonly BusinessTime $businessTime,
        private readonly AuditLogger $audit,
    ) {}

    /** 予約から来店下書きを開く。既に来店がある場合はそれを返す。 */
    public function openForReservation(Reservation $reservation, ?Authenticatable $actor): Visit
    {
        return DB::transaction(function () use ($reservation, $actor): Visit {
            $locked = Reservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            $existing = Visit::query()->where('reservation_id', $locked->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['status' => __('messages.visit_completion.invalid_status')]);
            }
            $customer = Customer::query()->whereKey($locked->customer_id)->firstOrFail();
            $locked->loadMissing('staff');

            return $this->visitFacts->createDraft($customer, $locked, [
                // 予約台帳のstarts_atはJSTの壁時計値。
                'business_date' => $locked->starts_at->format('Y-m-d'),
                'primary_staff_id' => $locked->staff_id,
                'primary_staff_name_snapshot' => $locked->staff?->display_name,
            ], $actor);
        });
    }

    public function openWalkIn(Customer $customer, string $businessDate, ?Authenticatable $actor): Visit
    {
        return $this->visitFacts->createDraft($customer, null, ['business_date' => $businessDate], $actor);
    }

    public function openStoreSale(?Customer $customer, string $saleDate, ?Authenticatable $actor): Checkout
    {
        return $this->checkouts->createStoreDraft($customer, $saleDate, [], $actor);
    }

    /**
     * 来店下書きの施術・担当・指名と、会計下書きを保存する。
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVisit(Visit $visit, array $data, ?Authenticatable $actor): Visit
    {
        return DB::transaction(function () use ($visit, $data, $actor): Visit {
            $locked = Visit::query()->whereKey($visit->getKey())->lockForUpdate()->firstOrFail();
            $treatmentStaffIndex = [];
            if ($locked->status === VisitStatus::Draft) {
                $treatmentStaffIndex = $this->replaceTreatments($locked, $data, $actor);
            } elseif (($data['treatments'] ?? null) !== null && $locked->status !== VisitStatus::Completed) {
                throw ValidationException::withMessages(['visit' => __('messages.checkout_entry.visit_locked')]);
            } else {
                $treatmentStaffIndex = $this->existingTreatmentStaffIndex($locked);
            }

            $lines = $data['lines'] ?? [];
            $tenders = $data['tenders'] ?? [];
            $checkout = Checkout::query()->where('visit_id', $locked->getKey())->first();
            if ($checkout === null && ($lines !== [] || $tenders !== [])) {
                $checkout = $this->checkouts->createDraft($locked, [], $actor);
            }
            if ($checkout !== null) {
                $this->replaceCheckout($checkout, $lines, $tenders, $locked->business_date->toDateString(), $treatmentStaffIndex, $actor);
            }
            $this->audit->log('visit.entry_saved', $locked, '来店・会計の下書きを保存', $actor);

            return $locked->refresh();
        });
    }

    /**
     * 来店なし会計の下書きを保存する。
     *
     * @param  array<string, mixed>  $data
     */
    public function saveStoreSale(Checkout $checkout, array $data, ?Authenticatable $actor): Checkout
    {
        return DB::transaction(function () use ($checkout, $data, $actor): Checkout {
            if ($checkout->visit_id !== null) {
                throw ValidationException::withMessages(['checkout' => __('messages.checkout_entry.visit_checkout_elsewhere')]);
            }
            $this->replaceCheckout($checkout, $data['lines'] ?? [], $data['tenders'] ?? [], $checkout->sale_date->toDateString(), [], $actor);

            return $checkout->refresh();
        });
    }

    /** 来店を完了し、下書き会計があれば同じtransactionで確定する。 */
    public function completeVisit(Visit $visit, ?Authenticatable $actor): Visit
    {
        if ($visit->status === VisitStatus::Completed) {
            $checkout = Checkout::query()->where('visit_id', $visit->getKey())->first();
            if ($checkout !== null && $checkout->status === CheckoutStatus::Draft) {
                $this->checkouts->finalize($checkout, $actor);
            }

            return $visit->refresh();
        }
        if ($visit->reservation_id !== null) {
            $reservation = Reservation::query()->findOrFail($visit->reservation_id);
            $this->reservations->markCompleted($reservation, $actor);

            return $visit->refresh();
        }

        return $this->completion->completeWalkIn($visit, $actor)->visit;
    }

    public function finalize(Checkout $checkout, ?Authenticatable $actor): Checkout
    {
        if ($checkout->visit_id !== null) {
            $visit = Visit::query()->findOrFail($checkout->visit_id);
            if ($visit->status !== VisitStatus::Completed) {
                throw ValidationException::withMessages(['visit' => __('messages.checkout_entry.complete_visit_first')]);
            }
        }

        return $this->checkouts->finalize($checkout, $actor);
    }

    public function void(Checkout $checkout, string $reason, ?Authenticatable $actor): Checkout
    {
        return $this->checkouts->void($checkout, $reason, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<int, VisitTreatmentStaff>> 施術index => staff_id => 担当実績
     */
    private function replaceTreatments(Visit $visit, array $data, ?Authenticatable $actor): array
    {
        $checkout = Checkout::query()->where('visit_id', $visit->getKey())->first();
        if ($checkout !== null && $checkout->status !== CheckoutStatus::Draft) {
            throw ValidationException::withMessages(['checkout' => __('messages.checkout_entry.checkout_locked')]);
        }
        if ($checkout !== null) {
            // 施術に紐づく明細・配分を先に外してから施術を置き換える。
            $this->checkouts->clearDraft($checkout);
        }
        foreach (VisitTreatment::query()->where('visit_id', $visit->getKey())->get() as $treatment) {
            VisitTreatmentStaff::query()->where('visit_treatment_id', $treatment->getKey())->get()->each->delete();
            $treatment->delete();
        }

        $primaryStaff = isset($data['primary_staff_id']) ? Staff::query()->findOrFail((int) $data['primary_staff_id']) : null;
        $visit->forceFill([
            'primary_staff_id' => $primaryStaff?->getKey(),
            'primary_staff_name_snapshot' => $primaryStaff?->display_name,
        ])->save();

        $this->replaceNominations($visit, $data['nominated_staff_ids'] ?? null);

        $index = [];
        foreach (array_values($data['treatments'] ?? []) as $position => $row) {
            $service = isset($row['service_id']) ? Service::query()->with('analysisCategory')->findOrFail((int) $row['service_id']) : null;
            $minutes = (int) ($row['actual_minutes'] ?? 0);
            if ($minutes < 1) {
                throw ValidationException::withMessages(["treatments.{$position}.actual_minutes" => __('messages.checkout_entry.minutes_required')]);
            }
            $start = $this->instant($visit, $row['started_at'] ?? null);
            $treatment = $this->visitFacts->addTreatment($visit, $service, [
                'actual_minutes' => $minutes,
                'actual_started_at' => $start,
                'actual_ended_at' => $start?->addMinutes($minutes),
                'sort_order' => $position,
            ], $actor);

            $staffRows = array_values($row['staff'] ?? []);
            $staffTotal = array_sum(array_map(static fn (array $staff): int => (int) ($staff['actual_minutes'] ?? 0), $staffRows));
            if ($staffRows !== [] && $staffTotal !== $minutes) {
                throw ValidationException::withMessages(["treatments.{$position}.staff" => __('messages.checkout_entry.staff_minutes_mismatch')]);
            }
            $cursor = $start;
            $index[$position] = [];
            foreach ($staffRows as $staffPosition => $staffRow) {
                $staff = Staff::query()->findOrFail((int) $staffRow['staff_id']);
                if (isset($index[$position][(int) $staff->getKey()])) {
                    throw ValidationException::withMessages(["treatments.{$position}.staff" => __('messages.checkout_entry.staff_duplicated')]);
                }
                $staffMinutes = (int) $staffRow['actual_minutes'];
                $staffStart = array_key_exists('started_at', $staffRow) && $staffRow['started_at'] !== null
                    ? $this->instant($visit, $staffRow['started_at'])
                    : $cursor;
                $index[$position][(int) $staff->getKey()] = $this->visitFacts->assignStaff($treatment, $staff, [
                    'actual_minutes' => $staffMinutes,
                    'actual_started_at' => $staffStart,
                    'actual_ended_at' => $staffStart?->addMinutes($staffMinutes),
                    'sort_order' => $staffPosition,
                ], $actor);
                $cursor = $staffStart?->addMinutes($staffMinutes);
            }
        }

        return $index;
    }

    /** @param list<int|string>|null $staffIds */
    private function replaceNominations(Visit $visit, ?array $staffIds): void
    {
        VisitStaffNomination::query()->where('visit_id', $visit->getKey())->get()->each->delete();
        if ($staffIds === null) {
            // 指名欄を送らない保存は「未記録」のまま。予約の明示指名を完了時に使う。
            $visit->forceFill(['nominations_recorded_at' => null])->save();

            return;
        }
        foreach (array_unique(array_map('intval', $staffIds)) as $staffId) {
            $staff = Staff::query()->findOrFail($staffId);
            VisitStaffNomination::query()->create([
                'visit_id' => $visit->getKey(),
                'staff_id' => $staff->getKey(),
                'staff_name_snapshot' => $staff->display_name,
            ]);
        }
        $visit->forceFill(['nominations_recorded_at' => now()])->save();
    }

    /** @return array<int, array<int, VisitTreatmentStaff>> */
    private function existingTreatmentStaffIndex(Visit $visit): array
    {
        $index = [];
        foreach (VisitTreatment::query()->where('visit_id', $visit->getKey())->orderBy('sort_order')->orderBy('id')->get()->values() as $position => $treatment) {
            $index[$position] = VisitTreatmentStaff::query()->where('visit_treatment_id', $treatment->getKey())
                ->get()->keyBy(fn (VisitTreatmentStaff $row): int => (int) $row->staff_id)->all();
        }

        return $index;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $tenders
     * @param  array<int, array<int, VisitTreatmentStaff>>  $treatmentStaffIndex
     */
    private function replaceCheckout(Checkout $checkout, array $lines, array $tenders, string $date, array $treatmentStaffIndex, ?Authenticatable $actor): void
    {
        $this->checkouts->clearDraft($checkout);
        $treatmentIds = $checkout->visit_id === null ? [] : VisitTreatment::query()->where('visit_id', $checkout->visit_id)
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        foreach (array_values($lines) as $position => $row) {
            $line = $this->addLine($checkout, $row, $position, $date, $treatmentIds, $actor);
            $allocations = array_values($row['allocations'] ?? []);
            if (! $line->is_staff_allocatable) {
                continue;
            }
            $treatmentPosition = isset($row['treatment_index']) ? (int) $row['treatment_index'] : null;
            foreach ($allocations as $allocation) {
                $staff = Staff::query()->findOrFail((int) $allocation['staff_id']);
                $treatmentStaff = $treatmentPosition === null ? null : ($treatmentStaffIndex[$treatmentPosition][(int) $staff->getKey()] ?? null);
                $this->checkouts->allocateStaff($line, $staff, (int) $allocation['amount'], [
                    'basis_minutes' => $treatmentStaff?->actual_minutes,
                ], $treatmentStaff, $actor);
            }
        }

        $created = [];
        foreach (array_values($tenders) as $row) {
            $method = PaymentMethod::query()->where('is_enabled', true)->findOrFail((int) $row['payment_method_id']);
            $created[] = [$this->checkouts->addTender($checkout, $method, (int) $row['amount'], [
                'received_at' => $this->receivedAt($date),
            ], null, $actor), $row];
        }
        $this->allocateTenders($checkout, $created, $actor);
        $this->checkouts->syncTotals($checkout);
    }

    /**
     * 支払の施術等／物販への配分（Task 11-20）。画面で入力された物販分を保存する。
     * 入力が無い場合は、答えが一意に決まる場合（片方の区分しか無い・支払が1件）だけ機械的に埋める。
     * それ以外は推測せず未配分のまま保存し、確定時に明示入力を求める。
     *
     * @param  list<array{0: CheckoutTender, 1: array<string, mixed>}>  $created
     */
    private function allocateTenders(Checkout $checkout, array $created, ?Authenticatable $actor): void
    {
        if ($created === []) {
            return;
        }
        $lines = CheckoutLine::query()->where('checkout_id', $checkout->getKey())->get();
        $retailTotal = (int) $lines->filter(fn (CheckoutLine $line): bool => TenderAllocationCategory::forLineType($line->item_type) === TenderAllocationCategory::Retail)->sum('gross_amount');
        $treatmentTotal = (int) $lines->sum('gross_amount') - $retailTotal;
        $explicit = collect($created)->every(fn (array $pair): bool => array_key_exists('retail_amount', $pair[1]) && $pair[1]['retail_amount'] !== null);

        foreach ($created as [$tender, $row]) {
            $amount = (int) $tender->amount;
            $retail = match (true) {
                $explicit => (int) $row['retail_amount'],
                $lines->isEmpty() => null,
                $retailTotal === 0 => 0,
                $treatmentTotal === 0 => $amount,
                count($created) === 1 && $amount === $retailTotal + $treatmentTotal => $retailTotal,
                default => null,
            };
            if ($retail === null) {
                continue;
            }
            if ($retail < 0 || $retail > $amount) {
                throw ValidationException::withMessages(['tenders' => __('messages.checkout_entry.tender_allocation_invalid')]);
            }
            $this->checkouts->allocateTender($tender, [
                TenderAllocationCategory::Treatment->value => $amount - $retail,
                TenderAllocationCategory::Retail->value => $retail,
            ], $actor);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int|null>  $treatmentIds
     */
    private function addLine(Checkout $checkout, array $row, int $position, string $date, array $treatmentIds, ?Authenticatable $actor): CheckoutLine
    {
        $type = CheckoutLineItemType::tryFrom((string) ($row['item_type'] ?? ''));
        if ($type === null) {
            throw ValidationException::withMessages(["lines.{$position}.item_type" => __('messages.checkout_entry.item_type_invalid')]);
        }
        $quantity = (int) ($row['quantity'] ?? 1);
        $unitAmount = (int) ($row['unit_amount'] ?? -1);
        if ($quantity < 1 || $unitAmount < 0) {
            throw ValidationException::withMessages(["lines.{$position}.unit_amount" => __('messages.checkout_entry.amount_invalid')]);
        }

        [$refs, $name, $defaultTaxCategoryId] = match ($type) {
            CheckoutLineItemType::Service => $this->serviceRef($row),
            CheckoutLineItemType::Product => $this->modelRef(Product::class, 'product_id', $row),
            CheckoutLineItemType::Ticket => $this->modelRef(TicketProduct::class, 'ticket_product_id', $row),
            CheckoutLineItemType::Membership => $this->modelRef(MembershipPlan::class, 'membership_plan_id', $row),
            CheckoutLineItemType::Other => [[], trim((string) ($row['item_name'] ?? '')), null],
        };
        if ($name === '') {
            throw ValidationException::withMessages(["lines.{$position}.item_name" => __('messages.checkout_entry.item_name_required')]);
        }
        if ($type === CheckoutLineItemType::Ticket || $type === CheckoutLineItemType::Membership) {
            if ($this->checkouts->customerIdFor($checkout) === null) {
                throw ValidationException::withMessages(["lines.{$position}.item_type" => __('messages.checkout_entry.customer_required_for_ticket')]);
            }
        }

        $taxCategoryId = isset($row['tax_category_id']) ? (int) $row['tax_category_id'] : $defaultTaxCategoryId;
        $taxCategory = $taxCategoryId === null ? null : TaxCategory::query()->find($taxCategoryId);
        if ($taxCategory === null) {
            throw ValidationException::withMessages(["lines.{$position}.tax_category_id" => __('messages.checkout_entry.tax_category_required')]);
        }
        $rate = $this->taxRates->forDate($taxCategory, $date);
        $amounts = $this->tax->splitInclusive($unitAmount * $quantity, $rate?->rate_bps);

        $treatmentId = null;
        if ($type === CheckoutLineItemType::Service && isset($row['treatment_index'])) {
            $treatmentId = $treatmentIds[(int) $row['treatment_index']] ?? null;
            if ($treatmentId === null) {
                throw ValidationException::withMessages(["lines.{$position}.treatment_index" => __('messages.checkout_entry.treatment_missing')]);
            }
        }

        return $this->checkouts->addLine($checkout, [
            ...$refs,
            'item_type' => $type->value,
            'visit_treatment_id' => $treatmentId,
            'item_name_snapshot' => mb_substr($name, 0, 100),
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'tax_rate_bps' => $rate?->rate_bps,
            ...$amounts,
            'is_staff_allocatable' => (bool) ($row['is_staff_allocatable'] ?? false),
            'sort_order' => $position,
        ], $taxCategory, $actor);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, int>, 1: string, 2: ?int}
     */
    private function serviceRef(array $row): array
    {
        $service = Service::query()->findOrFail((int) ($row['service_id'] ?? 0));

        return [['service_id' => (int) $service->getKey()], (string) ($row['item_name'] ?? $service->name), $service->tax_category_id === null ? null : (int) $service->tax_category_id];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $row
     * @return array{0: array<string, int>, 1: string, 2: ?int}
     */
    private function modelRef(string $model, string $key, array $row): array
    {
        $record = $model::query()->findOrFail((int) ($row[$key] ?? 0));
        $taxCategoryId = $record->getAttribute('tax_category_id');

        return [[$key => (int) $record->getKey()], (string) ($row['item_name'] ?? $record->getAttribute('name')), $taxCategoryId === null ? null : (int) $taxCategoryId];
    }

    /**
     * 決済日基準の売上日は受領日時のJST日付。後日まとめて入力しても営業日の売上になるよう、
     * 当日以外は営業日の正午（JST）を受領日時にする。
     */
    private function receivedAt(string $date): CarbonImmutable
    {
        if ($date === $this->businessTime->businessDate()->toDateString()) {
            return CarbonImmutable::now();
        }

        return CarbonImmutable::parse($date.' 12:00', $this->businessTime->timezone())->utc();
    }

    /** 画面のJST時刻（HH:MM）を来店営業日のUTC instantにする。 */
    private function instant(Visit $visit, mixed $time): ?CarbonImmutable
    {
        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($visit->business_date->toDateString().' '.$time, $this->businessTime->timezone())->utc();
    }
}
