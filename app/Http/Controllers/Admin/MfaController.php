<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\MfaPolicy;
use App\Domain\Auth\SmsOtpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartPhoneVerificationRequest;
use App\Http\Requests\Admin\VerifyPhoneRequest;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Audit\AuditLogger;
use App\Support\Security\PiiHasher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * スタッフ自身の MFA 管理（PLAN §12）。
 *
 * 自分の資格情報だけを扱う。**他ユーザーの credential には一切アクセスしない。**
 * TOTP の有効化・確認・無効化は Fortify のルートが担当し、
 * ここは状態表示と電話番号（SMS フォールバック）の設定を担う。
 */
class MfaController extends Controller
{
    public function show(Request $request, MfaPolicy $mfaPolicy): Response
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $passwordConfirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $passwordTimeout = (int) config('auth.password_timeout', 900);

        // 再認証後にこの画面へ戻す。
        if (now()->timestamp - $passwordConfirmedAt >= $passwordTimeout) {
            $request->session()->put('url.intended', route('admin.mfa.show'));
        }

        return Inertia::render('Admin/Profile/Mfa', [
            'mfa' => $mfaPolicy->summaryFor($user),
            'totp' => [
                'pending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
                'enabled' => $user->two_factor_confirmed_at !== null,
            ],
            'phone' => [
                // 平文は返さない。確認済みかどうかと末尾のみ。
                'verified' => $mfaPolicy->hasVerifiedPhone($user),
                'masked' => $this->maskedPhone($user),
                'pending_verification' => $request->session()->has('mfa.phone.pending'),
            ],
            'google' => [
                'linked' => $user->socialAccounts()
                    ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
                    ->exists(),
            ],
        ]);
    }

    /**
     * 電話番号の確認開始。**機微操作のため password.confirm 配下**。
     *
     * 旧番号 → 新番号を即 verified にはしない。必ず新番号への OTP 検証を通す。
     */
    public function startPhoneVerification(
        StartPhoneVerificationRequest $request,
        SmsOtpService $otp,
    ): RedirectResponse {
        $user = $request->user();
        $phone = (string) $request->validated()['phone'];

        $otp->send($user, $phone, SmsOtpService::PURPOSE_PHONE_VERIFICATION, $request->ip());

        // 検証が終わるまで確定させない。平文はセッションにのみ一時保持する。
        $request->session()->put('mfa.phone.pending', encrypt($phone));

        return back()->with('success', '認証コードを送信しました。');
    }

    /**
     * 新しい電話番号を OTP で確認して確定する。
     */
    public function verifyPhone(
        VerifyPhoneRequest $request,
        SmsOtpService $otp,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $request->user();
        $pending = $request->session()->get('mfa.phone.pending');

        if (! is_string($pending)) {
            return back()->withErrors(['code' => '確認中の電話番号がありません。もう一度お試しください。']);
        }

        $phone = (string) decrypt($pending);

        $otp->verify($user, (string) $request->validated()['code'], SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        $staff = $user->staff;

        if ($staff === null) {
            return back()->withErrors(['phone' => 'スタッフ情報が見つかりません。']);
        }

        $staff->forceFill([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ])->save();

        $request->session()->forget('mfa.phone.pending');

        $auditLogger->log(
            'mfa.phone.changed',
            null,
            // 平文の電話番号は残さない
            "SMS フォールバック用の電話番号を確認済みに更新 user#{$user->getKey()}",
            $user,
        );

        return back()->with('success', '電話番号を確認しました。SMS を予備の認証手段として利用できます。');
    }

    private function maskedPhone(User $user): ?string
    {
        $phone = $user->staff?->phone;

        if (! is_string($phone) || $phone === '') {
            return null;
        }

        $normalized = PiiHasher::normalizePhone($phone);

        if ($normalized === null) {
            return null;
        }

        // 末尾 4 桁のみ表示。
        return str_repeat('*', max(0, mb_strlen($normalized) - 4)).mb_substr($normalized, -4);
    }
}
