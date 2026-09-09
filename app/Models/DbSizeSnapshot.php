<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DB 使用量の日次スナップショット（PLAN §14 / Phase 8）。
 * 業務データではなく運用メトリクス。1 日 1 行（captured_on UNIQUE）。
 */
final class DbSizeSnapshot extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'captured_on',
        'total_mb',
        'note',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'captured_on' => 'date',
        'total_mb' => 'integer',
    ];
}
