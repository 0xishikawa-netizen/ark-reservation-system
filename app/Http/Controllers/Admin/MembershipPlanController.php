<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\CreateMembershipPlan;
use App\Actions\Membership\ToggleMembershipPlanActive;
use App\Actions\Membership\UpdateMembershipPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMembershipPlanRequest;
use App\Http\Requests\Admin\UpdateMembershipPlanRequest;
use App\Models\MembershipPlan;
use App\Queries\MembershipPlanListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembershipPlanController extends Controller
{
    public function index(MembershipPlanListQuery $query): Response
    {
        return Inertia::render('Admin/MembershipPlans/Index', [
            'membershipPlans' => $query->get()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/MembershipPlans/Create');
    }

    public function store(
        StoreMembershipPlanRequest $request,
        CreateMembershipPlan $createMembershipPlan,
    ): RedirectResponse {
        $createMembershipPlan->execute($request->validated(), $request->user());

        return back()->with('success', __('messages.membership.plan_created'));
    }

    public function edit(MembershipPlan $membershipPlan): Response
    {
        return Inertia::render('Admin/MembershipPlans/Edit', [
            'membershipPlan' => $membershipPlan->only([
                'id',
                'name',
                'price',
                'usage_count_per_period',
                'billing_interval',
                'stripe_price_id',
                'is_active',
                'sort_order',
            ]),
        ]);
    }

    public function update(
        UpdateMembershipPlanRequest $request,
        MembershipPlan $membershipPlan,
        UpdateMembershipPlan $updateMembershipPlan,
    ): RedirectResponse {
        $updateMembershipPlan->execute($membershipPlan, $request->validated(), $request->user());

        return back()->with('success', __('messages.membership.plan_updated'));
    }

    public function setActive(
        Request $request,
        MembershipPlan $membershipPlan,
        ToggleMembershipPlanActive $toggleMembershipPlanActive,
    ): RedirectResponse {
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $active = (bool) $validated['active'];

        $toggleMembershipPlanActive->execute($membershipPlan, $active, $request->user());

        return back()->with(
            'success',
            $active ? '月額プランを有効化しました。' : '月額プランを無効化しました。',
        );
    }
}
