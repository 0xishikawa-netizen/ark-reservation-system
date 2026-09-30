<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCalendarDay extends Model
{
    use HasFactory;

    public const STATUS_CLOSED = 'closed';

    public const STATUS_SPECIAL_HOURS = 'special_hours';

    /** 定休日の曜日でも、この日は通常の営業時間で営業する。 */
    public const STATUS_OPEN = 'open';

    /** @var list<string> */
    protected $fillable = [
        'business_date',
        'status',
        'opens_at',
        'closes_at',
        'note',
        'updated_by',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_date' => 'date'];
    }
}
