<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_tenders', function (Blueprint $table): void {
            // 日計は支払方法を限定せず、受領状態＋Asia/Tokyo営業日のUTC範囲で抽出する。
            $table->index(
                ['status', 'received_at', 'checkout_id'],
                'checkout_tenders_status_received_checkout_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('checkout_tenders', function (Blueprint $table): void {
            $table->dropIndex('checkout_tenders_status_received_checkout_index');
        });
    }
};
