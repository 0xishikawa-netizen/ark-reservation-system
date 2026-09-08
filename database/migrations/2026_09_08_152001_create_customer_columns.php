<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier の billable カラム。billable は App\Models\Customer（PK: user_id）。
 *
 * - Cashier 標準の `stripe_id` カラムは追加しない。Customer モデルのアクセサ/ミューテタで
 *   既存の `customers.stripe_customer_id`（Phase 1 作成）へエイリアスする（rename しない・二重管理しない）。
 * - `pm_type` / `pm_last_four` / `trial_ends_at` は Cashier の Billable trait が参照するため customers に追加する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};
