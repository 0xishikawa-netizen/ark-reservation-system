<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Service\CreateService;
use App\Actions\Service\SetServiceResources;
use App\Actions\Service\SetServiceStaff;
use App\Actions\Service\ToggleServiceActive;
use App\Actions\Service\UpdateService;
use App\Domain\Masters\MasterDeletionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Booth;
use App\Models\Qualification;
use App\Models\Service;
use App\Models\ServiceAnalysisCategory;
use App\Models\Staff;
use App\Models\TaxCategory;
use App\Queries\ServiceListQuery;
use App\Queries\ServiceStaffOptionsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Request $request, ServiceListQuery $query): Response
    {
        $searchInput = $request->query('search');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $onlyActive = $request->boolean('only_active');
        $categoryInput = $request->query('category');
        $category = is_string($categoryInput) && $categoryInput !== '' ? $categoryInput : null;
        $services = $query->get($search, $onlyActive, $category);

        return Inertia::render('Admin/Services/Index', [
            // 削除済み（復元用）は管理者（masters.delete）にだけ渡す。
            'trashed' => request()->user()?->can('masters.delete') ? app(MasterDeletionService::class)->trashed('services') : [],
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category,
                'analysis_category_name' => $service->analysisCategory?->name,
                'tax_category_name' => $service->taxCategory?->name,
                'duration_min' => $service->duration_min,
                'price' => $service->price,
                'is_online_bookable' => $service->is_online_bookable,
                'requires_staff' => $service->requires_staff,
                'color' => $service->color,
                'is_active' => $service->is_active,
                'sort_order' => $service->sort_order,
                'staff_names' => $service->staff
                    ->map(fn (Staff $staff): string => $staff->display_name)
                    ->values()
                    ->all(),
            ])->values(),
            'filters' => [
                'search' => $search,
                'only_active' => $onlyActive,
                'category' => $category,
            ],
            // 検索欄を選択式にするための候補（登録済みのカテゴリとメニュー名）。
            // 表示順の既定の並び（グローバルスコープ）は DISTINCT と両立しないため外す（MySQL の ONLY_FULL_GROUP_BY）。
            'categoryOptions' => Service::query()->withoutGlobalScope('sort_order')->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category')->values(),
            'nameOptions' => Service::query()->orderBy('sort_order')->orderBy('name')->pluck('name')->values(),
        ]);
    }

    public function create(ServiceStaffOptionsQuery $staffQuery): Response
    {
        return Inertia::render('Admin/Services/Create', [
            'staff' => $staffQuery->get(),
            ...$this->masterOptions(),
        ]);
    }

    public function store(
        StoreServiceRequest $request,
        CreateService $createService,
        SetServiceResources $setResources,
    ): RedirectResponse {
        $data = $request->validated();
        $service = $createService->execute($data, $request->user());
        $setResources->execute($service, $data['booth_ids'] ?? null, $data['qualification_ids'] ?? null, $request->user());

        return redirect()->route('admin.services.index')
            ->with('success', __('messages.service.created'));
    }

    public function edit(
        Service $service,
        ServiceStaffOptionsQuery $staffQuery,
    ): Response {
        return Inertia::render('Admin/Services/Edit', [
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_min' => $service->duration_min,
                'price' => $service->price,
                'category' => $service->category,
                'analysis_category_id' => $service->analysis_category_id,
                'tax_category_id' => $service->tax_category_id,
                'is_online_bookable' => $service->is_online_bookable,
                'requires_staff' => $service->requires_staff,
                'color' => $service->color,
                'sort_order' => $service->sort_order,
                'staff_ids' => $staffQuery->selectedIds($service),
                'booth_ids' => $service->booths()->pluck('booths.id')->map(static fn (mixed $id): int => (int) $id)->values(),
                'qualification_ids' => $service->qualifications()->pluck('qualifications.id')->map(static fn (mixed $id): int => (int) $id)->values(),
            ],
            'staff' => $staffQuery->get(),
            ...$this->masterOptions(),
        ]);
    }

    public function update(
        UpdateServiceRequest $request,
        Service $service,
        UpdateService $updateService,
        SetServiceStaff $setServiceStaff,
        SetServiceResources $setResources,
    ): RedirectResponse {
        $data = $request->validated();
        $service = $updateService->execute($service, $data, $request->user());
        $setServiceStaff->execute($service, $data['staff_ids'] ?? [], $request->user());
        $setResources->execute($service, $data['booth_ids'] ?? null, $data['qualification_ids'] ?? null, $request->user());

        return redirect()->route('admin.services.index')
            ->with('success', __('messages.service.updated'));
    }

    public function setActive(
        Request $request,
        Service $service,
        ToggleServiceActive $toggleServiceActive,
    ): RedirectResponse {
        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $toggleServiceActive->execute(
            $service,
            (bool) $validated['active'],
            $request->user(),
        );

        return back()->with(
            'success',
            $validated['active'] ? __('messages.service.activated') : __('messages.service.deactivated'),
        );
    }

    /** @return array{analysisCategories:mixed,taxCategories:mixed} */
    private function masterOptions(): array
    {
        return [
            'analysisCategories' => ServiceAnalysisCategory::query()
                ->orderByDesc('is_active')->orderBy('sort_order')->get(['id', 'code', 'name', 'is_active']),
            'taxCategories' => TaxCategory::query()
                ->orderByDesc('is_active')->orderBy('sort_order')->get(['id', 'code', 'name', 'is_active']),
            // Task 11-28: 利用ブース・必要資格の選択肢。
            'booths' => Booth::query()->orderByDesc('is_active')->orderBy('sort_order')->get(['id', 'name', 'is_active']),
            'qualifications' => Qualification::query()->orderByDesc('is_active')->orderBy('sort_order')->get(['id', 'code', 'name', 'is_active']),
        ];
    }
}
