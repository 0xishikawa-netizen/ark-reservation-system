<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Reservations;

use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Queries\ReservationListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReservationListQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_and_paginates_reservations_with_resolved_names(): void
    {
        $customer = Customer::factory()->create(['kana' => 'ヨヤク タロウ']);
        $customer->user->forceFill(['name' => '予約 太郎'])->save();
        $service = Service::factory()->create(['name' => '整体60分']);
        $staff = Staff::factory()->create(['display_name' => '担当A']);
        $target = Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 11:00:00',
            'status' => ReservationStatus::Confirmed,
            'source' => ReservationSource::Admin,
        ]);
        Reservation::factory()->create([
            'customer_id' => $customer->user_id,
            'service_id' => $service->id,
            'staff_id' => $staff->user_id,
            'booth_id' => null,
            'starts_at' => '2026-10-02 10:00:00',
            'ends_at' => '2026-10-02 11:00:00',
            'status' => ReservationStatus::Completed,
        ]);

        $result = app(ReservationListQuery::class)->paginate(
            '2026-10-01',
            (int) $staff->user_id,
            ReservationStatus::Confirmed->value,
            10,
        );
        $row = $result->items()[0];

        $this->assertSame(1, $result->total());
        $this->assertSame($target->id, $row['id']);
        $this->assertSame('予約 太郎', $row['customer_name']);
        $this->assertSame('整体60分', $row['service_name']);
        $this->assertSame('担当A', $row['staff_name']);
        $this->assertSame('confirmed', $row['status']);
        $this->assertSame('ADMIN', $row['source']);
    }
}
