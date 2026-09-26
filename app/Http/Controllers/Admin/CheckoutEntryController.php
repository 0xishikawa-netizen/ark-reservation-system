<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\CheckoutEntryService;
use App\Domain\Business\TaxRateService;
use App\Enums\Accounting\CheckoutStatus;
use App\Enums\Visit\VisitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCheckoutEntryRequest;
use App\Models\Checkout;
use App\Models\CheckoutLine;
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
use App\Models\VisitTreatment;
use App\Support\Business\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** 来店・会計入力（Task 11-19）。業務ルールはCheckoutEntryService、ここは入出力の整形だけを行う。 */
final class CheckoutEntryController extends Controller
{
    public function index(Request $request, BusinessTime $businessTime): Response
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $input['date'] ?? $businessTime->businessDate()->toDateString();

        $visits = Visit::query()
            ->with(['customer.user:id,name', 'checkout'])
            ->where('business_date', $date)
            ->orderBy('id')
            ->get()
            ->map(fn (Visit $visit): array => [
                'kind' => 'visit',
                'id' => $visit->id,
                'customer_name' => $visit->customer?->user?->name,
                'primary_staff_name' => $visit->primary_staff_name_snapshot,
                'visit_status' => $visit->status->value,
                'has_reservation' => $visit->reservation_id !== null,
                'checkout_status' => $visit->checkout?->status->value,
                'total_amount' => $visit->checkout?->total_amount,
                'url' => route('admin.visits.checkout.show', $visit),
            ]);
        $sales = Checkout::query()
            ->with('customer.user:id,name')
            ->whereNull('visit_id')
            ->where('sale_date', $date)
            ->orderBy('id')
            ->get()
            ->map(fn (Checkout $checkout): array => [
                'kind' => 'sale',
                'id' => $checkout->id,
                'customer_name' => $checkout->customer?->user?->name,
                'primary_staff_name' => null,
                'visit_status' => null,
                'has_reservation' => false,
                'checkout_status' => $checkout->status->value,
                'total_amount' => $checkout->total_amount,
                'url' => route('admin.checkouts.show', $checkout),
            ]);

