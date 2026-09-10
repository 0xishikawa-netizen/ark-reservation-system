<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('parent_payment_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('payments')
                ->nullOnDelete();
            $table->dateTime('payment_expires_at')
                ->nullable()
                ->after('status')
                ->index();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->unsignedInteger('final_amount')->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_payment_id');
            $table->dropIndex(['payment_expires_at']);
            $table->dropColumn('payment_expires_at');
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn('final_amount');
        });
    }
};
