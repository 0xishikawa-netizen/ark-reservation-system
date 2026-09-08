<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Actions\Customer\UpdateCustomerProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCustomerProfileRequest;
use App\Models\Customer;
use App\Models\User;
use App\Queries\CustomerProfileQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request, CustomerProfileQuery $query): Response
    {
        $customer = $this->customerFor($request);
        $this->authorize('view', $customer);

        return Inertia::render('Customer/Profile/Show', [
            'customer' => $this->profileData($query->get($customer)),
        ]);
    }

    public function edit(Request $request, CustomerProfileQuery $query): Response
    {
        $customer = $this->customerFor($request);
        $this->authorize('update', $customer);

        return Inertia::render('Customer/Profile/Edit', [
            'customer' => $this->profileData($query->get($customer)),
        ]);
    }

    public function update(
        UpdateCustomerProfileRequest $request,
        UpdateCustomerProfile $updateCustomerProfile,
    ): RedirectResponse {
        $customer = $this->customerFor($request);
        $this->authorize('update', $customer);

        // 顧客セルフ編集では、管理用メモをリクエストに含められても更新対象にしない。
        $data = $request->safe()->only([
            'name',
            'kana',
            'phone',
            'birthday',
            'gender',
        ]);

        $updateCustomerProfile->execute($customer, $data, $request->user());

        return redirect()->route('mypage.profile.show')
            ->with('success', 'プロフィールを更新しました。');
    }

    private function customerFor(Request $request): Customer
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $customer = $user->customer;

        if (! $customer instanceof Customer) {
            abort(403);
        }

        return $customer;
    }

    /** @return array<string, mixed> */
    private function profileData(Customer $customer): array
    {
        return [
            'user_id' => $customer->user_id,
            'name' => $customer->user->name,
            'kana' => $customer->kana,
            'phone' => $customer->phone,
            'birthday' => $customer->birthday?->toDateString(),
            'gender' => $customer->gender,
            'email' => $customer->user->email,
            'email_verified' => $customer->user->email_verified_at !== null,
        ];
    }
}
