<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 「例外日」。基本シフトと異なる日だけを表現する。
     *
     * - is_off = true  … その日は休み（自動生成しない）
     * - is_off = false … 通常と異なる時間で出勤。実際の時間帯は staff_shifts（origin=manual）側に持つ。
     *
     * 例外日が存在する (staff_id, exception_date) は自動生成の対象外（手動編集を保護する）。
     */
    public function up(): void
    {
        Schema::create('staff_shift_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff', 'user_id')->cascadeOnDelete();
            $table->date('exception_date');
            $table->boolean('is_off')->default(false);
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'exception_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_shift_exceptions');
    }
};
