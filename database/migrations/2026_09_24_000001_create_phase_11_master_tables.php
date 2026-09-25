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
        Schema::create('service_analysis_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('tax_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_category_id')->constrained()->restrictOnDelete();
            // 10000 = 100.00%。float を使わず basis point で保持する。
            $table->unsignedSmallInteger('rate_bps');
            $table->date('effective_from');
            // 半開区間 [effective_from, effective_to)。NULL は期限なし。
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['tax_category_id', 'effective_from']);
            $table->index(['tax_category_id', 'effective_to']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name', 100);
            $table->unsignedBigInteger('price');
            $table->foreignId('tax_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->boolean('is_enabled')->default(true);
            $table->smallInteger('display_order')->default(0);
            $table->string('external_provider', 32)->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'display_order']);
            $table->index('external_provider');
        });

        Schema::create('store_calendar_days', function (Blueprint $table): void {
            $table->id();
            $table->date('business_date')->unique();
            // 通常営業は行を作らない。closed / special_hours の例外だけを保存する。
            $table->string('status', 24);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'business_date']);
        });

        Schema::create('monthly_sales_targets', function (Blueprint $table): void {
            $table->id();
            // 月初日で保存する。月初への正規化はServiceの唯一の入口で保証する。
            $table->date('target_month')->unique();
            $table->unsignedBigInteger('target_amount');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employment_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('staff_employment_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff', 'user_id')->cascadeOnDelete();
            $table->foreignId('employment_type_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            // 半開区間 [effective_from, effective_to)。NULL は現在も有効。
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'effective_from']);
            $table->index(['staff_id', 'effective_to']);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->foreignId('analysis_category_id')
                ->nullable()
                ->after('category')
                ->constrained('service_analysis_categories')
                ->restrictOnDelete();
            $table->foreignId('tax_category_id')
                ->nullable()
                ->after('analysis_category_id')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('ticket_products', function (Blueprint $table): void {
            $table->foreignId('tax_category_id')
                ->nullable()
                ->after('price')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('membership_plans', function (Blueprint $table): void {
            $table->foreignId('tax_category_id')
                ->nullable()
                ->after('price')
                ->constrained()
                ->restrictOnDelete();
        });

        $this->migrateLegacyClosedDates();
    }

    public function down(): void
    {
        $this->restoreLegacyClosedDates();

        Schema::table('membership_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_category_id');
        });

        Schema::table('ticket_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_category_id');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_category_id');
            $table->dropConstrainedForeignId('analysis_category_id');
        });

        Schema::dropIfExists('staff_employment_periods');
        Schema::dropIfExists('employment_types');
        Schema::dropIfExists('monthly_sales_targets');
        Schema::dropIfExists('store_calendar_days');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('products');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_categories');
        Schema::dropIfExists('service_analysis_categories');
    }

    private function migrateLegacyClosedDates(): void
    {
        $setting = DB::table('settings')->where('key', 'booking.closed_dates')->first();
        $decoded = is_string($setting?->value) ? json_decode($setting->value, true) : null;

        if (! is_array($decoded)) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($decoded as $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $rows[$date] = [
                    'business_date' => $date,
                    'status' => 'closed',
                    'opens_at' => null,
                    'closes_at' => null,
                    'note' => '旧予約受付設定から移行',
                    'updated_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('store_calendar_days')->insertOrIgnore(array_values($rows));
        }
    }

    private function restoreLegacyClosedDates(): void
    {
        if (! Schema::hasTable('store_calendar_days')) {
            return;
        }

        $dates = DB::table('store_calendar_days')
            ->where('status', 'closed')
            ->orderBy('business_date')
            ->pluck('business_date')
            ->map(static fn (mixed $date): string => (string) $date)
            ->all();

        DB::table('settings')->updateOrInsert(
            ['key' => 'booking.closed_dates'],
            [
                'value' => json_encode($dates, JSON_THROW_ON_ERROR),
                'type' => 'json',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
