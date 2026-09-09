<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider ごとの最終同期時刻・polling カーソル（Phase 9）。
 * Scheduler が止まっていても Admin から「いつ最後に同期したか」を確認できるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_provider_sync_state', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->dateTime('last_inbound_at')->nullable();
            $table->string('last_inbound_cursor', 191)->nullable();
            $table->dateTime('last_outbound_at')->nullable();
            $table->dateTime('last_reconcile_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_provider_sync_state');
    }
};
