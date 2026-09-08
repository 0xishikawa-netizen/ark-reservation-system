<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * membership_usage_transactions（追記のみ・UPDATE/DELETE 禁止。Model のイベントで拒否）。
 * available(period) = SUM(delta) WHERE membership_id AND period_start = period。
 * held(period) = count(RESERVE) - count(RELEASE)。二重減算しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_usage_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships')->restrictOnDelete();
            $table->date('period_start'); // この行が属する期。RESERVE は予約時の期を snapshot。
            $table->string('type', 12); // GRANT / RESERVE / RELEASE / CONSUME / ADJUST。
            $table->smallInteger('delta'); // signed。GRANT +N / RESERVE -1 / RELEASE +1 / CONSUME -1 / ADJUST ±N。
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->string('dedupe_key', 100);
            $table->dateTime('created_at')->useCurrent(); // updated_at は持たない（追記専用）。

            $table->unique('dedupe_key', 'membership_usage_transactions_dedupe_key_unique');
            $table->index(['membership_id', 'period_start']);
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_usage_transactions');
    }
};
