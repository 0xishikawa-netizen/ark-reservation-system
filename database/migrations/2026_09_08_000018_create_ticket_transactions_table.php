<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_wallet_id')->constrained('ticket_wallets')->restrictOnDelete();
            $table->string('type', 16);
            $table->smallInteger('delta');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->string('dedupe_key', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('dedupe_key', 'ticket_transactions_dedupe_key_unique');
            $table->index(['ticket_wallet_id', 'id']);
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_transactions');
    }
};
