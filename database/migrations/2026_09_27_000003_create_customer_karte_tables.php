<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-21: 顧客カルテの分析項目（来店動機・来店目的・紹介者・都道府県・市区町村）と初診時snapshot。
 * 番地・建物名は分析データとして持たない。既存顧客・既存Visitは推測backfillしない（NULL＝未入力/未取得）。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['acquisition_channels', 'visit_purposes'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('code', 32)->unique();
                $table->string('name', 50);
                $table->boolean('is_active')->default(true);
                $table->smallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        $now = now();
        // 来店動機: 旧「顧客データ一覧」「日計表（予約媒体）」「新規統計」で使われていた値（表記の統一のみ）。
        DB::table('acquisition_channels')->insertOrIgnore(array_map(static fn (array $row, int $index): array => [
            ...$row, 'is_active' => true, 'sort_order' => ($index + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
        ], $channels = [
            ['code' => 'hotpepper', 'name' => 'ホットペッパー'],
            ['code' => 'epark', 'name' => 'EPARK'],
            ['code' => 'referral', 'name' => '紹介'],
            ['code' => 'flyer', 'name' => 'チラシ'],
            ['code' => 'website', 'name' => 'HP'],
            ['code' => 'ozmall', 'name' => 'OZmall'],
            ['code' => 'toritsu', 'name' => '都立'],
            ['code' => 'signboard', 'name' => '看板'],
            ['code' => 'other', 'name' => 'その他'],
        ], array_keys($channels)));
        // 来店目的: 旧資料で完全一致の値として繰り返し使われたものだけ。表記揺れは統合せず手動確認対象（docs参照）。
        DB::table('visit_purposes')->insertOrIgnore(array_map(static fn (array $row, int $index): array => [
            ...$row, 'is_active' => true, 'sort_order' => ($index + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
        ], $purposes = [
            ['code' => 'pain_relief', 'name' => '痛みを取りたい'],
            ['code' => 'root_cause', 'name' => '根本的に治したい'],
            ['code' => 'relaxation', 'name' => 'リラクゼーション'],
            ['code' => 'exercise', 'name' => '運動不足解消'],
            ['code' => 'other', 'name' => 'その他'],
        ], array_keys($purposes)));

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('acquisition_channel_id')->nullable()->after('note')->constrained('acquisition_channels')->restrictOnDelete();
            $table->string('acquisition_note', 100)->nullable()->after('acquisition_channel_id');
            $table->string('visit_purpose_note', 255)->nullable()->after('acquisition_note');
            $table->foreignId('referrer_customer_id')->nullable()->after('visit_purpose_note')->constrained('customers', 'user_id')->nullOnDelete();
            $table->string('referrer_name', 100)->nullable()->after('referrer_customer_id');
            $table->string('prefecture', 10)->nullable()->after('referrer_name');
            $table->string('city', 50)->nullable()->after('prefecture');
        });
        Schema::create('customer_visit_purpose', function (Blueprint $table): void {
            $table->foreignId('customer_id')->constrained('customers', 'user_id')->cascadeOnDelete();
            $table->foreignId('visit_purpose_id')->constrained('visit_purposes')->restrictOnDelete();
            $table->primary(['customer_id', 'visit_purpose_id']);
        });

        Schema::table('visits', function (Blueprint $table): void {
            // NULL=初診カルテsnapshot未取得（旧Visit・再来）。値あり=下の各列が初診完了時点の値。
            $table->dateTime('first_visit_karte_snapshot_at')->nullable()->after('first_visit_age_years_snapshot');
            $table->foreignId('first_visit_acquisition_channel_id')->nullable()->after('first_visit_karte_snapshot_at')
                ->constrained('acquisition_channels')->restrictOnDelete();
            $table->boolean('first_visit_referred')->nullable()->after('first_visit_acquisition_channel_id');
            $table->string('first_visit_prefecture', 10)->nullable()->after('first_visit_referred');
            $table->string('first_visit_city', 50)->nullable()->after('first_visit_prefecture');
        });
        Schema::create('visit_first_purposes', function (Blueprint $table): void {
            $table->foreignId('visit_id')->constrained('visits')->restrictOnDelete();
            $table->foreignId('visit_purpose_id')->constrained('visit_purposes')->restrictOnDelete();
            $table->primary(['visit_id', 'visit_purpose_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_first_purposes');
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('first_visit_acquisition_channel_id');
            $table->dropColumn(['first_visit_karte_snapshot_at', 'first_visit_referred', 'first_visit_prefecture', 'first_visit_city']);
        });
        Schema::dropIfExists('customer_visit_purpose');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acquisition_channel_id');
            $table->dropConstrainedForeignId('referrer_customer_id');
            $table->dropColumn(['acquisition_note', 'visit_purpose_note', 'referrer_name', 'prefecture', 'city']);
        });
        Schema::dropIfExists('visit_purposes');
        Schema::dropIfExists('acquisition_channels');
    }
};
