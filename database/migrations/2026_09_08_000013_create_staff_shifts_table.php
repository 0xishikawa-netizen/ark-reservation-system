<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff', 'user_id')->cascadeOnDelete();
            $table->date('work_date');
            $table->time('start_at');
            $table->time('end_at');
            $table->timestamps();

            $table->index(['staff_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_shifts');
    }
};
