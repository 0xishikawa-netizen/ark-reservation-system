<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    use HasFactory;

    protected $table = 'staff';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'display_name',
        'color',
        'is_bookable',
        'sort_order',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
        ];
    }
}
