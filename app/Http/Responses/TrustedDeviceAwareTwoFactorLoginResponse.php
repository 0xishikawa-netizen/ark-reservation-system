<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domain\Auth\TrustedDeviceService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify 標準の TwoFactorLoginResponse を差し替え、
 * TOTP チャレンジ通過時に「この端末を信頼する」が選択されていれば
 * {@see TrustedDeviceService} で端末を記憶する。
 */
final class TrustedDeviceAwareTwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private readonly TrustedDeviceService $trustedDevices) {}

    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $user = $request->user();

        if ($user instanceof User && $request->boolean('trust_device')) {
            $this->trustedDevices->remember($user, $request);
        }

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended(Fortify::redirects('login'));
    }
}
