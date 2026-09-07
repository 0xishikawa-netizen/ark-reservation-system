<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

class StripeLiveKeyGuardTest extends TestCase
{
    public function test_live_secret_key_is_rejected_in_testing(): void
    {
        config()->set('stripe.key', 'pk_test_dummy_for_guard_test');
        config()->set('stripe.secret', 'sk_live_dummy_for_guard_test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stripe Live キーは local/testing で使用できません。');

        AppServiceProvider::assertNoStripeLiveKeys();
    }

    public function test_test_keys_are_allowed_in_testing(): void
    {
        config()->set('stripe.key', 'pk_test_dummy_for_guard_test');
        config()->set('stripe.secret', 'sk_test_dummy_for_guard_test');

        AppServiceProvider::assertNoStripeLiveKeys();

        $this->addToAssertionCount(1);
    }
}
