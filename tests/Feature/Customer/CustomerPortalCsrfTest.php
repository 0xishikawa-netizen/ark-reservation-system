<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portal の状態変更 POST が CSRF トークン無しで拒否される（419）ことを確認する。
 * ReservationCsrfTest と同じ手法（このクラスだけ CSRF ミドルウェアを実環境相当に戻す）。
 */
final class CustomerPortalCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withMiddleware();
        $this->app->instance('env', 'csrf-testing');
    }

    public function test_membership_cancel_post_without_csrf_token_is_rejected(): void
    {
        $this->post('/mypage/membership/cancel')->assertStatus(419);
    }

    public function test_membership_subscribe_post_without_csrf_token_is_rejected(): void
    {
        $this->post('/mypage/membership/subscribe')->assertStatus(419);
    }

    public function test_reservation_cancel_delete_without_csrf_token_is_rejected(): void
    {
        $this->delete('/mypage/reservations/1')->assertStatus(419);
    }
}
