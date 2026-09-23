<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 会員番号（member_no）を正式なDB項目にする。
 *
 * 「顧客IDをゼロ埋めして画面表示するだけ」の代替実装を廃止し、
 * `ARK` + 6桁ゼロ埋めの実カラムとして持たせる（DB内部ID＝user_idとは別概念）。
 * 同時登録時の採番も、既に一意性が保証された user_id（AUTO_INCREMENT）から
 * 生成するため、追加の採番テーブルなしに安全。値は Customer モデルの
 * creating フックで一度だけ設定し、以後変更しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->char('member_no', 10)->nullable()->after('user_id');
        });

        // 既存顧客を安全にbackfill（顧客IDをそのまま画面に出すのではなく、正式なmember_noとして生成）。
        DB::table('customers')->orderBy('user_id')->select(['user_id'])->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('customers')
                    ->where('user_id', $row->user_id)
                    ->update(['member_no' => 'ARK'.str_pad((string) $row->user_id, 6, '0', STR_PAD_LEFT)]);
            }
        }, 'user_id');

        Schema::table('customers', function (Blueprint $table): void {
            $table->char('member_no', 10)->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->unique('member_no');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['member_no']);
            $table->dropColumn('member_no');
        });
    }
};
