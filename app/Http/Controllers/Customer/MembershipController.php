<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Membership\MembershipCheckoutSaga;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Enums\Membership\MembershipStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SubscribeMembershipRequest;
use App\Http\Requests\Customer\UpdateMembershipPaymentMethodRequest;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Queries\CustomerMembershipQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembershipController extends Controller
{
    public function show(Request $request, CustomerMembershipQuery $query): Response
    {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        return Inertia::render('Customer/Membership/Index', [
            'membership' => $query->currentFor((int) $customer->user_id),
            'history' => $query->historyFor((int) $customer->user_id),
            'plans' => $query->activePlans(),
            'paymentMethod' => $customer->hasDefaultPaymentMethod()
                ? [
                    'brand' => $customer->pm_type,
                    'last_four' => $customer->pm_last_four,
                ]
                : null,
            // Stripe の publishable key のみ。secret / subscription ID は共有しない。
            'stripeKey' => (string) config('stripe.key'),
        ]);
    }

    public function subscribe(
        SubscribeMembershipRequest $request,
        MembershipCheckoutSaga $saga,
    ): RedirectResponse {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        $plan = MembershipPlan::query()
            ->where('is_active', true)
            ->findOrFail($request->integer('membership_plan_id'));
        $paymentMethodId = $request->string('payment_method_id')->toString();

        try {
            $saga->execute(
                $customer,
                $plan,
                $paymentMethodId === '' ? null : $paymentMethodId,
                $request->user(),
            );
        } catch (PaymentGatewayException) {
            return back()->with(
                'error',
                'お申し込みの確認に時間がかかっています。しばらくして状態をご確認ください。',
            );
        }

        return redirect()
            ->route('mypage.membership.show')
            ->with('success', '利用権の申し込みを受け付けました。');
    }

    public function cancel(
        Request $request,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $membership = $this->currentMembership($request);

        $subscriptions->requestCancelAtPeriodEnd($membership, $request->user());

        return back()->with('success', '当期末で停止します。期末までは利用できます。');
    }

    public function resume(
        Request $request,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $membership = $this->currentMembership($request);

        $subscriptions->resumeCancelAtPeriodEnd($membership, $request->user());

        return back()->with('success', '次回更新での解約を取り消しました。');
    }

    public function updatePaymentMethod(
        UpdateMembershipPaymentMethodRequest $request,
    ): RedirectResponse {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        $customer->updateDefaultPaymentMethod(
            $request->string('payment_method_id')->toString(),
        );

        return back()->with('success', '支払い方法を更新しました。');
    }

    private function currentMembership(Request $request): Membership
    {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        return Membership::query()
            ->where('customer_id', $customer->user_id)
            ->where('status', '!=', MembershipStatus::Canceled->value)
            ->latest('id')
            ->firstOrFail();
    }
}
