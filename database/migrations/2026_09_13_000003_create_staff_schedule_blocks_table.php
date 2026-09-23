<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 予約以外（休憩・ミーティング・事務作業・清掃・研修・外出・その他）で
 * スタッフ／ブースの時間を予約不可にする「予定ブロック」。
 *
 * Reservation を顧客なしで無理やり流用せず、独立した概念として管理する。
 * staff_id / booth_id はどちらか一方必須（両方指定も許容）。アプリ層で検証する
 * （DB横断のポータビリティを優先し、CHECK制約はここでは課さない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_schedule_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->cascadeOnDelete();
            $table->foreignId('booth_id')->nullable()->constrained('booths')->cascadeOnDelete();
            $table->date('work_date');
            $table->time('start_at');
            $table->time('end_at');
            $table->string('type', 20);
            $table->string('title', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['staff_id', 'work_date']);
            $table->index(['booth_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_schedule_blocks');
    }
};
