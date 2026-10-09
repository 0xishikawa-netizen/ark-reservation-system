<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\MfaPolicy;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Google ログイン（Phase 9.6 / Laravel Socialite）。
 *
 * 方針:
 * - セッションベース Web アプリなので Socialite の stateful（state 検証あり）を使う。
 *   `stateless()` は使わない。CSRF/state 保護を弱めない。
 * - 特権ロール（staff/manager/admin）を Google callback から **自動作成・自動昇格・自動 link しない**。
 * - 既存 email と一致しただけの silent account linking を禁止する。
 * - OAuth の access/refresh token は保存しない。identity（provider_user_id）＋最小データのみ。
 * - Google 認証成功後は既存ログインと同じ Security Pipeline（email 認証 / Role / MFA /
 *   Admin Access / Idle Timeout / Audit）へ合流させる。Google 専用の bypass 経路は作らない。
 */
class GoogleAuthController extends Controller
{
    private const PENDING_TTL_SECONDS = 600;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * 未ログインユーザーの「Google でログイン / 登録」入口。
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('home');
        }

        $request->session()->put('google_oauth.intent', 'login');

        return $this->toGoogle();
    }

    /**
     * ログイン済みユーザーが Security 設定から Google を連携する入口。
     * ルート側で `password.confirm` を要求している（再認証必須）。
     */
    public function startLink(Request $request): RedirectResponse
    {
        $request->session()->put('google_oauth.intent', 'link');
        $request->session()->put('google_oauth.link_user_id', (int) $request->user()->getKey());
        $request->session()->put('google_oauth.return', $this->safeReturnPath($request));

        return $this->toGoogle();
    }

    /**
     * 連携完了後の戻り先。ARK 内部パスのみ許可（open redirect 対策）。
     */
    private function safeReturnPath(Request $request): string
    {
        $candidate = (string) ($request->query('return') ?? $request->headers->get('referer') ?? '');
        $path = (string) parse_url($candidate, PHP_URL_PATH);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return route('mypage.security.show', absolute: false);
        }

        return $path;
    }

    /**
     * Google からのコールバック。intent（login / link）で分岐する。
     */
    public function callback(Request $request): RedirectResponse
    {
        $intent = (string) $request->session()->pull('google_oauth.intent', 'login');
        $linkUserId = (int) $request->session()->pull('google_oauth.link_user_id', 0);
        $returnPath = (string) $request->session()->pull('google_oauth.return', '');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->fail(__('messages.common.session_expired'));
        } catch (Throwable) {
            // 内部情報（例外詳細）は表示しない。
            return $this->fail(__('messages.google.login_failed'));
        }

        $providerId = (string) $googleUser->getId();
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $name = trim((string) ($googleUser->getName() ?? '')) ?: __('messages.google.default_user_name');
        $emailVerified = filter_var(
            $googleUser->user['email_verified'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        if ($providerId === '' || $email === '') {
            return $this->fail(__('messages.google.profile_unavailable'));
        }

        if (! $emailVerified) {
            return $this->fail(__('messages.google.email_unverified'));
        }

        if ($intent === 'link') {
            return $this->handleLink($request, $linkUserId, $returnPath, $providerId, $email);
        }

        return $this->handleLogin($request, $providerId, $email, $name);
    }

    /**
     * 「既存 ARK アカウントがあります」画面。パスワードで本人確認してから link する。
     */
    public function confirm(Request $request): Response|RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/LinkGoogle', [
            'email' => $pending['email'],
        ]);
    }

    /**
     * 既存 Customer アカウントへの Google 連携を、ARK パスワード確認のうえ実行する。
     */
    public function linkExisting(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return $this->fail(__('messages.google.link_expired'));
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($validated['email']));

        // pending の email（Google が返した検証済みメール）と一致することを要求する。
        if (! hash_equals($pending['email'], $email)) {
            throw ValidationException::withMessages([
                'email' => __('messages.google.email_mismatch'),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('messages.auth.invalid_credentials'),
            ]);
        }

        // 特権アカウントはこの経路で link させない（多重ガード）。
        if ($user->hasAnyRole(MfaPolicy::REQUIRED_ROLES)) {
            $request->session()->forget('google_oauth.pending');

            return $this->fail(__('messages.google.link_after_login'));
        }

        $this->attachSocialAccount($user, $pending['provider_user_id'], $email);

        if ($user->email_verified_at === null) {
            // Google が検証済みメールを保証し、かつ ARK パスワードで本人確認できたため確定してよい。
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $request->session()->forget('google_oauth.pending');

        $this->auditLogger->log(
            'auth.google.linked',
            null,
            "既存アカウントへ Google を連携（パスワード確認あり）user#{$user->getKey()}",
            $user,
        );

        return $this->loginAndContinue($request, $user);
    }

    /**
     * Google 連携を解除する。ルート側で `password.confirm` を要求している。
     * 唯一のログイン手段を失わせない（パスワード未設定ユーザーは解除不可）。
     */
    public function unlink(Request $request): RedirectResponse
    {
        $user = $request->user();

        $accounts = $user->socialAccounts()
            ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
            ->get();

        if ($accounts->isEmpty()) {
            return back()->with('info', __('messages.google.not_linked'));
        }

        if (blank($user->password)) {
            throw ValidationException::withMessages([
                'google' => __('messages.google.cannot_unlink_without_password')
                    .__('messages.google.set_password_first'),
            ]);
        }

        // この provider の連携行はすべて削除する（残存連携による不正アクセスを残さない）。
        $user->socialAccounts()
            ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
            ->delete();

        $this->auditLogger->log(
            'auth.google.unlinked',
            null,
            "Google 連携を解除 user#{$user->getKey()}",
            $user,
        );

        return back()->with('success', __('messages.google.unlinked'));
    }

    // ---------- 内部 ----------

    private function toGoogle(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    private function handleLogin(Request $request, string $providerId, string $email, string $name): RedirectResponse
    {
        $existing = UserSocialAccount::query()
            ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $providerId)
            ->first();

        if ($existing !== null) {
            // 既に連携済み → そのユーザーでログイン。
            $user = $existing->user;

            if ($existing->provider_email !== $email) {
                $existing->forceFill(['provider_email' => $email])->save();
            }

            $this->auditLogger->log(
                'auth.google.login',
                null,
                "Google ログイン user#{$user->getKey()}",
                $user,
            );

            return $this->loginAndContinue($request, $user);
        }

        $userByEmail = User::query()->where('email', $email)->first();

        if ($userByEmail !== null) {
            if ($userByEmail->hasAnyRole(MfaPolicy::REQUIRED_ROLES)) {
                // 特権アカウントは silent link 禁止。callback からは絶対に link しない。
                $this->auditLogger->log(
                    'auth.google.link_blocked',
                    null,
                    "特権アカウントの Google 自動連携を拒否 user#{$userByEmail->getKey()}",
                    null,
                );

                return $this->fail(
                    __('messages.google.email_used_by_admin')
                    .__('messages.google.link_from_settings'),
                );
            }

            // 既存 Customer → パスワード確認画面へ（silent link しない）。
            $request->session()->put('google_oauth.pending', [
                'provider_user_id' => $providerId,
                'email' => $email,
                'created_at' => now()->timestamp,
            ]);

            return redirect()->route('auth.google.confirm');
        }

        // 新規 → Customer のみ作成。staff/manager/admin は絶対に付与しない。
        try {
            $user = DB::transaction(function () use ($providerId, $email, $name): User {
                $user = new User;
                $user->name = $name;
                $user->email = $email;
                $user->password = null; // Google のみ。パスワードは未設定（後から設定可能）。
                // Google が email_verified=true を保証している場合のみここへ来る。
                $user->email_verified_at = now();
                $user->save();

                Customer::query()->create([
                    'user_id' => $user->id,
                    'kana' => '',
                    'created_via' => 'google',
                ]);

                $user->assignRole('customer');

                $this->attachSocialAccount($user, $providerId, $email);

                return $user;
            });
        } catch (QueryException) {
            // 同一 Google アカウントで callback が競合した場合（email / social の UNIQUE 衝突）。
            // 既に作られている行で解決し直す。
            $race = UserSocialAccount::query()
                ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
                ->where('provider_user_id', $providerId)
                ->first();

            if ($race !== null) {
                return $this->loginAndContinue($request, $race->user);
            }

            return $this->fail(__('messages.google.login_busy'));
        }

        $this->auditLogger->log(
            'auth.google.registered',
            null,
            "Google 新規登録（Customer）user#{$user->getKey()}",
            $user,
        );

        return $this->loginAndContinue($request, $user);
    }

    private function handleLink(
        Request $request,
        int $linkUserId,
        string $returnPath,
        string $providerId,
        string $email,
    ): RedirectResponse {
        $user = $request->user();
        $back = $returnPath !== '' && str_starts_with($returnPath, '/') && ! str_starts_with($returnPath, '//')
            ? $returnPath
            : route('mypage.security.show', absolute: false);

        if ($user === null || $user->getKey() !== $linkUserId) {
            return $this->fail(__('messages.google.link_session_invalid'));
        }

        $existing = UserSocialAccount::query()
            ->where('provider', UserSocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $providerId)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->user_id === (int) $user->getKey()) {
                return redirect()->to($back)->with('info', __('messages.google.already_linked'));
            }

            return redirect()->to($back)
                ->withErrors(['google' => __('messages.google.linked_to_other_account')]);
        }

        // 1 ユーザー 1 Google 連携まで。別の Google が既に連携済みなら、
        // まず解除してもらう（連携解除漏れによる不正な残存連携を防ぐ）。
        if ($user->socialAccounts()->where('provider', UserSocialAccount::PROVIDER_GOOGLE)->exists()) {
            return redirect()->to($back)->withErrors([
                'google' => __('messages.google.other_google_linked')
                    .__('messages.google.unlink_current_first'),
            ]);
        }

        $this->attachSocialAccount($user, $providerId, $email);

        $this->auditLogger->log(
            'auth.google.linked',
            null,
            "設定画面から Google を連携 user#{$user->getKey()}",
            $user,
        );

        return redirect()->to($back)->with('success', __('messages.google.linked'));
    }

    private function attachSocialAccount(User $user, string $providerId, string $email): void
    {
        UserSocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => UserSocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => $providerId,
            'provider_email' => $email,
        ]);
    }

    /**
     * ログインを確定し、既存の Security Pipeline へ合流させる。
     *
     * - session fixation 対策: 必ず session ID を regenerate。
     * - staff/manager/admin は MFA を bypass しない:
     *   - 確認済み TOTP あり → Fortify の 2 段階認証チャレンジへ誘導（コード入力必須）。
     *   - TOTP 未設定 → ログインは通すが EnsureStaffMfa が /admin/mfa へ隔離する。
     */
    private function loginAndContinue(Request $request, User $user): RedirectResponse
    {
        if ($user->is_active === false) {
            return $this->fail(__('messages.auth.account_disabled'));
        }

        $mfaPolicy = app(MfaPolicy::class);

        if ($mfaPolicy->isRequiredFor($user) && $mfaPolicy->hasConfirmedTotp($user)) {
            // まだ Auth::login しない。Fortify の 2FA チャレンジに委ねる。
            $request->session()->regenerate();
            $request->session()->put('login.id', $user->getKey());
            $request->session()->put('login.remember', false);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        if ($mfaPolicy->needsSetup($user)) {
            return redirect()->route('admin.mfa.show');
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended(route('home'));
    }

    /**
     * @return array{provider_user_id: string, email: string, created_at: int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get('google_oauth.pending');

        if (! is_array($pending)
            || ! isset($pending['provider_user_id'], $pending['email'], $pending['created_at'])
            || (now()->timestamp - (int) $pending['created_at']) > self::PENDING_TTL_SECONDS) {
            $request->session()->forget('google_oauth.pending');

            return null;
        }

        return [
            'provider_user_id' => (string) $pending['provider_user_id'],
            'email' => (string) $pending['email'],
            'created_at' => (int) $pending['created_at'],
        ];
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
