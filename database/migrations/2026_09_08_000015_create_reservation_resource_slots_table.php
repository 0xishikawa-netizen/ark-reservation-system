<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_resource_slots', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_type', 8);
            $table->unsignedBigInteger('resource_id');
            $table->dateTime('slot_start');
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['resource_type', 'resource_id', 'slot_start'],
                'reservation_resource_slot_unique',
            );
            $table->index('reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_resource_slots');
    }
};
