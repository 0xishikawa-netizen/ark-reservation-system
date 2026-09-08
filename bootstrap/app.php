<?php

declare(strict_types=1);

use App\Exceptions\Reservation\SlotUnavailableException;
use App\Exceptions\Reservation\StaleReservationException;
use App\Exceptions\Ticket\InsufficientTicketBalanceException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleFortifyRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            ThrottleFortifyRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $renderConflict = static function (
            \RuntimeException $exception,
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

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
