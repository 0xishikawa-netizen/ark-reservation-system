<?php

declare(strict_types=1);

namespace App\Console\Commands\Integration;

use App\Domain\Integration\ProviderResolver;
use App\Jobs\Integration\PollExternalReservationsJob;
use Illuminate\Console\Command;

final class PollExternalReservations extends Command
{
    /** @var string */
    protected $signature = 'reservations:poll-external {--sync : キューを介さず即時実行}';

    /** @var string */
    protected $description = '有効な外部予約 Provider から直近ウィンドウの予約を取り込む（Inbound）';

    public function handle(): int
    {
        if ($this->option('sync')) {
            (new PollExternalReservationsJob)->handle(app(ProviderResolver::class));
        } else {
            PollExternalReservationsJob::dispatch();
        }

        $this->info('外部予約の取り込みをディスパッチしました。');

        return self::SUCCESS;
    }
}
