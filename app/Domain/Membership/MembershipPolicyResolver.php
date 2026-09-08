<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Enums\Membership\MembershipNoShowPolicy;
use App\Support\Settings\Settings;

final class MembershipPolicyResolver
{
    /** @var list<string> */
    public const ALLOWED_NO_SHOW = ['consume', 'restore'];

    public function __construct(private readonly Settings $settings) {}

    /**
     * RESERVE 時に snapshot する no_show ポリシー。
     * settings テーブルを優先し、無ければ config/membership.php の既定。不正値は consume。
     */
    public function noShowPolicy(): MembershipNoShowPolicy
    {
        $value = $this->settings->get(
            'membership.no_show_policy',
            (string) config('membership.no_show_policy', 'consume'),
        );

        return is_string($value)
            ? (MembershipNoShowPolicy::tryFrom($value) ?? MembershipNoShowPolicy::Consume)
            : MembershipNoShowPolicy::Consume;
    }
}
