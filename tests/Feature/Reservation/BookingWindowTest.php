<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use App\Domain\Reservation\BookingWindow;
use App\Models\StoreCalendarDay;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingWindowTest extends TestCase
{
    use RefreshDatabase;

    private function window(): BookingWindow
    {
        return app(BookingWindow::class);
    }

    private function set(string $key, mixed $value, string $type): void
    {
        app(Settings::class)->set($key, $value, $type);
    }

    public function test_absent_settings_mean_no_restriction_backward_compatible(): void
    {
        $window = $this->window();

        $this->assertSame(BookingWindow::MODE_NONE, $window->horizonMode());
        $this->assertNull($window->lastBookableDate(CarbonImmutable::parse('2026-09-01')));
        $this->assertNull($window->customerRejectionReason(
            CarbonImmutable::parse('2027-05-01 10:00:00'),
            CarbonImmutable::parse('2026-09-01 09:00:00'),
        ));
    }

    public function test_monthly_before_release_day_only_opens_current_month(): void
    {
        $this->set('booking.horizon_mode', 'monthly', 'string');
        $this->set('booking.release_day_of_month', 20, 'int');

        $now = CarbonImmutable::parse('2026-09-14 09:00:00');
        $last = $this->window()->lastBookableDate($now);

        $this->assertNotNull($last);
        $this->assertSame('2026-09-30', $last->toDateString());
    }

    public function test_monthly_on_release_day_opens_through_end_of_next_month(): void
    {
        $this->set('booking.horizon_mode', 'monthly', 'string');
        $this->set('booking.release_day_of_month', 20, 'int');

        $now = CarbonImmutable::parse('2026-09-20 09:00:00');
        $last = $this->window()->lastBookableDate($now);

        $this->assertSame('2026-10-31', $last?->toDateString());
    }

    public function test_rolling_mode_opens_horizon_days_ahead(): void
    {
        $this->set('booking.horizon_mode', 'rolling', 'string');
        $this->set('booking.horizon_days', 45, 'int');

        $now = CarbonImmutable::parse('2026-09-01 09:00:00');

        $this->assertSame('2026-10-16', $this->window()->lastBookableDate($now)?->toDateString());
    }

    public function test_rejects_dates_beyond_horizon_and_before_lead_and_on_closed_days(): void
    {
        $this->set('booking.horizon_mode', 'monthly', 'string');
        $this->set('booking.release_day_of_month', 20, 'int');
        $this->set('booking.min_lead_minutes', 120, 'int');
        StoreCalendarDay::query()->create(['business_date' => '2026-09-23', 'status' => StoreCalendarDay::STATUS_CLOSED]);

        $now = CarbonImmutable::parse('2026-09-14 12:00:00');
        $window = $this->window();

        // 期間外（10月は 20 日まで開放されない）
        $this->assertNotNull($window->customerRejectionReason(
            CarbonImmutable::parse('2026-10-05 10:00:00'),
            $now,
        ));

        // 直前締切（2時間前）
        $this->assertNotNull($window->customerRejectionReason(
            CarbonImmutable::parse('2026-09-14 13:00:00'),
            $now,
        ));

        // 休業日
        $this->assertNotNull($window->customerRejectionReason(
            CarbonImmutable::parse('2026-09-23 10:00:00'),
            $now,
        ));

        // 期間内・締切前・営業日 → OK
        $this->assertNull($window->customerRejectionReason(
            CarbonImmutable::parse('2026-09-25 10:00:00'),
            $now,
        ));
    }

    public function test_assert_customer_bookable_throws_validation_exception(): void
    {
        StoreCalendarDay::query()->create(['business_date' => '2026-09-20', 'status' => StoreCalendarDay::STATUS_CLOSED]);

        $this->expectException(ValidationException::class);

        $this->window()->assertCustomerBookable(
            CarbonImmutable::parse('2026-09-20 10:00:00'),
            CarbonImmutable::parse('2026-09-14 09:00:00'),
        );
    }
}
