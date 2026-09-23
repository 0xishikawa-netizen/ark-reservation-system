<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 予約台帳のオンライン予約通知（§34-37）の既読状態。
     * 管理者ごとに「×で閉じた予約」を記録し、同じ通知が再表示されないようにする。
     * localStorage だけに依存しない（別端末・別ブラウザでも既読が引き継がれる）。
     */
    public function up(): void
    {
        Schema::create('reservation_notification_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->timestamp('dismissed_at');

            // MySQL の識別子は 64 文字まで。デフォルト命名は超えるため短い名前を明示する。
            $table->unique(['user_id', 'reservation_id'], 'rnd_user_reservation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_notification_dismissals');
    }
};
