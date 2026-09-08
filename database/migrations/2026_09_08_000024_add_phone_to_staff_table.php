<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS OTP を MFA フォールバックとして使うためのスタッフ電話番号。
     * 既存 PII 方針（customers.phone）に合わせ、at-rest 暗号化 + keyed HMAC 等価検索。
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            // encrypted cast の暗号文が収まるよう text（customers.phone と同じ判断）
            $table->text('phone')->nullable()->after('display_name');
            $table->char('phone_hmac', 64)->nullable()->after('phone');
            $table->dateTime('phone_verified_at')->nullable()->after('phone_hmac');

            $table->index('phone_hmac');
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->dropIndex(['phone_hmac']);
            $table->dropColumn(['phone', 'phone_hmac', 'phone_verified_at']);
        });
    }
};
