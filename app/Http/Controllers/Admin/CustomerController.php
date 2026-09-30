<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Customer\UpdateCustomerKarte;
use App\Actions\Customer\UpdateCustomerProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCustomerKarteRequest;
use App\Http\Requests\Admin\UpdateCustomerNoteRequest;
use App\Http\Requests\Admin\UpdateCustomerProfileRequest;
use App\Models\AcquisitionChannel;
use App\Models\Customer;
use App\Models\VisitPurpose;
use App\Queries\CustomerListQuery;
use App\Queries\CustomerOverviewQuery;
use App\Queries\CustomerProfileQuery;
use App\Queries\ReservationPanelQuery;
use App\Support\Geography\Prefectures;
use Illuminate\Http\JsonResponse;
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

    /**
     * 予約台帳などから顧客カードを開いたときに表示する軽量サマリー（JSON）。
     * 画面遷移せず、右サイドパネルへ顧客情報・直近の予約を差し込むために使う。
     */
    public function summary(
        Request $request,
        Customer $customer,
        CustomerProfileQuery $query,
        CustomerOverviewQuery $overviewQuery,
    ): JsonResponse {
        $this->authorize('view', $customer);

        $profile = $this->profileData($query->get($customer));

        return response()->json([
            'profile' => [
                'user_id' => $profile['user_id'],
                'name' => $profile['name'],
                'kana' => $profile['kana'],
                'phone' => $profile['phone'],
                'gender' => $profile['gender'],
                'note' => $profile['note'],
                'created_at' => $profile['created_at'],
            ],
            'overview' => $overviewQuery->for(
                $customer->user_id,
                (bool) $request->user()?->can('reservations.view'),
            ),
        ]);
    }

    /**
     * 予約台帳サイドパネルの「顧客検索から選んだ顧客」表示用（§15-16）。
     * 特定の予約に紐づかない顧客プロフィール版。閲覧は台帳と同じ can:reservations.view、
     * 顧客 PII は can:customers.view を持つ場合のみ（§19）。
     */
    public function boardPanel(
        Request $request,
        Customer $customer,
        ReservationPanelQuery $query,
    ): JsonResponse {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = $request->user();

        return response()->json($query->getForCustomer(
            (int) $customer->user_id,
            canManage: $user?->can('reservations.manage') === true,
            canViewCustomer: $user?->can('customers.view') === true,
            referenceDate: $validated['date'] ?? null,
            canEditCustomer: $user?->can('customers.manage') === true,
        ));
    }

    /**
     * 予約台帳の顧客・予約詳細パネルから、メモだけを素早く追加・編集する（§8）。
     * 他のプロフィール項目はフル編集画面（edit/update）で扱う。他の予約アクション
     * （complete/cancel等）と同じく back() で戻し、パネル側は自前の fetch で再取得する。
     */
    public function updateNote(
        UpdateCustomerNoteRequest $request,
        Customer $customer,
        UpdateCustomerProfile $updateCustomerProfile,
    ): RedirectResponse {
        $updateCustomerProfile->execute(
            $customer,
            ['note' => $request->validated('note')],
            $request->user(),
        );

        return back()->with('success', __('messages.customer.note_updated'));
    }

    public function edit(Customer $customer, CustomerProfileQuery $query): Response
    {
        $this->authorize('update', $customer);

        $customer->loadMissing(['visitPurposes:id', 'referrer.user:id,name']);

        return Inertia::render('Admin/Customers/Edit', [
            'customer' => $this->profileData($query->get($customer)),
            'karte' => [
                'acquisition_channel_id' => $customer->acquisition_channel_id,
                'acquisition_note' => $customer->acquisition_note,
                'visit_purpose_ids' => $customer->visitPurposes->pluck('id')->values(),
                'visit_purpose_note' => $customer->visit_purpose_note,
                'referrer_customer_id' => $customer->referrer_customer_id,
                'referrer_customer_name' => $customer->referrer?->user?->name,
                'referrer_name' => $customer->referrer_name,
                'prefecture' => $customer->prefecture,
                'city' => $customer->city,
            ],
            'acquisitionChannels' => AcquisitionChannel::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            'visitPurposes' => VisitPurpose::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            'prefectures' => Prefectures::ALL,
            'canManageKarte' => request()->user()?->can('customers.manage') ?? false,
            'customerSearchEndpoint' => route('admin.reservations.customer-search'),
        ]);
    }

    public function updateKarte(
        UpdateCustomerKarteRequest $request,
        Customer $customer,
        UpdateCustomerKarte $updateCustomerKarte,
    ): RedirectResponse {
        $updateCustomerKarte->execute($customer, $request->validated(), $request->user());

        return back()->with('success', __('messages.customer.karte_updated'));
    }

    public function update(
        UpdateCustomerProfileRequest $request,
        Customer $customer,
        UpdateCustomerProfile $updateCustomerProfile,
    ): RedirectResponse {
        $this->authorize('update', $customer);

        $updateCustomerProfile->execute($customer, $request->validated(), $request->user());

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', __('messages.customer.profile_updated'));
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
