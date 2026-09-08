<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Enums\Reservation\ResourceType;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResourceSlotUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_resource_slot_rejects_duplicates_and_allows_distinct_keys(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL 前提');
        }

        $reservation = Reservation::factory()->create();
        $otherReservation = Reservation::factory()->create();
        $slot = [
            'resource_type' => ResourceType::Staff->value,
            'resource_id' => 123,
            'slot_start' => '2026-10-01 10:00:00',
        ];

        DB::table('reservation_resource_slots')->insert([
            ...$slot,
            'reservation_id' => $reservation->id,
        ]);

        try {
            DB::table('reservation_resource_slots')->insert([
                ...$slot,
                'reservation_id' => $otherReservation->id,
            ]);
            $this->fail('同一リソース・同一時刻のスロットが重複登録されました。');
        } catch (QueryException $exception) {
            $this->assertInstanceOf(QueryException::class, $exception);
            $this->assertSame('23000', $exception->errorInfo[0] ?? null);
        }

        DB::table('reservation_resource_slots')->insert([
            ...$slot,
            'slot_start' => '2026-10-01 10:15:00',
            'reservation_id' => $reservation->id,
        ]);
        DB::table('reservation_resource_slots')->insert([
            ...$slot,
            'resource_type' => ResourceType::Booth->value,
            'reservation_id' => $reservation->id,
        ]);

        $this->assertSame(3, DB::table('reservation_resource_slots')->count());

        $reservation->delete();

        $this->assertSame(
            0,
            DB::table('reservation_resource_slots')
                ->where('reservation_id', $reservation->id)
                ->count(),
        );
        $this->assertDatabaseHas('reservations', ['id' => $otherReservation->id]);
    }
}
