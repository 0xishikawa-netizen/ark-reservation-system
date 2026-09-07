<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Staff\CreateStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(): Response
    {
        $staff = Staff::query()
            ->select(['user_id', 'display_name', 'color', 'is_bookable', 'sort_order'])
            ->with('user:id,name,email')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Staff $staffMember): array => [
                'user_id' => $staffMember->user_id,
                'name' => $staffMember->user->name,
                'email' => $staffMember->user->email,
                'display_name' => $staffMember->display_name,
                'color' => $staffMember->color,
                'is_bookable' => $staffMember->is_bookable,
            ]);

        return Inertia::render('Admin/Staff/Index', [
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
            ->with('success', 'スタッフを作成し、パスワード設定メールを送信しました。');
    }
}
