<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id')->restrictOnDelete();
            // 予約なし来店を許容する一方、1予約から複数来店が作られることはDBで防ぐ。
            $table->foreignId('reservation_id')->nullable()->unique()->constrained('reservations')->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 16)->default('draft');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('primary_staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->string('primary_staff_name_snapshot', 100)->nullable();
            // 初回・再診・到達率の確定根拠。完了処理で採番し、未知の過去値はNULLのまま扱う。
            $table->unsignedInteger('visit_sequence')->nullable();
            $table->boolean('future_reservation_exists_at_checkout')->nullable();
            $table->dateTime('future_reservation_snapshot_at')->nullable();
            $table->char('completion_operation_id', 36)->nullable()->unique();
            $table->timestamps();

            $table->unique(['customer_id', 'visit_sequence']);
            $table->index(['business_date', 'status']);
            $table->index(['customer_id', 'business_date']);
            $table->index(['primary_staff_id', 'business_date']);
            $table->index('completed_at');
        });

        Schema::create('visit_treatments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignId('analysis_category_id')->nullable()->constrained('service_analysis_categories')->restrictOnDelete();
            $table->string('service_name_snapshot', 100)->nullable();
            $table->string('analysis_category_code_snapshot', 20)->nullable();
            $table->string('analysis_category_name_snapshot', 100)->nullable();
            $table->string('status', 16)->default('draft');
            $table->dateTime('actual_started_at')->nullable();
            $table->dateTime('actual_ended_at')->nullable();
            $table->unsignedSmallInteger('actual_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('operation_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['visit_id', 'sort_order']);
            $table->index(['service_id', 'actual_started_at']);
        });

        Schema::create('visit_treatment_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_treatment_id')->constrained('visit_treatments')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->string('staff_name_snapshot', 100)->nullable();
            $table->dateTime('actual_started_at')->nullable();
            $table->dateTime('actual_ended_at')->nullable();
            $table->unsignedSmallInteger('actual_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['visit_treatment_id', 'staff_id']);
            $table->index(['staff_id', 'actual_started_at']);
        });

        Schema::create('checkouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('visits')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('subtotal_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->char('currency', 3)->default('jpy');
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->char('operation_id', 36)->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'finalized_at']);
        });

        Schema::create('checkout_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_id')->constrained('checkouts')->restrictOnDelete();
            $table->string('item_type', 24);
            $table->foreignId('visit_treatment_id')->nullable()->constrained('visit_treatments')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('ticket_product_id')->nullable()->constrained('ticket_products')->restrictOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->restrictOnDelete();
            $table->string('item_name_snapshot', 100);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount');
            $table->foreignId('tax_category_id')->nullable()->constrained('tax_categories')->restrictOnDelete();
            $table->string('tax_category_code_snapshot', 32)->nullable();
            $table->string('tax_category_name_snapshot', 100)->nullable();
            $table->unsignedSmallInteger('tax_rate_bps')->nullable();
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('tax_amount');
            $table->unsignedBigInteger('gross_amount');
            $table->boolean('is_staff_allocatable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('operation_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['checkout_id', 'sort_order']);
            $table->index(['item_type', 'service_id']);
        });

        Schema::create('checkout_tenders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_id')->constrained('checkouts')->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 16)->default('received');
            $table->string('external_reference', 100)->nullable();
            $table->dateTime('received_at')->nullable();
            $table->string('operation_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['checkout_id', 'status']);
            $table->index(['payment_method_id', 'received_at']);
        });

        Schema::create('staff_revenue_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkout_line_id')->constrained('checkout_lines')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff', 'user_id')->nullOnDelete();
            $table->foreignId('visit_treatment_staff_id')->nullable()->constrained('visit_treatment_staff')->restrictOnDelete();
            $table->string('staff_name_snapshot', 100)->nullable();
            $table->unsignedSmallInteger('basis_minutes')->nullable();
            $table->unsignedBigInteger('allocated_amount');
            $table->string('operation_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->unique(['checkout_line_id', 'staff_id']);
            $table->index(['staff_id', 'created_at']);
        });

        // 回数券・月額の権利は既存テーブルをSoRとし、ここには売上配賦の契約額snapshotだけを持つ。
        Schema::create('revenue_recognition_contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->foreignId('ticket_wallet_id')->nullable()->constrained('ticket_wallets')->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignId('source_checkout_line_id')->nullable()->constrained('checkout_lines')->restrictOnDelete();
            $table->foreignId('source_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('contract_amount');
            $table->unsignedInteger('units')->nullable();
            $table->char('currency', 3)->default('jpy');
            $table->string('status', 16)->default('draft');
            $table->string('operation_key', 100)->unique();
            $table->timestamps();

            $table->unique('ticket_wallet_id');
            $table->unique(['membership_id', 'period_start']);
            $table->index(['kind', 'status']);
        });

        Schema::create('revenue_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revenue_recognition_contract_id')->constrained('revenue_recognition_contracts')->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $table->foreignId('visit_treatment_id')->nullable()->constrained('visit_treatments')->restrictOnDelete();
            $table->foreignId('ticket_reservation_usage_id')->nullable()->unique()->constrained('ticket_reservation_usages')->restrictOnDelete();
            $table->foreignId('membership_reservation_usage_id')->nullable()->unique()->constrained('membership_reservation_usages')->restrictOnDelete();
            $table->date('recognized_on');
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('allocation_no');
            $table->boolean('is_remainder')->default(false);
            $table->string('operation_key', 100)->unique();
            $table->timestamps();

            $table->unique(['revenue_recognition_contract_id', 'allocation_no'], 'revenue_allocations_contract_no_unique');
            $table->index(['recognized_on', 'revenue_recognition_contract_id'], 'revenue_allocations_date_contract_index');
        });

        // 行内で完結する不変条件はDBにも持たせ、複数行合計だけをServiceへ委ねる。
        DB::statement('ALTER TABLE visit_treatments ADD CONSTRAINT chk_visit_treatments_minutes CHECK (actual_minutes IS NULL OR actual_minutes > 0)');
        DB::statement('ALTER TABLE visit_treatment_staff ADD CONSTRAINT chk_visit_staff_minutes CHECK (actual_minutes IS NULL OR actual_minutes > 0)');
        DB::statement('ALTER TABLE checkout_lines ADD CONSTRAINT chk_checkout_lines_quantity CHECK (quantity > 0)');
        DB::statement('ALTER TABLE checkout_lines ADD CONSTRAINT chk_checkout_lines_amounts CHECK (net_amount + tax_amount = gross_amount)');
        DB::statement('ALTER TABLE checkout_tenders ADD CONSTRAINT chk_checkout_tenders_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_allocations');
        Schema::dropIfExists('revenue_recognition_contracts');
        Schema::dropIfExists('staff_revenue_allocations');
        Schema::dropIfExists('checkout_tenders');
        Schema::dropIfExists('checkout_lines');
        Schema::dropIfExists('checkouts');
        Schema::dropIfExists('visit_treatment_staff');
        Schema::dropIfExists('visit_treatments');
        Schema::dropIfExists('visits');
    }
};
