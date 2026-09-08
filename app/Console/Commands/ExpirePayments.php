<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\ReservationCheckoutSaga;
use App\Enums\Reservation\ReservationStatus;
use App\Exceptions\Payment\PaymentGatewayException;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 支払い期限を過ぎた仮予約（pending_payment）を失効させる（PLAN §7）。
 *
 * 冪等。同じ予約を何度処理しても二重 void / 二重 slot 解放 / 二重 audit を起こさない
 * （判定と遷移は ReservationCheckoutSaga::expireReservation が担う）。
 */
class ExpirePayments extends Command
{
    protected $signature = 'payments:expire {--limit=200 : 1 回の実行で処理する最大件数}';

    protected $description = '支払い期限切れの仮予約を失効させ、枠と与信を解放する';

    public function handle(ReservationCheckoutSaga $saga): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $reservations = Reservation::query()
            ->where('status', ReservationStatus::PendingPayment->value)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('payment_expires_at')
            ->limit($limit)
            ->get();

        $expired = 0;
        $failed = 0;

        foreach ($reservations as $reservation) {
            try {
                $saga->expireReservation($reservation);
                $expired++;
            } catch (PaymentGatewayException $exception) {
                // Stripe と通信できない / 結果が曖昧。失効させず次回に持ち越す。
                // needs_attention は PaymentService 側で立っている。
                $failed++;
                Log::warning('payments:expire gateway error', [
                    'reservation_id' => $reservation->id,
                    'reason' => $exception::class,
                ]);
            } catch (Throwable $exception) {
                $failed++;
                Log::error('payments:expire failed', [
                    'reservation_id' => $reservation->id,
                    'reason' => $exception::class,
                ]);
            }
        }

        $this->info("期限切れ処理: {$expired} 件 / 失敗: {$failed} 件");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
