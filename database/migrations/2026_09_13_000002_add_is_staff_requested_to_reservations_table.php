<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「指名」（顧客が特定スタッフを明示的に希望した予約かどうか）を正式に管理する。
 * staff_id だけでは「顧客が指名した」のか「システム/店舗が割り当てた」のか区別できないため追加。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->boolean('is_staff_requested')->default(false)->after('staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('is_staff_requested');
        });
    }
};
