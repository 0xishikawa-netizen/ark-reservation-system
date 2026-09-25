<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_metric_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_metric_value_id')->unique()->constrained('historical_metric_values')->restrictOnDelete();
            $table->string('difference_category', 32);
            $table->string('review_status', 24);
            $table->string('reason', 1000);
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_metric_reviews');
    }
};
