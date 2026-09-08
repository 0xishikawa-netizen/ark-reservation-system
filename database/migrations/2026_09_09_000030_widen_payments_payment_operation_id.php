<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments.payment_operation_id を char(36)（UUID 専用）→ varchar(64) へ拡張する。
 * Membership invoice の決定的 operation ID `inv:{stripe_invoice_id}` は 36 字に収まらないため。
 * UNIQUE / NOT NULL は維持（Phase 5 の UUID 冪等性は不変。nullable へ緩めない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_operation_id', 64)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->char('payment_operation_id', 36)->nullable(false)->change();
        });
    }
};
