<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Payment\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebhookEvent> */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stripe_event_id' => 'evt_'.fake()->unique()->lexify(str_repeat('?', 24)),
            'type' => 'payment_intent.succeeded',
            'api_version' => null,
            'status' => WebhookEventStatus::Received,
            'related_type' => null,
            'related_id' => null,
            'event_created_at' => null,
            'received_at' => now(),
            'processed_at' => null,
            'attempts' => 0,
            'error' => null,
        ];
    }
}
