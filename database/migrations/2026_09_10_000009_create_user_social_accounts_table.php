<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.6: ソーシャルログイン（外部 IdP）の identity マッピング。
 *
 * users テーブルに provider ごとの列を増やし続けない。将来 Apple / LINE 等を
 * 追加しても行を増やすだけで済む構造にする。今回実装するのは Google のみ。
 *
 * - `UNIQUE(provider, provider_user_id)`: 同一 IdP アカウントを複数 ARK ユーザーへ
 *   紐付けさせない（provider id collision 対策）。email は identity key にしない。
 * - OAuth の access/refresh token は保存しない（ログイン用途のみ。§23）。
 * - provider_email は「連携時点で IdP が返した検証済みメール」の記録用（照合には使わない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('provider_email', 255)->nullable();
            $table->timestamps();

            // 同一 IdP アカウントを複数 ARK ユーザーへ紐付けさせない。
            $table->unique(['provider', 'provider_user_id']);
            // 1 ユーザーにつき 1 provider 1 連携まで（複数 Google の同時連携を防ぐ。
            // 解除漏れによる不正な残存連携＝乗っ取り継続を構造的に排除する）。
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
    }
};
