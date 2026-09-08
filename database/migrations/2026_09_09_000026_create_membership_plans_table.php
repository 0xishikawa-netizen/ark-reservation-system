<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('price'); // 円（JPY・小数なし）。表示用。
            $table->unsignedSmallInteger('usage_count_per_period');
            $table->string('billing_interval', 10)->default('month'); // Phase 6 は month のみ。
            $table->string('stripe_price_id', 40); // Test Mode の price のみ（price_...）。
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
