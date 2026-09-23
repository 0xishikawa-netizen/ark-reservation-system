<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Actions\Customer\CreateProvisionalCustomer;
use App\Models\User;
use App\Support\Security\PiiHasher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GuestCustomerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_customer_can_be_created_with_a_real_email(): void
    {
        $customer = app(CreateProvisionalCustomer::class)->executeGuest([
            'name' => '予約 花子',
            'phone' => '090-1234-5678',
            'email' => 'Guest@example.com',
        ]);

        $this->assertSame('guest@example.com', $customer->user->email);
        $this->assertNull($customer->user->password);
        $this->assertSame('web', $customer->created_via);
        $this->assertSame('090-1234-5678', $customer->phone);
        $this->assertSame(PiiHasher::phoneHmac('090-1234-5678'), $customer->phone_hmac);
        $this->assertTrue($customer->user->hasRole('customer'));
    }

    public function test_guest_customer_without_email_uses_a_unique_invalid_placeholder(): void
    {
        $customer = app(CreateProvisionalCustomer::class)->executeGuest([
            'name' => '予約 太郎',
            'phone' => '08012345678',
        ]);

        $this->assertMatchesRegularExpression(
            '/\Aprovisional\+[0-9a-z]+@ark\.invalid\z/',
            $customer->user->email,
        );
        $this->assertNull($customer->user->password);
        $this->assertSame('web', $customer->created_via);
    }

    public function test_existing_member_email_is_rejected_as_a_validation_error(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        try {
            app(CreateProvisionalCustomer::class)->executeGuest([
                'name' => '重複 顧客',
                'phone' => '07012345678',
                'email' => 'member@example.com',
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'このメールアドレスは登録済みです。ログインして予約してください。',
                $exception->errors()['email'][0],
            );
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 0);
    }
}
