<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 双方向同期の競合（Phase 9）。ARK 予約も外部も silent overwrite せず、
 * needs_attention として Admin から確認できるようにする。PII snapshot 丸保存は禁止（fingerprint のみ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->string('external_reservation_id_masked', 32)->nullable();
            $table->string('conflict_type', 32);
            $table->string('ark_fingerprint', 64)->nullable();
            $table->string('external_fingerprint', 64)->nullable();
            $table->dateTime('detected_at');
            $table->string('status', 12)->default('open'); // open / resolved / ignored
            $table->string('resolution', 32)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider', 'status']);
            $table->index('reservation_id');
            $table->index(['status', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_sync_conflicts');
    }
};
