<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARK → 外部同期の Outbox（Phase 9）。
 * ARK 予約更新と同一 transaction で 1 行作成 → commit 後に Queue が拾って外部へ送る。
 * idempotency_key は「同じ業務操作」を retry しても外部 create が二重にならない安定キー。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('operation', 12);              // create / update / cancel
            $table->string('idempotency_key', 120)->unique();
            $table->json('payload_json')->nullable();     // 最小正規化 snapshot（PII なし）
            $table->string('status', 16)->default('pending'); // pending/processing/succeeded/failed/needs_attention/skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('locked_at')->nullable();
            $table->string('locked_by', 64)->nullable();
            $table->string('last_error_category', 20)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->uuid('correlation_id');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index('provider');
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_sync_outbox');
    }
};