        return Inertia::render('Admin/Checkouts/Index', [
            'date' => $date,
            'rows' => $visits->concat($sales)->values(),
            'customerSearchEndpoint' => route('admin.reservations.customer-search'),
        ]);
    }

    public function openReservation(Request $request, Reservation $reservation, CheckoutEntryService $entries): RedirectResponse
    {
        $visit = $entries->openForReservation($reservation, $request->user());

        return redirect()->route('admin.visits.checkout.show', $visit);
    }

    public function storeWalkIn(Request $request, CheckoutEntryService $entries): RedirectResponse
    {
        $input = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,user_id'],
            'business_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $visit = $entries->openWalkIn(Customer::query()->findOrFail($input['customer_id']), $input['business_date'], $request->user());

        return redirect()->route('admin.visits.checkout.show', $visit)->with('success', __('messages.checkout_entry.opened'));
    }

    public function storeSale(Request $request, CheckoutEntryService $entries): RedirectResponse
    {
        $input = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,user_id'],
            'sale_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $customer = isset($input['customer_id']) ? Customer::query()->findOrFail($input['customer_id']) : null;
        $checkout = $entries->openStoreSale($customer, $input['sale_date'], $request->user());

        return redirect()->route('admin.checkouts.show', $checkout)->with('success', __('messages.checkout_entry.opened'));
    }

    public function showVisit(Visit $visit, TaxRateService $taxRates): Response
    {
        $visit->load(['customer.user:id,name', 'reservation', 'nominations', 'treatments.staffAssignments']);
        $checkout = Checkout::query()->with(['lines.allocations', 'tenders'])->where('visit_id', $visit->id)->first();

        return Inertia::render('Admin/Checkouts/Entry', [
            ...$this->masters($visit->business_date->toDateString(), $taxRates),
            'mode' => 'visit',
            'visit' => $this->visitPayload($visit),
            'checkout' => $checkout === null ? null : $this->checkoutPayload($checkout),
            'customer' => $this->customerPayload($visit->customer),
            'date' => $visit->business_date->toDateString(),
            'endpoints' => [
                'save' => route('admin.visits.checkout.update', $visit),
                'complete' => route('admin.visits.complete', $visit),
                'finalize' => $checkout === null ? null : route('admin.checkouts.finalize', $checkout),
                'void' => $checkout === null ? null : route('admin.checkouts.void', $checkout),
                'index' => route('admin.checkouts.index', ['date' => $visit->business_date->toDateString()]),
            ],
        ]);
    }

    public function updateVisit(SaveCheckoutEntryRequest $request, Visit $visit, CheckoutEntryService $entries): RedirectResponse
    {
        $entries->saveVisit($visit, $request->validated(), $request->user());

        return back()->with('success', __('messages.checkout_entry.saved'));
    }

    public function completeVisit(Request $request, Visit $visit, CheckoutEntryService $entries): RedirectResponse
    {
        $entries->completeVisit($visit, $request->user());

        return back()->with('success', __('messages.checkout_entry.completed'));
    }

    public function showCheckout(Checkout $checkout, TaxRateService $taxRates): Response|RedirectResponse
    {
        if ($checkout->visit_id !== null) {
            return redirect()->route('admin.visits.checkout.show', $checkout->visit_id);
        }
        $checkout->load(['customer.user:id,name', 'lines.allocations', 'tenders']);

        return Inertia::render('Admin/Checkouts/Entry', [
            ...$this->masters($checkout->sale_date->toDateString(), $taxRates),
            'mode' => 'sale',
            'visit' => null,
            'checkout' => $this->checkoutPayload($checkout),
            'customer' => $this->customerPayload($checkout->customer),
            'date' => $checkout->sale_date->toDateString(),
            'endpoints' => [
                'save' => route('admin.checkouts.update', $checkout),
                'complete' => null,
                'finalize' => route('admin.checkouts.finalize', $checkout),
                'void' => route('admin.checkouts.void', $checkout),
                'index' => route('admin.checkouts.index', ['date' => $checkout->sale_date->toDateString()]),
            ],
        ]);
    }

    public function updateCheckout(SaveCheckoutEntryRequest $request, Checkout $checkout, CheckoutEntryService $entries): RedirectResponse
    {
        $entries->saveStoreSale($checkout, $request->validated(), $request->user());

        return back()->with('success', __('messages.checkout_entry.saved'));
    }

    public function finalize(Request $request, Checkout $checkout, CheckoutEntryService $entries): RedirectResponse
    {
        $entries->finalize($checkout, $request->user());

        return back()->with('success', __('messages.checkout_entry.finalized'));
    }

    public function void(Request $request, Checkout $checkout, CheckoutEntryService $entries): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $entries->void($checkout, $input['reason'], $request->user());

        return back()->with('success', __('messages.checkout_entry.voided'));
    }

    /** @return array<string, mixed> */
    private function masters(string $date, TaxRateService $taxRates): array
    {
        $taxCategories = TaxCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (TaxCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'rate_bps' => $taxRates->forDate($category, $date)?->rate_bps,
            ])->values();

        return [
            'services' => Service::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'price', 'duration_min', 'tax_category_id', 'requires_staff'])->values(),
            'products' => Product::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'price', 'tax_category_id'])->values(),
            'ticketProducts' => TicketProduct::query()->where('is_active', true)->orderBy('id')
                ->get(['id', 'name', 'price', 'tax_category_id'])->values(),
            'membershipPlans' => MembershipPlan::query()->where('is_active', true)->orderBy('id')
                ->get(['id', 'name', 'price', 'tax_category_id'])->values(),
            'staff' => Staff::query()->orderBy('sort_order')->orderBy('user_id')
                ->get(['user_id', 'display_name'])->map(fn (Staff $staff): array => ['id' => $staff->user_id, 'name' => $staff->display_name])->values(),
            'taxCategories' => $taxCategories,
            'paymentMethods' => PaymentMethod::query()->where('is_enabled', true)->orderBy('display_order')->orderBy('id')
                ->get(['id', 'code', 'name'])->values(),
            'customerSearchEndpoint' => route('admin.reservations.customer-search'),
            'canVoid' => request()->user()?->can('checkouts.void') ?? false,
        ];
    }

    /** @return array<string, mixed> */
    private function visitPayload(Visit $visit): array
    {
        $timezone = app(BusinessTime::class)->timezone();
        $time = static fn ($instant): ?string => $instant === null ? null : CarbonImmutable::parse($instant)->setTimezone($timezone)->format('H:i');

        return [
            'id' => $visit->id,
            'status' => $visit->status->value,
            'reservation_id' => $visit->reservation_id,
            'reservation_starts_at' => $visit->reservation?->starts_at?->format('H:i'),
            'business_date' => $visit->business_date->toDateString(),
            'primary_staff_id' => $visit->primary_staff_id,
            'nominations_recorded' => $visit->nominations_recorded_at !== null,
            'nominated_staff_ids' => $visit->nominations->pluck('staff_id')->filter()->values(),
            'reservation_staff_requested' => $visit->reservation?->is_staff_requested,
            'treatments' => $visit->treatments->map(fn (VisitTreatment $treatment): array => [
                'id' => $treatment->id,
                'service_id' => $treatment->service_id,
                'service_name' => $treatment->service_name_snapshot,
                'category' => $treatment->analysis_category_code_snapshot,
                'actual_minutes' => $treatment->actual_minutes,
                'started_at' => $time($treatment->actual_started_at),
                'status' => $treatment->status->value,
                'staff' => $treatment->staffAssignments->map(fn ($row): array => [
                    'staff_id' => $row->staff_id,
                    'actual_minutes' => $row->actual_minutes,
                    'started_at' => $time($row->actual_started_at),
                ])->values(),
            ])->values(),
            'editable' => $visit->status === VisitStatus::Draft,
        ];
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(Checkout $checkout): array
    {
        $treatmentPositions = $checkout->visit_id === null ? [] : VisitTreatment::query()->where('visit_id', $checkout->visit_id)
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->flip()->all();

        return [
            'id' => $checkout->id,
            'status' => $checkout->status->value,
            'subtotal_amount' => $checkout->subtotal_amount,
            'tax_amount' => $checkout->tax_amount,
            'total_amount' => $checkout->total_amount,
            'void_reason' => $checkout->void_reason,
            'lines' => $checkout->lines->map(fn (CheckoutLine $line): array => [
                'item_type' => $line->item_type,
                'service_id' => $line->service_id,
                'product_id' => $line->product_id,
                'ticket_product_id' => $line->ticket_product_id,
                'membership_plan_id' => $line->membership_plan_id,
                'item_name' => $line->item_name_snapshot,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'tax_category_id' => $line->tax_category_id,
                'tax_rate_bps' => $line->tax_rate_bps,
                'net_amount' => $line->net_amount,
                'tax_amount' => $line->tax_amount,
                'gross_amount' => $line->gross_amount,
                'treatment_index' => $line->visit_treatment_id === null ? null : ($treatmentPositions[$line->visit_treatment_id] ?? null),
                'is_staff_allocatable' => $line->is_staff_allocatable,
                'allocations' => $line->allocations->map(fn ($allocation): array => [
                    'staff_id' => $allocation->staff_id,
                    'amount' => $allocation->allocated_amount,
                ])->values(),
            ])->values(),
            'tenders' => $checkout->tenders->map(fn ($tender): array => [
                'payment_method_id' => $tender->payment_method_id,
                'amount' => $tender->amount,
                'status' => $tender->status->value,
                'external' => $tender->payment_id !== null,
            ])->values(),
            'editable' => $checkout->status === CheckoutStatus::Draft,
        ];
    }

    /** @return array<string, mixed>|null */
    private function customerPayload(?Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customer->user_id,
            'name' => $customer->user?->name,
            'member_no' => $customer->member_no,
            'url' => route('admin.customers.show', $customer->user_id),
        ];
    }
}
