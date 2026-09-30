<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Business\BusinessMasterService;
use App\Domain\Business\SalesTargetService;
use App\Domain\Business\StoreCalendarService;
use App\Domain\Business\TaxRateService;
use App\Http\Controllers\Controller;
use App\Models\AcquisitionChannel;
use App\Models\EmploymentType;
use App\Models\MonthlySalesTarget;
use App\Models\PaymentMethod;
use App\Models\Qualification;
use App\Models\ServiceAnalysisCategory;
use App\Models\StoreCalendarDay;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\VisitPurpose;
use App\Support\Business\BusinessTime;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class BusinessMasterSettingsController extends Controller
{
    public function show(
        SalesTargetService $salesTargets,
        Settings $settings,
        BusinessTime $businessTime,
        StoreCalendarService $calendar,
    ): Response {
        return Inertia::render('Admin/Settings/BusinessMasters', [
            'analysisCategories' => ServiceAnalysisCategory::query()
                ->orderBy('sort_order')->orderBy('id')->get(),
            'taxCategories' => TaxCategory::query()
                ->with(['rates' => fn ($query) => $query->orderBy('effective_from')])
                ->orderBy('sort_order')->orderBy('id')->get(),
            'paymentMethods' => PaymentMethod::query()
                ->orderBy('display_order')->orderBy('id')->get(),
            'calendarDays' => StoreCalendarDay::query()
                ->orderByDesc('business_date')->limit(200)->get()
                ->map(fn (StoreCalendarDay $day): array => [
                    ...$day->only(['id', 'status', 'opens_at', 'closes_at', 'note']),
                    'business_date' => $day->business_date->toDateString(),
                    'opens_at' => $day->opens_at === null ? null : substr((string) $day->opens_at, 0, 5),
                    'closes_at' => $day->closes_at === null ? null : substr((string) $day->closes_at, 0, 5),
                ]),
            'salesTargets' => [
                'default_amount' => $salesTargets->defaultAmount() ?? 0,
                'monthly' => MonthlySalesTarget::query()
                    ->orderByDesc('target_month')->get()
                    ->map(fn (MonthlySalesTarget $target): array => [
                        'id' => (int) $target->id,
                        'target_month' => $target->target_month->format('Y-m'),
                        'target_amount' => (int) $target->target_amount,
                    ]),
            ],
            'acquisitionChannels' => AcquisitionChannel::query()->orderBy('sort_order')->orderBy('id')->get(),
            'visitPurposes' => VisitPurpose::query()->orderBy('sort_order')->orderBy('id')->get(),
            'qualifications' => Qualification::query()->orderBy('sort_order')->orderBy('id')->get(),
            'employmentTypes' => EmploymentType::query()
                ->orderBy('sort_order')->orderBy('id')->get(),
            'closedWeekdays' => $calendar->closedWeekdays(),
            'business' => [
                'timezone' => $businessTime->timezone(),
                'default_opens_at' => (string) ($settings->get('business_hours.open', config('reservation.business_hours.open', '10:00')) ?? config('reservation.business_hours.open', '10:00')),
                'default_closes_at' => (string) ($settings->get('business_hours.close', config('reservation.business_hours.close', '22:00')) ?? config('reservation.business_hours.close', '22:00')),
            ],
        ]);
    }

    public function storeAnalysisCategory(Request $request, BusinessMasterService $service): RedirectResponse
    {
        $service->createAnalysisCategory($this->masterData($request, 'service_analysis_categories'), $request->user());

        return back()->with('success', __('messages.business.analysis_category_saved'));
    }

    public function updateAnalysisCategory(
        Request $request,
        ServiceAnalysisCategory $analysisCategory,
        BusinessMasterService $service,
    ): RedirectResponse {
        $service->updateAnalysisCategory(
            $analysisCategory,
            $this->masterData($request, 'service_analysis_categories', (int) $analysisCategory->id),
            $request->user(),
        );

        return back()->with('success', __('messages.business.analysis_category_saved'));
    }

    public function storeKarteMaster(Request $request, string $kind, BusinessMasterService $service): RedirectResponse
    {
        [$model, $table] = $this->karteMaster($kind);
        $service->createKarteMaster($model, $this->masterData($request, $table), $request->user());

        return back()->with('success', __('messages.business.karte_master_saved'));
    }

    public function updateKarteMaster(Request $request, string $kind, int $id, BusinessMasterService $service): RedirectResponse
    {
        [$model, $table] = $this->karteMaster($kind);
        $record = $model::query()->findOrFail($id);
        $service->updateKarteMaster($record, $this->masterData($request, $table, $id), $request->user());

        return back()->with('success', __('messages.business.karte_master_saved'));
    }

    /** @return array{0: class-string<Model>, 1: string} */
    private function karteMaster(string $kind): array
    {
        return match ($kind) {
            'acquisition-channels' => [AcquisitionChannel::class, 'acquisition_channels'],
            'visit-purposes' => [VisitPurpose::class, 'visit_purposes'],
            // Task 11-28: 資格マスタ（はり師など）。同じ監査付きの作成・更新・無効化を使う。
            'qualifications' => [Qualification::class, 'qualifications'],
            default => abort(404),
        };
    }

    public function storeTaxCategory(Request $request, BusinessMasterService $service): RedirectResponse
    {
        $service->createTaxCategory($this->masterData($request, 'tax_categories'), $request->user());

        return back()->with('success', __('messages.business.tax_category_saved'));
    }

    public function updateTaxCategory(Request $request, TaxCategory $taxCategory, BusinessMasterService $service): RedirectResponse
    {
        $service->updateTaxCategory(
            $taxCategory,
            $this->masterData($request, 'tax_categories', (int) $taxCategory->id),
            $request->user(),
        );

        return back()->with('success', __('messages.business.tax_category_saved'));
    }

    public function storeTaxRate(Request $request, TaxRateService $service): RedirectResponse
    {
        $service->save($this->taxRateData($request), null, $request->user());

        return back()->with('success', __('messages.business.tax_rate_saved'));
    }

    public function updateTaxRate(Request $request, TaxRate $taxRate, TaxRateService $service): RedirectResponse
    {
        $service->save($this->taxRateData($request), $taxRate, $request->user());

        return back()->with('success', __('messages.business.tax_rate_saved'));
    }

    public function storePaymentMethod(Request $request, BusinessMasterService $service): RedirectResponse
    {
        $service->createPaymentMethod($this->paymentMethodData($request), $request->user());

        return back()->with('success', __('messages.business.payment_method_saved'));
    }

    public function updatePaymentMethod(Request $request, PaymentMethod $paymentMethod, BusinessMasterService $service): RedirectResponse
    {
        $service->updatePaymentMethod($paymentMethod, $this->paymentMethodData($request, (int) $paymentMethod->id), $request->user());

        return back()->with('success', __('messages.business.payment_method_saved'));
    }

    public function saveCalendarDay(Request $request, StoreCalendarService $service): RedirectResponse
    {
        $validated = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in([
                StoreCalendarDay::STATUS_CLOSED,
                StoreCalendarDay::STATUS_SPECIAL_HOURS,
                StoreCalendarDay::STATUS_OPEN,
            ])],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $service->save($validated, $request->user());

        return back()->with('success', __('messages.business.calendar_saved'));
    }

    /** 毎週の定休日（ISO 曜日 1=月〜7=日）。 */
    public function saveClosedWeekdays(Request $request, StoreCalendarService $service): RedirectResponse
    {
        $validated = $request->validate([
            'weekdays' => ['present', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
        ]);
        $service->setClosedWeekdays(array_map('intval', $validated['weekdays']), $request->user());

        return back()->with('success', __('messages.business.closed_weekdays_saved'));
    }

    public function clearCalendarDay(Request $request, StoreCalendarDay $calendarDay, StoreCalendarService $service): RedirectResponse
    {
        $service->clear($calendarDay, $request->user());

        return back()->with('success', __('messages.business.calendar_cleared'));
    }

    public function updateDefaultSalesTarget(Request $request, SalesTargetService $service): RedirectResponse
    {
        $validated = $request->validate(['target_amount' => ['required', 'integer', 'min:0', 'max:999999999999']]);
        $service->setDefaultAmount((int) $validated['target_amount'], $request->user());

        return back()->with('success', __('messages.business.sales_target_saved'));
    }

    public function updateMonthlySalesTarget(Request $request, SalesTargetService $service): RedirectResponse
    {
        $validated = $request->validate([
            'target_month' => ['required', 'date_format:Y-m'],
            'target_amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);
        $service->setMonthly((string) $validated['target_month'], (int) $validated['target_amount'], $request->user());

        return back()->with('success', __('messages.business.sales_target_saved'));
    }

    public function clearMonthlySalesTarget(Request $request, MonthlySalesTarget $salesTarget, SalesTargetService $service): RedirectResponse
    {
        $service->clearMonthly($salesTarget, $request->user());

        return back()->with('success', __('messages.business.sales_target_cleared'));
    }

    public function storeEmploymentType(Request $request, BusinessMasterService $service): RedirectResponse
    {
        $service->createEmploymentType($this->masterData($request, 'employment_types'), $request->user());

        return back()->with('success', __('messages.business.employment_type_saved'));
    }

    public function updateEmploymentType(Request $request, EmploymentType $employmentType, BusinessMasterService $service): RedirectResponse
    {
        $service->updateEmploymentType(
            $employmentType,
            $this->masterData($request, 'employment_types', (int) $employmentType->id),
            $request->user(),
        );

        return back()->with('success', __('messages.business.employment_type_saved'));
    }

    /** @return array{code:string,name:string,is_active:bool,sort_order:int} */
    private function masterData(Request $request, string $table, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_&-]+$/',
                Rule::unique($table, 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
        ]);

        return [
            'code' => (string) $validated['code'],
            'name' => (string) $validated['name'],
            'is_active' => (bool) $validated['is_active'],
            'sort_order' => (int) $validated['sort_order'],
        ];
    }

    /** @return array{tax_category_id:int,rate_bps:int,effective_from:string,effective_to:string|null} */
    private function taxRateData(Request $request): array
    {
        $validated = $request->validate([
            'tax_category_id' => ['required', 'integer', 'exists:tax_categories,id'],
            'rate_bps' => ['required', 'integer', 'between:0,'.TaxRateService::MAX_RATE_BPS],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            'tax_category_id' => (int) $validated['tax_category_id'],
            'rate_bps' => (int) $validated['rate_bps'],
            'effective_from' => (string) $validated['effective_from'],
            'effective_to' => isset($validated['effective_to']) ? (string) $validated['effective_to'] : null,
        ];
    }

    /** @return array{code:string,name:string,is_enabled:bool,display_order:int,external_provider:string|null} */
    private function paymentMethodData(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash:ascii',
                Rule::unique('payment_methods', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
            'display_order' => ['required', 'integer'],
            'external_provider' => ['nullable', 'string', 'max:32', 'alpha_dash:ascii'],
        ]);

        return [
            'code' => (string) $validated['code'],
            'name' => (string) $validated['name'],
            'is_enabled' => (bool) $validated['is_enabled'],
            'display_order' => (int) $validated['display_order'],
            'external_provider' => isset($validated['external_provider'])
                ? (string) $validated['external_provider']
                : null,
        ];
    }
}
