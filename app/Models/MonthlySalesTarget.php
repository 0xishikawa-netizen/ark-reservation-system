<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlySalesTarget extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['target_month', 'target_amount', 'updated_by'];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['target_month' => 'date', 'target_amount' => 'integer'];
    }
}
