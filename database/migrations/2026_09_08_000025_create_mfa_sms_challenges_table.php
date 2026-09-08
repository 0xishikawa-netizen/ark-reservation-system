<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS OTP チャレンジ。
     *
     * **OTP の平文は保存しない**（code_hash のみ）。
     * 送信先も平文ではなく phone_hmac で保持する。
     */
    public function up(): void
    {
        Schema::create('mfa_sms_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->char('phone_hmac', 64);
            $table->string('code_hash', 255);
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('used_at')->nullable();
            $table->dateTime('sent_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'expires_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_sms_challenges');
    }
};
