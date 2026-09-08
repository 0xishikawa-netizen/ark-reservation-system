<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Payment\WebhookEventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'stripe_event_id',
        'type',
        'api_version',
        'related_type',
        'related_id',
        'event_created_at',
        'received_at',
        'processed_at',
        'attempts',
        'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'event_created_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
