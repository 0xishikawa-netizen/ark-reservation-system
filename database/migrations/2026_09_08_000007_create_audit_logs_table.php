<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 60);
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('summary', 500);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index('actor_user_id');
            $table->index(['entity_type', 'entity_id']);
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
