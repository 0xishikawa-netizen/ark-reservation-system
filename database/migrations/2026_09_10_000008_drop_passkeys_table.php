<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.6: Passkey（WebAuthn）機能を撤去する。
 *
 * 2026_09_08_000023_create_passkeys_table.php（履歴）は書き換えず、
 * ここで新規にテーブルを落とす。本番運用前であり、Passkey の資格情報は
 * 保持しない方針（feature 完全廃止）。
 *
 * laravel/passkeys パッケージ自体は laravel/fortify の依存として残る
 * （単体では利用しないため composer からは外せない）。ルート・UI・
 * モデル参照はアプリ側から全て除去済み。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('passkeys');
    }

    public function down(): void
    {
        // ロールバック時は元の schema を復元する（create 履歴と同一）。
        Schema::create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }
};
