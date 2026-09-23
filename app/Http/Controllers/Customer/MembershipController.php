<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Membership\MembershipCheckoutSaga;
use App\Domain\Membership\MembershipSubscriptionService;
use App\Enums\Membership\MembershipStatus;
use App\Exceptions\Payment\PaymentGatewayDeclinedException;
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

    /**
     * 申込。3DS/SCA が必要なら確認画面へ、不要なら完了。pending を「成功」とは表示しない。
     */
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
            $result = $saga->execute(
                $customer,
                $plan,
                $paymentMethodId === '' ? null : $paymentMethodId,
                $request->user(),
            );
        } catch (PaymentGatewayDeclinedException) {
            return back()->with('error', __('messages.payment.card_declined'));
        } catch (PaymentGatewayException) {
            // 結果不明。成功と断定しない（needs_attention が立ち reconcile 対象になる）。
            return back()->with('error', __('messages.membership.apply_pending'));
        }

        if ($result->requiresConfirmation) {
            return redirect()->route('mypage.membership.confirm');
        }

        if ($result->membership->status === MembershipStatus::Active) {
            return redirect()->route('mypage.membership.show')
                ->with('success', __('messages.membership.applied'));
        }

        return redirect()->route('mypage.membership.show')
            ->with('info', __('messages.membership.apply_accepted'));
    }

    /**
     * 3DS/SCA 確認画面。自分の進行中申込（pending）だけを対象にする。
     */
    public function confirm(
        Request $request,
        MembershipSubscriptionService $subscriptions,
    ): Response|RedirectResponse {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        $membership = Membership::query()
            ->with('plan:id,name,price')
            ->where('customer_id', $customer->user_id)
            ->where('status', MembershipStatus::Pending->value)
            ->whereNotNull('stripe_subscription_id')
            ->latest('id')
            ->first();

        if ($membership === null) {
            return redirect()->route('mypage.membership.show');
        }

        try {
            $result = $subscriptions->syncCheckout($membership, $request->user());
        } catch (PaymentGatewayException) {
            return redirect()->route('mypage.membership.show')
                ->with('info', __('messages.membership.payment_pending'));
        }

        if (! $result->requiresConfirmation || $result->clientSecret === null) {
            $active = $result->membership->status === MembershipStatus::Active;

            return redirect()->route('mypage.membership.show')->with(
                $active ? 'success' : 'info',
                $active
                    ? __('messages.membership.payment_completed')
                    : __('messages.membership.apply_accepted'),
            );
        }

        return Inertia::render('Customer/Membership/Confirm', [
            'plan' => [
                'name' => (string) $membership->plan?->name,
                'price' => (int) ($membership->plan?->price ?? 0),
            ],
            // publishable key と PaymentIntent client_secret のみ（ブラウザ用）。secret key は渡さない。
            'stripe' => [
                'publishable_key' => (string) config('stripe.key'),
                'client_secret' => $result->clientSecret,
            ],
        ]);
    }

    /**
     * Stripe.js での認証後に呼ばれる。client の申告ではなく Stripe retrieve の結果で判断する。
     */
    public function syncPayment(
        Request $request,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        $membership = Membership::query()
            ->where('customer_id', $customer->user_id)
            ->where('status', '!=', MembershipStatus::Canceled->value)
            ->whereNotNull('stripe_subscription_id')
            ->latest('id')
            ->first();

        if ($membership === null) {
            return redirect()->route('mypage.membership.show');
        }

        try {
            $result = $subscriptions->syncCheckout($membership, $request->user());
        } catch (PaymentGatewayDeclinedException) {
            return redirect()->route('mypage.membership.show')
                ->with('error', __('messages.payment.card_declined'));
        } catch (PaymentGatewayException) {
            return redirect()->route('mypage.membership.show')
                ->with('info', __('messages.membership.payment_pending'));
        }

        if ($result->membership->status === MembershipStatus::Active) {
            return redirect()->route('mypage.membership.show')
                ->with('success', __('messages.membership.payment_completed'));
        }

        return redirect()->route('mypage.membership.show')
            ->with('info', __('messages.membership.payment_processing'));
    }

    public function cancel(
        Request $request,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $membership = $this->currentMembership($request);

        $subscriptions->requestCancelAtPeriodEnd($membership, $request->user());

        return back()->with('success', __('messages.membership.cancel_at_period_end'));
    }

    public function resume(
        Request $request,
        MembershipSubscriptionService $subscriptions,
    ): RedirectResponse {
        $membership = $this->currentMembership($request);

        $subscriptions->resumeCancelAtPeriodEnd($membership, $request->user());

        return back()->with('success', __('messages.membership.cancel_reverted'));
    }

    public function updatePaymentMethod(
        UpdateMembershipPaymentMethodRequest $request,
    ): RedirectResponse {
        $customer = $request->user()?->customer;

        abort_unless($customer instanceof Customer, 403);

        $customer->updateDefaultPaymentMethod(
            $request->string('payment_method_id')->toString(),
        );

        return back()->with('success', __('messages.membership.payment_method_updated'));
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
