<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Domain\Integration\Service\OutboxDispatcher;
use App\Jobs\Integration\DispatchReservationOutboxJob;
use Illuminate\Console\Command;

final class DispatchReservationOutbox extends Command
{
    /** @var string */
    protected $signature = 'reservations:dispatch-outbox {--sync : キューを介さず即時実行}';

    /** @var string */
    protected $description = 'ARK → 外部同期の Outbox を送信する（Outbound）';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        if ($this->option('sync')) {
            (new DispatchReservationOutboxJob)->handle($dispatcher);
        } else {
            DispatchReservationOutboxJob::dispatch();
        }

        $this->info('Outbox の送信をディスパッチしました。');

        return self::SUCCESS;
    }
}
