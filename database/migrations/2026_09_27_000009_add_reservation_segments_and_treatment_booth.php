<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-29: 予約商品と実施施術の分離・延長・終了後インターバル。
 *
 * - reservation_segments: 予約時点の予定構成（延長で追加した施術など）。予約メニューの時間とは別に持つ。
 *   実績の正本は visit_treatments（来店・会計で確定）。予約を後から変えても完了済みの実績は変わらない。
 * - visit_treatments.booth_id: 実際に使ったブース（任意）。
 * - reservations.buffer_min: 意味を明記する（予約終了後に確保するインターバル。開始を後ろへずらすものではない）。
 * 既存データは変更しない（推測 backfill なし）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->string('kind', 16)->default('extension');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reservation_id', 'sort_order']);
        });

        Schema::table('visit_treatments', function (Blueprint $table): void {
            $table->foreignId('booth_id')->nullable()->after('analysis_category_id')->constrained('booths')->nullOnDelete();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('buffer_min')->default(0)
                ->comment('予約終了後のインターバル（分）。ends_at はこれを含む占有終了。予約時間は starts_at〜ends_at−buffer_min')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('visit_treatments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('booth_id');
        });
        Schema::dropIfExists('reservation_segments');
    }
};
