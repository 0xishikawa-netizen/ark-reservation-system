<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->index()->constrained('reservations')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('provider', 20)->default('stripe');
            $table->char('payment_operation_id', 36)->unique();
            $table->unsignedInteger('amount');
            $table->char('currency', 3)->default('jpy');
            $table->string('status', 24);
            $table->string('capture_method', 10)->default('manual');
            $table->string('stripe_payment_intent_id', 40)->nullable()->unique();
            $table->string('stripe_charge_id', 40)->nullable();
            $table->dateTime('authorized_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedInteger('refunded_amount')->default(0);
            $table->string('failure_code', 50)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->dateTime('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('needs_attention');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
