<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 施術後の着替え・片付けのために、予約の後ろへ確保する余白（分）。
 * ends_at にはこの分も含めて保存するため、重複チェック・空き枠計算は既存ロジックのまま
 * 自動的にバッファを考慮する。duration_min とは別に持つのは、実際の施術時間と
 * 余白を後から区別して集計・表示できるようにするため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('buffer_min')->default(0)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('buffer_min');
        });
    }
};
