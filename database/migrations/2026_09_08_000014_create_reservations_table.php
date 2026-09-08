<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->foreignId('booth_id')->nullable()->constrained('booths')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('source', 20);
            $table->string('payment_method', 20);
            $table->string('payment_status', 20)->default('unpaid');
            $table->dateTime('payment_expires_at')->nullable();
            $table->string('status', 24);
            $table->dateTime('attended_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('external_provider', 20)->nullable();
            $table->string('external_reservation_id', 64)->nullable();
            $table->string('sync_status', 16)->default('NOT_REQUIRED');
            $table->dateTime('synced_at')->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->integer('version')->default(0);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('starts_at');
            $table->index(['staff_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
            $table->index('status');
            $table->index('payment_status');
            $table->index('payment_expires_at');
            $table->unique(['external_provider', 'external_reservation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
