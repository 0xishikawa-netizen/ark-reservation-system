<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Models\DailyBusinessNote;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DailyBusinessNoteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, DailyBusinessNote> */
    public function forMonth(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->toDateString();
        $end = CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString();

        return DailyBusinessNote::query()->whereBetween('business_date', [$start, $end])->get()
            ->keyBy(static fn (DailyBusinessNote $note): string => $note->business_date->toDateString())->all();
    }

    public function save(string $businessDate, ?string $condition, ?string $reflection, User $actor): DailyBusinessNote
    {
        return DB::transaction(function () use ($businessDate, $condition, $reflection, $actor): DailyBusinessNote {
            $note = DailyBusinessNote::query()->firstOrNew(['business_date' => $businessDate]);
            if (! $note->exists) {
                $note->created_by = $actor->getKey();
            }
            $note->business_condition = $condition;
            $note->reflection = $reflection;
            if ($note->isDirty(['business_condition', 'reflection']) || ! $note->exists) {
                $note->updated_by = $actor->getKey();
                $changed = array_keys($note->getDirty());
                $note->save();
                $this->audit->log('reports.daily_note_updated', $note,
                    $businessDate.' '.implode(',', array_intersect($changed, ['business_condition', 'reflection'])), $actor);
            }

            return $note;
        });
    }
}
