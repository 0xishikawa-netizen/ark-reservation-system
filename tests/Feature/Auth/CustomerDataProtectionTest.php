<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\User;
use App\Support\Security\PiiHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CustomerDataProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_is_encrypted_and_its_normalized_hmac_is_synchronized(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'kana' => 'テスト タロウ',
            'phone' => '090-1234-5678',
            'birthday' => '1990-01-02',
        ]);

        $stored = DB::table('customers')->where('user_id', $user->id)->first();
        $fresh = $customer->fresh();

        $this->assertNotNull($stored);
        $this->assertNotNull($fresh);
        $this->assertNotSame('090-1234-5678', $stored->phone);
        $this->assertNotSame('1990-01-02', $stored->birthday);
        $this->assertSame(
            PiiHasher::phoneHmac('090-1234-5678'),
            $stored->phone_hmac,
        );
        $this->assertSame('090-1234-5678', $fresh->phone);
        $this->assertSame('1990-01-02', $fresh->birthday?->toDateString());

        $customer->phone = null;
        $customer->save();

        $this->assertNull($customer->fresh()?->phone_hmac);
    }

    public function test_phone_hmac_throws_when_pii_lookup_key_is_missing(): void
    {
        config(['security.pii_lookup_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PII_LOOKUP_KEY が未設定です。config/security.php を確認してください。');

        PiiHasher::phoneHmac('090-1234-5678');
    }

    public function test_phone_hmac_depends_on_pii_lookup_key_instead_of_app_key(): void
    {
        config([
            'app.key' => 'base64:Zmlyc3QtYXBwLWtleQ==',
            'security.pii_lookup_key' => 'first-pii-lookup-key',
        ]);

        $original = PiiHasher::phoneHmac('090-1234-5678');

        config(['app.key' => 'base64:c2Vjb25kLWFwcC1rZXk=']);

        $this->assertSame($original, PiiHasher::phoneHmac('090-1234-5678'));

        config(['security.pii_lookup_key' => 'second-pii-lookup-key']);

        $this->assertNotSame($original, PiiHasher::phoneHmac('090-1234-5678'));
    }
}
