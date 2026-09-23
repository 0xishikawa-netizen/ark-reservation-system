<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustMembershipRequest;
use App\Http\Requests\Admin\CancelMembershipNowRequest;
use App\Models\Customer;
use App\Models\Membership;
use App\Queries\AdminMembershipQuery;
use App\Queries\CustomerProfileQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerMembershipController extends Controller
{
    public function show(
        Customer $customer,
        AdminMembershipQuery $query,
        CustomerProfileQuery $profileQuery,
    ): Response {
        $customer = $profileQuery->get($customer);

        return Inertia::render('Admin/Customers/Membership', [
            'customer' => [
                'user_id' => (int) $customer->user_id,
                'name' => (string) $customer->user->name,
            ],
            'membership' => $query->forCustomer((int) $customer->user_id),
            'history' => $query->historyFor((int) $customer->user_id),
            'can' => [
                'adjust' => request()->user()?->can('membership.manage') ?? false,
            ],
        ]);
    }

    public function adjust(
        AdjustMembershipRequest $request,
        Membership $membership,
        MembershipLedgerService $ledger,
    ): RedirectResponse {
        $ledger->adjust(
            $membership,
            $request->integer('delta'),
            $request->string('operation_key')->toString(),
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', __('messages.membership.adjusted'));
    }

    public function cancelNow(
        CancelMembershipNowRequest $request,
        Membership $membership,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $subscriptions->cancelNow(
            $membership,
            $request->string('reason')->toString(),
            $request->user(),
        );

        return back()->with('success', __('messages.membership.canceled_now'));
    }

    public function sync(
        Request $request,
        Membership $membership,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $subscriptions->syncFromStripe($membership, $request->user());

        return back()->with('success', __('messages.membership.stripe_synced'));
    }
}
