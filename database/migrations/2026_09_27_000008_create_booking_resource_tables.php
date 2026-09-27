<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-28: 予約リソース（メニュー×ブース、スタッフの資格、メニューが必要とする資格）。
 *
 * - booth_service: メニューで使える具体的なブース（多対多）。1件も無いメニューは従来どおり全有効ブースが候補。
 * - qualifications: 資格マスタ（はり師など）。名前でコードにハードコードしない。
 * - qualification_staff: スタッフが保有する資格。
 * - qualification_service: メニューの施術に必要な資格。全部を保有するスタッフだけが担当できる。
 * 施術可能スタッフは既存 service_staff をそのまま使う（重複テーブルを作らない）。
 * 既存データへの推測 backfill はしない（紐付けは画面で明示設定する）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booth_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booth_id')->constrained('booths')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['service_id', 'booth_id']);
            $table->index('booth_id');
        });

        Schema::create('qualifications', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('qualification_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qualification_id')->constrained('qualifications')->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('staff', 'user_id')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['staff_id', 'qualification_id']);
            $table->index('qualification_id');
        });

        Schema::create('qualification_service', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qualification_id')->constrained('qualifications')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['service_id', 'qualification_id']);
            $table->index('qualification_id');
        });

        // 資格マスタの選択肢だけ用意する（誰が保有するか・どのメニューに必要かは推測で埋めない）。
        $now = now();
        DB::table('qualifications')->insertOrIgnore([
            ['code' => 'acupuncturist', 'name' => 'はり師', 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('qualification_service');
        Schema::dropIfExists('qualification_staff');
        Schema::dropIfExists('qualifications');
        Schema::dropIfExists('booth_service');
    }
};
