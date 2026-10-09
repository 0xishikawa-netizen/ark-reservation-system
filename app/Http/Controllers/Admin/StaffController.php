<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Staff\CreateStaff;
use App\Actions\Staff\DeactivateStaff;
use App\Actions\Staff\SetStaffCapabilities;
use App\Actions\Staff\UpdateStaff;
use App\Domain\Masters\MasterDeletionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Models\Qualification;
use App\Models\Service;
use App\Models\Staff;
use App\Queries\StaffListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(StaffListQuery $query): Response
    {
        $staff = $query->get()
            ->map(fn (Staff $staffMember): array => [
                'user_id' => $staffMember->user_id,
                'name' => $staffMember->user->name,
                'email' => $staffMember->user->email,
                'display_name' => $staffMember->display_name,
                'color' => $staffMember->color,
                'is_bookable' => $staffMember->is_bookable,
                'sort_order' => $staffMember->sort_order,
                'role' => $staffMember->user->getRoleNames()->first(),
                'is_active' => $staffMember->user->is_active,
            ]);

        return Inertia::render('Admin/Staff/Index', [
            // 削除済み（復元用）は管理者（masters.delete）にだけ渡す。
            'trashed' => request()->user()?->can('masters.delete') ? app(MasterDeletionService::class)->trashed('staff') : [],
            'staff' => $staff,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Staff/Create');
    }

    public function store(StoreStaffRequest $request, CreateStaff $createStaff): RedirectResponse
    {
        $createStaff->create($request->validated());

        return redirect()->route('admin.staff.index')
            ->with('success', __('messages.staff.created'));
    }

    public function edit(Staff $staff, StaffListQuery $query): Response
    {
        $staff = $query->forEdit($staff);

        return Inertia::render('Admin/Staff/Edit', [
            'staff' => [
                'user_id' => $staff->user_id,
                'name' => $staff->user->name,
                'email' => $staff->user->email,
                'display_name' => $staff->display_name,
                'color' => $staff->color,
                'is_bookable' => $staff->is_bookable,
                'sort_order' => $staff->sort_order,
                'role' => $staff->user->getRoleNames()->first(),
                'is_active' => $staff->user->is_active,
                'service_ids' => $staff->services()->pluck('services.id')->map(static fn (mixed $id): int => (int) $id)->values(),
                'qualification_ids' => $staff->qualifications()->pluck('qualifications.id')->map(static fn (mixed $id): int => (int) $id)->values(),
            ],
            // Task 11-28: 実施できる施術・保有資格の選択肢。
            'services' => Service::query()->orderByDesc('is_active')->orderBy('sort_order')->get(['id', 'name', 'is_active']),
            'qualifications' => Qualification::query()->orderByDesc('is_active')->orderBy('sort_order')->get(['id', 'name', 'is_active']),
            'roles' => [
                ['title' => __('messages.masters.type_staff'), 'value' => 'staff'],
                ['title' => __('messages.staff.role_manager'), 'value' => 'manager'],
                ['title' => __('messages.staff.role_admin'), 'value' => 'admin'],
            ],
        ]);
    }

    public function update(
        UpdateStaffRequest $request,
        Staff $staff,
        UpdateStaff $updateStaff,
        SetStaffCapabilities $setCapabilities,
    ): RedirectResponse {
        $data = $request->validated();
        $updateStaff->execute($staff, $data, $request->user());
        $setCapabilities->execute($staff, $data['service_ids'] ?? null, $data['qualification_ids'] ?? null, $request->user());

        return redirect()->route('admin.staff.index')
            ->with('success', __('messages.staff.updated'));
    }

    public function deactivate(
        Request $request,
        Staff $staff,
        DeactivateStaff $deactivateStaff,
    ): RedirectResponse {
        $deactivateStaff->execute($staff, $request->user());

        return redirect()->route('admin.staff.index')
            ->with('success', __('messages.staff.unbookable'));
    }
}
