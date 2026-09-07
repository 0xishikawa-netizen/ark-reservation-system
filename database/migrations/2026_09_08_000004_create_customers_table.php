<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('kana', 100);
            $table->text('phone')->nullable();
            $table->char('phone_hmac', 64)->nullable()->index();
            $table->text('birthday')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('note', 1000)->nullable();
            $table->string('stripe_customer_id', 40)->nullable()->index();
            $table->string('created_via', 20)->default('web');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
