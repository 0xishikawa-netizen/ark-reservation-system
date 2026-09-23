<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 勤務枠の由来を区別する。
     *
     * - manual   … 管理者が直接作成した枠 / 例外日の枠。自動生成が絶対に触らない。
     * - template … 基本シフトから自動生成した枠。再生成時に安全に扱える。
     *
     * 既存行はすべて 'manual' 扱い（default）。これにより
     * migration 適用だけで既存の勤務枠・未来予約に影響が出ない。
     */
    public function up(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table): void {
            $table->string('origin', 16)->default('manual')->after('end_at');
            $table->index(['work_date', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table): void {
            $table->dropIndex(['work_date', 'origin']);
            $table->dropColumn('origin');
        });
    }
};
