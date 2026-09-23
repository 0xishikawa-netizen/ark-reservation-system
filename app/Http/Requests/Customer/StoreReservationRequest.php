<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Domain\Membership\MembershipLedgerService;
use App\Domain\Ticket\TicketLedgerService;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketWallet;
use App\Support\SlotKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->customer !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'payment_method' => ['nullable', 'string', Rule::in(['onsite', 'ticket', 'card', 'membership'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $service = $this->validatedService($validator);

            if ($service !== null && (! $service->is_active
                || ! $service->is_online_bookable
                || ! $service->requires_staff)) {
                $validator->errors()->add(
                    'service_id',
                    __('messages.reservation.service_not_online_target'),
                );
            }

            $this->validateStaff($validator, $service);
            $this->validateBoundary($validator);
            $this->validateTicketBalance($validator);
            $this->validateMembershipBalance($validator);
        });
    }

    private function validatedService(Validator $validator): ?Service
    {
        if ($validator->errors()->has('service_id')) {
            return null;
        }

        return Service::query()->find((int) $this->input('service_id'));
    }

    private function validateStaff(Validator $validator, ?Service $service): void
    {
        if ($this->input('staff_id') === null || $validator->errors()->has('staff_id')) {
            return;
        }

        $staffId = (int) $this->input('staff_id');
        $staff = Staff::query()->find($staffId);
        $isAssigned = $service !== null && DB::table('service_staff')
            ->where('service_id', $service->id)
            ->where('staff_id', $staffId)
            ->exists();

        if ($staff === null || ! $staff->is_bookable || ! $isAssigned) {
            $validator->errors()->add(
                'staff_id',
                __('messages.reservation.staff_not_assigned_to_selected'),
            );
        }
    }

    private function validateBoundary(Validator $validator): void
    {
        if ($validator->errors()->has('starts_at')) {
            return;
        }

        try {
            $startsAt = CarbonImmutable::parse((string) $this->input('starts_at'));
        } catch (Throwable) {
            return;
        }

        if (! SlotKey::fromSettings()->isBoundary($startsAt)) {
            $validator->errors()->add(
                'starts_at',
                __('messages.reservation.non_boundary_start'),
            );
        }
    }

    private function validateTicketBalance(Validator $validator): void
    {
        if ($this->input('payment_method') !== 'ticket'
            || $validator->errors()->has('payment_method')) {
            return;
        }

        $customer = $this->user()?->customer;

        if ($customer === null) {
            return;
        }

        $ledger = app(TicketLedgerService::class);
        $available = TicketWallet::query()
            ->where('customer_id', $customer->user_id)
            ->active()
            ->get()
            ->sum(fn (TicketWallet $wallet): int => $ledger->available($wallet));

        if ($available < 1) {
            $validator->errors()->add(
                'payment_method',
                __('messages.ticket.none_available_sentence'),
            );
        }
    }

    private function validateMembershipBalance(Validator $validator): void
    {
        if ($this->input('payment_method') !== 'membership'
            || $validator->errors()->has('payment_method')) {
            return;
        }

        $customer = $this->user()?->customer;

        if ($customer === null) {
            return;
        }

        $membership = Membership::query()
            ->where('customer_id', $customer->user_id)
            ->bookable()
            ->latest('id')
            ->first();

        if ($membership === null
            || app(MembershipLedgerService::class)->available($membership) < 1) {
            $validator->errors()->add(
                'payment_method',
                __('messages.membership.none_available_sentence'),
            );
        }
    }
}
