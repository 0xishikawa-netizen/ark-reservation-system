<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('display_name', 50);
            $table->string('color', 7)->default('#888888');
            $table->boolean('is_bookable')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
