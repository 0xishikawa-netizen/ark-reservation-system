<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_size_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('captured_on')->unique();
            $table->unsignedInteger('total_mb');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_size_snapshots');
    }
};
