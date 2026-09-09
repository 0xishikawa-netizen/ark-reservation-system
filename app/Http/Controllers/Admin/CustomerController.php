<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Customer\UpdateCustomerProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCustomerProfileRequest;
use App\Models\Customer;
use App\Queries\CustomerListQuery;
use App\Queries\CustomerOverviewQuery;
use App\Queries\CustomerProfileQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerListQuery $query): Response
    {
        $this->authorize('viewAny', Customer::class);

        $searchInput = $request->query('q');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $customers = $query->paginate($search);
        $customers->appends($search === '' ? [] : ['q' => $search]);

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers,
            'filters' => [
                'q' => $search,
            ],
        ]);
    }

    public function show(
        Request $request,
        Customer $customer,
        CustomerProfileQuery $query,
        CustomerOverviewQuery $overviewQuery,
    ): Response {
        $this->authorize('view', $customer);

        return Inertia::render('Admin/Customers/Show', [
            'customer' => $this->profileData($query->get($customer)),
            'overview' => $overviewQuery->for(
                $customer->user_id,
                (bool) $request->user()?->can('reservations.view'),
            ),
        ]);
    }

    public function edit(Customer $customer, CustomerProfileQuery $query): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Admin/Customers/Edit', [
            'customer' => $this->profileData($query->get($customer)),
        ]);
    }

    public function update(
        UpdateCustomerProfileRequest $request,
        Customer $customer,
        UpdateCustomerProfile $updateCustomerProfile,
    ): RedirectResponse {
        $this->authorize('update', $customer);

        $updateCustomerProfile->execute($customer, $request->validated(), $request->user());

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', '顧客プロフィールを更新しました。');
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
            'note' => $customer->note,
            'email' => $customer->user->email,
            'email_verified' => $customer->user->email_verified_at !== null,
            'created_via' => $customer->created_via,
            'created_at' => $customer->created_at?->toDateTimeString(),
        ];
    }
}
