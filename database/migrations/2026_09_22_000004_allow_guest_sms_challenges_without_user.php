<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mfa_sms_challenges', function (Blueprint $table): void {
            // 予約検索は存在確認前に同じ応答で送るため、User に紐づけない。
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('mfa_sms_challenges')->whereNull('user_id')->delete();

        Schema::table('mfa_sms_challenges', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
