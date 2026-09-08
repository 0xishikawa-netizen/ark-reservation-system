<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id', 64)->unique();
            $table->string('type', 60);
            $table->string('api_version', 20)->nullable();
            $table->string('status', 16);
            $table->string('related_type', 60)->nullable();
            $table->string('related_id', 40)->nullable();
            $table->dateTime('event_created_at')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
