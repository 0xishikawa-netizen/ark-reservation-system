<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Fortify\PasswordValidationRules;
use App\Domain\Reservation\GuestReservationTokenService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class BookingMemberUpgradeController extends Controller
{
    use PasswordValidationRules;

    public function store(
        Request $request,
        string $selector,
        GuestReservationTokenService $guestTokens,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tokenParts = explode('.', $selector, 2);

        if (count($tokenParts) !== 2) {
            abort(404);
        }

        $reservation = $guestTokens->resolve($tokenParts[0], $tokenParts[1]);

        if ($reservation === null) {
            abort(404);
        }

        $reservation->loadMissing('customer.user');
        $user = $reservation->customer->user;

        // 会員化済みのトークンから資格情報を再設定できないよう、一度だけ許可する。
        if ($user->password !== null) {
            abort(404);
        }

        $usesPlaceholderEmail = Str::endsWith(Str::lower($user->email), '@ark.invalid');

        if ($request->filled('email')) {
            $request->merge([
                'email' => Str::lower(trim((string) $request->input('email'))),
            ]);
        }

        $validated = $request->validate([
            'email' => [
                $usesPlaceholderEmail ? 'required' : 'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email')->ignore($user->id),
            ],
            'password' => $this->passwordRules(),
        ]);
        $email = isset($validated['email'])
            ? Str::lower(trim((string) $validated['email']))
            : $user->email;

        DB::transaction(function () use ($user, $email, $validated, $auditLogger): void {
            $user->forceFill([
                'email' => $email,
                'email_verified_at' => null,
                'password' => Hash::make((string) $validated['password']),
            ])->save();

            $auditLogger->log(
                'customer.guest_upgraded_to_member',
                $user->customer,
                "ゲスト顧客を会員化 #{$user->id} {$user->name}",
                $user,
            );
        });

        Auth::login($user);
        $request->session()->regenerate();
        $user->sendEmailVerificationNotification();

        return redirect()
            ->route('home')
            ->with('success', __('messages.auth.member_upgrade_completed'));
    }
}
