<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-27: 会計を作らずに来店完了した理由（無料・事前決済済み・回数券/月額利用）。
 * NULL は「通常どおり会計で確定する来店」。既存行は推測で埋めない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('checkout_exemption_reason', 32)->nullable()->after('completion_operation_id');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn('checkout_exemption_reason');
        });
    }
};
