<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * membership_reservation_usages（1 予約 = 1 行）。
 * RESERVE 時に period_start と no_show_policy を snapshot し、設定変更・期ロールオーバーの
 * 遡及を構造的に防ぐ（Phase 4 の ticket_reservation_usages と同型）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_reservation_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->date('period_start'); // RESERVE 時の期。期またぎ判定に使う。
            $table->string('no_show_policy', 12); // RESERVE 時 snapshot: consume / restore。
            $table->string('status', 12)->default('reserved'); // reserved / released / consumed。
            $table->dateTime('reserved_at');
            $table->dateTime('released_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();

            $table->index('membership_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_reservation_usages');
    }
};
