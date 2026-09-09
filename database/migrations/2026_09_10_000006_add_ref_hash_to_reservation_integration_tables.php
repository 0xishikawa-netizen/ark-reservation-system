<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 表示用 mask（末尾4桁）とは別に、衝突しない識別ハッシュを持つ（Phase 9 Red Team F-16）。
 * conflict の同一視・並行作成の収束に使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_sync_conflicts', function (Blueprint $table): void {
            $table->string('external_ref_hash', 64)->nullable()->after('external_reservation_id_masked');
            $table->index(['provider', 'external_ref_hash']);
        });

        Schema::table('reservation_provider_mappings', function (Blueprint $table): void {
            // 順序比較の材料（Provider が返せば保存・単調増加検証に使う）。
            $table->string('external_version', 64)->nullable()->after('external_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_sync_conflicts', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'external_ref_hash']);
            $table->dropColumn('external_ref_hash');
        });
        Schema::table('reservation_provider_mappings', function (Blueprint $table): void {
            $table->dropColumn('external_version');
        });
    }
};
