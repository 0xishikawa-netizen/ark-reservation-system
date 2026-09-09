<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 外部同期の追記専用オペレーション履歴（Phase 9）。
 * 禁止: credential / access token / raw payload / full PII / secret / card 情報。
 * 外部 ID は末尾 4 桁のみの mask 済み値。updated_at は持たない（Model で updating/deleting を拒否）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('direction', 10);   // inbound / outbound
            $table->string('operation', 12);   // create / update / cancel / fetch / reconcile
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('external_reservation_id_masked', 32)->nullable();
            $table->uuid('correlation_id');
            $table->string('idempotency_key', 120)->nullable();
            $table->string('status', 12);      // started/succeeded/no_op/skipped/failed/conflict
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('error_category', 20)->nullable();
            $table->string('safe_error_code', 80)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
            $table->index('reservation_id');
            $table->index('status');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_sync_events');
    }
};
