<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->index()->constrained('payments')->restrictOnDelete();
            $table->char('refund_operation_id', 36)->unique();
            $table->unsignedInteger('amount');
            $table->string('reason', 255);
            $table->string('status', 16);
            $table->string('stripe_refund_id', 40)->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
