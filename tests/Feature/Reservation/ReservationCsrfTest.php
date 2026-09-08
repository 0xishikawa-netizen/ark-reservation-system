<?php

declare(strict_types=1);

namespace Tests\Feature\Reservation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReservationCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware();

        // testing 環境の CSRF 自動無効化を、このクラスだけ実環境相当へ戻す。
        $this->app->instance('env', 'csrf-testing');
    }

    public function test_customer_reservation_post_without_csrf_token_is_rejected(): void
    {
        $this->post('/reserve')->assertStatus(419);
    }

    public function test_admin_reservation_post_without_csrf_token_is_rejected(): void
    {
        $this->post('/admin/reservations')->assertStatus(419);
    }
}
