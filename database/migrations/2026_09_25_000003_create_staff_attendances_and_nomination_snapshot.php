<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff', 'user_id')->restrictOnDelete();
            $table->date('business_date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            // 分割勤務を許容する。日時はUTC、business_dateはJSTの出勤日。
            $table->index(['staff_id', 'business_date']);
            $table->index(['clock_in_at', 'clock_out_at']);
        });

        Schema::create('staff_attendance_breaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_attendance_id')->constrained('staff_attendances')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('type', 24)->default('break');
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['staff_attendance_id', 'start_at']);
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->boolean('staff_requested_at_checkout')->nullable()->after('future_reservation_snapshot_at');
            $table->foreignId('requested_staff_id_at_checkout')->nullable()->after('staff_requested_at_checkout');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn(['staff_requested_at_checkout', 'requested_staff_id_at_checkout']);
        });
        Schema::dropIfExists('staff_attendance_breaks');
        Schema::dropIfExists('staff_attendances');
    }
};
