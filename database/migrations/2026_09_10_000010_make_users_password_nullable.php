<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 9.6: Google のみで登録したユーザーはパスワードを持たない。
 *
 * 以前は「知らないランダムパスワード」を入れていたが、
 * 「パスワード未設定かどうか」を確実に判定できるよう NULL 許容にする。
 * これにより Google 連携解除時の自己ロックアウト判定が正確になる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NOT NULL へ戻す前に、Google のみのアカウント（password = NULL）を
        // 使用不能なランダムハッシュで埋める（rollback がスキーマ変更で失敗しないように）。
        // 当該ユーザーは以後パスワードでログインできないため、パスワード再設定が必要になる。
        DB::table('users')->whereNull('password')->update([
            'password' => Hash::make(Str::random(64)),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
