<?php

declare(strict_types=1);

use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventStaffTotpDisable;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StartSession;
use App\Http\Middleware\ThrottleFortifyRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware\EncryptHistory;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // fetch() の JSON を「直前の URL」に記録しない StartSession に差し替える（back() が JSON 画面へ戻る不具合の対策）。
        $middleware->web(replace: [
            FrameworkStartSession::class => StartSession::class,
        ]);
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            // Inertia がブラウザ履歴に保存するページデータを暗号化し、ログアウト時に鍵を捨てられるようにする。
            EncryptHistory::class,
            ThrottleFortifyRequests::class,
            // 業務ロールによる TOTP 無効化を止める（自己ロックアウト対策）。
            PreventStaffTotpDisable::class,
        ]);

        // Stripe webhook は署名検証で真正性を担保するため CSRF トークンを持たない。
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $renderConflict = static function (
            RuntimeException $exception,
            Request $request,
        ): JsonResponse|RedirectResponse {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => [
                        'reservation' => [$exception->getMessage()],
                    ],
                ], 409);
            }

            return back()->withErrors([
                'reservation' => $exception->getMessage(),
            ], 'reservation');
        };

        $exceptions->render(
            fn (SlotUnavailableException $exception, Request $request) => $renderConflict($exception, $request),
        );
        $exceptions->render(
            fn (StaleReservationException $exception, Request $request) => $renderConflict($exception, $request),
        );
        $exceptions->render(
            fn (InsufficientTicketBalanceException $exception, Request $request) => $renderConflict($exception, $request),
        );
        $exceptions->render(
            fn (InsufficientMembershipBalanceException $exception, Request $request) => $renderConflict($exception, $request),
        );

        // Inertia の送信で返った 419 / 429 を、英語の素のエラー画面（モーダル）ではなく日本語の案内に変える。
        // respond() は1つしか登録できない（後から登録すると上書き）ため、1つのコールバックで扱う。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->hasHeader('X-Inertia')) {
                return $response;
            }

            // 本当にセッションが失効していた場合（419）は、再ログインへ導く。時間経過で意図的に失効させる仕組みはない。
            if ($response->getStatusCode() === 419) {
                if (Auth::check()) {
                    return back()->withErrors(['session' => __('messages.auth.session_ended')]);
                }
                // POST先URLへ戻さないよう、操作していた画面を戻り先にする。
                redirect()->setIntendedUrl(url()->previous());

                return redirect()->route('login')->with('status', __('messages.auth.session_ended'));
            }

            // ログイン等の試行回数超過（429）は、送信元の画面へ戻し入力欄に残り秒数つきの案内を出す
            // （以前は素の 429 画面がモーダルで出て、フォームに案内が出なかった。全面検証 2026-09-30）。
            if ($response->getStatusCode() === 429) {
                $field = match (true) {
                    $request->routeIs('password.confirm.store') => 'password',
                    $request->routeIs('two-factor.login.store') => 'code',
                    default => 'email',
                };

                return back()->withErrors([
                    $field => __('auth.throttle', ['seconds' => (int) ($response->headers->get('Retry-After') ?? 60)]),
                ]);
            }

            return $response;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
