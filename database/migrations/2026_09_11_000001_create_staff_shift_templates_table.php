<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * スタッフの「基本シフト」（曜日ごとの通常勤務時間）。
     * ここから予約受付期間分の staff_shifts を自動生成する（origin=template）。
     * 同一曜日に複数レコード = 複数時間帯（例 10:00-13:00 / 15:00-19:00）。
     */
    public function up(): void
    {
        Schema::create('staff_shift_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff', 'user_id')->cascadeOnDelete();
            // 0=日曜 ... 6=土曜（Carbon::dayOfWeek と一致）。
            $table->unsignedTinyInteger('weekday');
            $table->time('start_at');
            $table->time('end_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['staff_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_shift_templates');
    }
};
