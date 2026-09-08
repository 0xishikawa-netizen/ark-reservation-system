<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Enums\Ticket\TicketTransactionType;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_tables_have_the_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('ticket_products', [
            'id',
            'name',
            'total_count',
            'price',
            'validity_days',
            'is_active',
            'sort_order',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ticket_wallets', [
            'id',
            'customer_id',
            'ticket_product_id',
            'purchased_count',
            'balance',
            'expires_at',
            'status',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ticket_transactions', [
            'id',
            'ticket_wallet_id',
            'type',
            'delta',
            'reservation_id',
            'staff_id',
            'reason',
            'dedupe_key',
            'created_at',
        ]));
        $this->assertTrue(Schema::hasColumns('ticket_reservation_usages', [
            'id',
            'reservation_id',
            'ticket_wallet_id',
            'no_show_policy',
            'status',
            'held_at',
            'released_at',
            'consumed_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_ticket_transactions_does_not_have_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('ticket_transactions', 'updated_at'));
    }

    public function test_duplicate_dedupe_key_is_rejected_by_the_database(): void
    {
        $wallet = TicketWallet::factory()->create();
        $attributes = [
            'ticket_wallet_id' => $wallet->id,
            'type' => TicketTransactionType::Grant,
            'delta' => 5,
            'dedupe_key' => 'test:duplicate-dedupe-key',
        ];

        TicketTransaction::query()->create($attributes);

        try {
            TicketTransaction::query()->create($attributes);
            $this->fail('同一 dedupe_key の台帳行が重複登録されました。');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0] ?? null);
        }
    }
}
