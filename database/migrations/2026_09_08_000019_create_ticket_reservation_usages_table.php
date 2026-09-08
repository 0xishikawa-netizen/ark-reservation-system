<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_reservation_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('ticket_wallet_id')->constrained('ticket_wallets')->restrictOnDelete();
            $table->string('no_show_policy', 16);
            $table->string('status', 16)->default('held');
            $table->dateTime('held_at');
            $table->dateTime('released_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();

            $table->index('ticket_wallet_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reservation_usages');
    }
};
