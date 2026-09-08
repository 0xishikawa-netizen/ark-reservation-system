<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id')->restrictOnDelete();
            $table->foreignId('ticket_product_id')->constrained('ticket_products')->restrictOnDelete();
            $table->unsignedSmallInteger('purchased_count');
            $table->smallInteger('balance');
            $table->date('expires_at');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('expires_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_wallets');
    }
};
