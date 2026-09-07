<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware();

        // Laravel は testing 環境で CSRF 検証を省略するため、このクラスだけ実環境相当の分岐を通す。
        $this->app->instance('env', 'csrf-testing');
    }

    public function test_post_without_csrf_token_is_rejected(): void
    {
        $this->post('/logout')->assertStatus(419);
    }

    public function test_post_with_valid_csrf_token_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession([]);

        $this->post('/logout', [
            '_token' => Session::token(),
        ])->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_get_request_does_not_require_csrf_token(): void
    {
        $this->get('/login')->assertOk();
    }
}
