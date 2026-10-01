<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            // 担当スタッフの性別希望（male / female）。指名とは別に「男性スタッフ希望」「女性スタッフ希望」を残す。
            $table->string('staff_gender_preference', 10)->nullable()->after('is_staff_requested');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('staff_gender_preference');
        });
    }
};
