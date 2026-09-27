<?php

declare(strict_types=1);

use App\Exceptions\Membership\InsufficientMembershipBalanceException;
use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventStaffTotpDisable;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleFortifyRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
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

        // 本当にセッションが失効していた場合（419）、Inertia の操作では英語の「Page Expired」画面を
        // モーダルで出さず、日本語の案内とともに再ログインへ導く。時間経過で意図的に失効させる仕組みはない。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() !== 419 || ! $request->hasHeader('X-Inertia')) {
                return $response;
            }
            if (Auth::check()) {
                return back()->withErrors(['session' => __('messages.auth.session_ended')]);
            }
            // POST先URLへ戻さないよう、操作していた画面を戻り先にする。
            redirect()->setIntendedUrl(url()->previous());

            return redirect()->route('login')->with('status', __('messages.auth.session_ended'));
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
