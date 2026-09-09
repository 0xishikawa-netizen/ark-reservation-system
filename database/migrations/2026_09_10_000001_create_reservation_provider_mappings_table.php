<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARK 予約 ↔ 外部予約の対応表（Phase 9）。
 * provider + external_reservation_id を 1 単位として扱う（external_reservation_id 単独を global unique にしない）。
 * 1 provider につき 1 mapping（同じ外部予約が同時 import されても reservation 1 / mapping 1 に収束）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_provider_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('external_reservation_id', 191);
            $table->string('external_customer_id', 191)->nullable();
            $table->string('fingerprint', 64)->nullable();      // 最後に反映した外部 fingerprint
            $table->dateTime('external_updated_at')->nullable(); // provider が返した更新時刻
            $table->dateTime('last_synced_at')->nullable();      // 最後に ARK へ反映した時刻
            $table->dateTime('last_seen_at')->nullable();        // 最後に fetch で観測した時刻
            $table->string('sync_status', 16)->default('in_sync'); // in_sync / drift / conflict / stale
            $table->timestamps();

            $table->unique(['provider', 'external_reservation_id'], 'rsv_map_provider_external_unique');
            $table->unique(['provider', 'reservation_id'], 'rsv_map_provider_reservation_unique');
            $table->index('reservation_id');
            $table->index(['provider', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_provider_mappings');
    }
};
