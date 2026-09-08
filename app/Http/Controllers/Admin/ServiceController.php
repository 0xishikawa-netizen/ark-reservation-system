<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Service\CreateService;
use App\Actions\Service\SetServiceStaff;
use App\Actions\Service\ToggleServiceActive;
use App\Actions\Service\UpdateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Service;
use App\Models\Staff;
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
        $services = $query->get($search, $onlyActive);

        return Inertia::render('Admin/Services/Index', [
            'services' => $services->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category,
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
            ],
        ]);
    }

    public function create(ServiceStaffOptionsQuery $staffQuery): Response
    {
        return Inertia::render('Admin/Services/Create', [
            'staff' => $staffQuery->get(),
        ]);
    }

    public function store(
        StoreServiceRequest $request,
        CreateService $createService,
    ): RedirectResponse {
        $createService->execute($request->validated(), $request->user());

        return redirect()->route('admin.services.index')
            ->with('success', 'サービスを作成しました。');
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
                'is_online_bookable' => $service->is_online_bookable,
                'requires_staff' => $service->requires_staff,
                'color' => $service->color,
                'sort_order' => $service->sort_order,
                'staff_ids' => $staffQuery->selectedIds($service),
            ],
            'staff' => $staffQuery->get(),
        ]);
    }

    public function update(
        UpdateServiceRequest $request,
        Service $service,
        UpdateService $updateService,
        SetServiceStaff $setServiceStaff,
    ): RedirectResponse {
        $data = $request->validated();
        $service = $updateService->execute($service, $data, $request->user());
        $setServiceStaff->execute($service, $data['staff_ids'] ?? [], $request->user());

        return redirect()->route('admin.services.index')
            ->with('success', 'サービスを更新しました。');
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
            $validated['active'] ? 'サービスを有効化しました。' : 'サービスを無効化しました。',
        );
    }
}
