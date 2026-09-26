<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 11-19: 来店を伴わない店頭会計と、スタッフ単位の指名snapshotを追加する。
 * 既存のVisit会計（visit_id必須で作成済みの行）の意味は変えない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->foreignId('visit_id')->nullable()->change();
            // 来店なし会計の購入者（匿名の物販はNULL）と売上日。来店会計ではvisitが正本なのでNULLのまま。
            $table->foreignId('customer_id')->nullable()->after('visit_id')
                ->constrained('customers', 'user_id')->restrictOnDelete();
            $table->date('sale_date')->nullable()->after('customer_id');
            $table->index(['sale_date', 'status']);
        });

        Schema::create('visit_staff_nominations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->string('staff_name_snapshot', 100)->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'staff_id']);
            $table->index('staff_id');
        });

        Schema::table('visits', function (Blueprint $table): void {
            // NULL=指名の有無が未記録（旧Visit）。値あり=visit_staff_nominationsが指名の正本。
            $table->dateTime('nominations_recorded_at')->nullable()->after('requested_staff_id_at_checkout');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn('nominations_recorded_at');
        });
        Schema::dropIfExists('visit_staff_nominations');
        Schema::table('checkouts', function (Blueprint $table): void {
            $table->dropIndex(['sale_date', 'status']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('sale_date');
        });
        // 来店なし会計が存在する場合にvisit_idをNOT NULLへ戻すとデータを失うため、戻さない。
    }
};
