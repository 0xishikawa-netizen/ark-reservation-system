<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use App\Models\TicketTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TicketTransactionAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_transaction_can_be_created(): void
    {
        $transaction = TicketTransaction::factory()->create();

        $this->assertDatabaseHas('ticket_transactions', [
            'id' => $transaction->id,
            'dedupe_key' => $transaction->dedupe_key,
        ]);
    }

    public function test_existing_transaction_cannot_be_updated(): void
    {
        $transaction = TicketTransaction::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ticket_transactions は追記専用です。');

        $transaction->update(['reason' => '変更不可']);
    }

    public function test_existing_transaction_cannot_be_deleted(): void
    {
        $transaction = TicketTransaction::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ticket_transactions は追記専用です。');

        $transaction->delete();
    }
}
