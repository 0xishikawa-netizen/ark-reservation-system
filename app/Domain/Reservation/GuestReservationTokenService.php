<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Models\Reservation;
use App\Models\ReservationGuestToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 未ログイン予約の確認リンク用トークンを発行・検証する。
 *
 * selector は検索用に公開し、validator は URL にだけ含めて DB にはハッシュを保存する。
 */
final class GuestReservationTokenService
{
    public function issue(Reservation $reservation): string
    {
        $selector = Str::random(24);
        $validator = Str::random(40);
        $reservationDate = CarbonImmutable::instance($reservation->ends_at);

        ReservationGuestToken::query()->create([
            'reservation_id' => $reservation->getKey(),
            'selector' => $selector,
            'token_hash' => Hash::make($validator),
            'last_used_at' => null,
            // 来店後も確認できるよう予約終了から1年間有効にし、過去日時でも即失効させない。
            'expires_at' => $reservationDate->max(CarbonImmutable::now())->addYear(),
        ]);

        return $selector.'.'.$validator;
    }

    public function resolve(string $selector, string $validator): ?Reservation
    {
        $token = ReservationGuestToken::query()
            ->where('selector', $selector)
            ->first();

        if ($token === null || $token->expires_at->isPast()) {
            return null;
        }

        if (! Hash::check($validator, $token->token_hash)) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->reservation;
    }
}
