<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * memberships（業務 SoR）。Cashier の subscriptions（課金契約の記録）とは別物。
 * 予約可否は memberships.status と period_available だけで判定し、Stripe subscription status を
 * 業務ロジックで直接参照しない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers', 'user_id')->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->string('stripe_subscription_id', 40)->nullable()->unique();
            // subscription create の安定 idempotency の根。DB 先行保存・プロセス内で再生成しない。
            $table->char('membership_operation_id', 36)->unique();
            // 進行中の論理操作（曖昧結果の収束用）: create / cancel / resume / cancel_now。
            $table->string('pending_operation', 20)->nullable();
            $table->string('status', 16); // pending/active/grace/canceling/paused/canceled（MembershipStateMachine）。
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('grace_until')->nullable();
            $table->smallInteger('period_available')->default(0); // derived cache。SoR は台帳。
            $table->dateTime('started_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('status');
            $table->index('current_period_end');
            $table->index('needs_attention');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
