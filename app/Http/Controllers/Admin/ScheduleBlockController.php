<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Schedule\ScheduleBlockInput;
use App\Domain\Schedule\ScheduleBlockService;
use App\Enums\Schedule\ScheduleBlockType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreScheduleBlockRequest;
use App\Http\Requests\Admin\UpdateScheduleBlockRequest;
use App\Models\StaffScheduleBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 予定ブロック（予約以外でスタッフ／ブースの時間を埋める・§27-46）のCRUD。
 * 顧客予約ではないため、メール/SMS/Stripe/回数券/月額プランは一切動かさない。
 */
final class ScheduleBlockController extends Controller
{
    public function store(
        StoreScheduleBlockRequest $request,
        ScheduleBlockService $service,
    ): RedirectResponse {
        $data = $request->validated();
        $user = $this->userFor($request);
        $workDate = CarbonImmutable::parse((string) $data['work_date']);

        $service->create(new ScheduleBlockInput(
            staffId: isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            boothId: isset($data['booth_id']) ? (int) $data['booth_id'] : null,
            workDate: $workDate,
            startsAt: $this->atTime($workDate, (string) $data['start_at']),
            endsAt: $this->atTime($workDate, (string) $data['end_at']),
            type: ScheduleBlockType::from((string) $data['type']),
            title: $this->normalize($data['title'] ?? null),
            note: $this->normalize($data['note'] ?? null),
            actorUserId: (int) $user->id,
        ));

        return redirect()
            ->route('admin.schedule.index', $this->scheduleReturnParams($request, $workDate->toDateString()))
            ->with('success', __('messages.schedule_block.created'));
    }

    public function update(
        UpdateScheduleBlockRequest $request,
        StaffScheduleBlock $block,
        ScheduleBlockService $service,
    ): RedirectResponse {
        $data = $request->validated();
        $user = $this->userFor($request);
        $workDate = CarbonImmutable::parse((string) $data['work_date']);

        $service->update($block, new ScheduleBlockInput(
            staffId: isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            boothId: isset($data['booth_id']) ? (int) $data['booth_id'] : null,
            workDate: $workDate,
            startsAt: $this->atTime($workDate, (string) $data['start_at']),
            endsAt: $this->atTime($workDate, (string) $data['end_at']),
            type: ScheduleBlockType::from((string) $data['type']),
            title: $this->normalize($data['title'] ?? null),
            note: $this->normalize($data['note'] ?? null),
            actorUserId: (int) $user->id,
        ));

        return redirect()
            ->route('admin.schedule.index', $this->scheduleReturnParams($request, $workDate->toDateString()))
            ->with('success', __('messages.schedule_block.updated'));
    }

    /**
     * 予定ブロックのD&D（時間・担当・日付変更・§40）。既存 update() のバリデーションを再利用する。
     */
    public function updateTime(
        Request $request,
        StaffScheduleBlock $block,
        ScheduleBlockService $service,
    ): RedirectResponse {
        $validated = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_at' => ['required', 'date_format:H:i'],
            'end_at' => ['required', 'date_format:H:i', 'after:start_at'],
            'staff_id' => ['nullable', 'integer', 'exists:staff,user_id'],
            'booth_id' => ['nullable', 'integer', 'exists:booths,id'],
        ]);
        $user = $this->userFor($request);
        $workDate = CarbonImmutable::parse((string) $validated['work_date']);

        $staffId = $request->has('staff_id')
            ? (isset($validated['staff_id']) ? (int) $validated['staff_id'] : null)
            : ($block->staff_id === null ? null : (int) $block->staff_id);
        $boothId = $request->has('booth_id')
            ? (isset($validated['booth_id']) ? (int) $validated['booth_id'] : null)
            : ($block->booth_id === null ? null : (int) $block->booth_id);

        $service->update($block, new ScheduleBlockInput(
            staffId: $staffId,
            boothId: $boothId,
            workDate: $workDate,
            startsAt: $this->atTime($workDate, (string) $validated['start_at']),
            endsAt: $this->atTime($workDate, (string) $validated['end_at']),
            type: $block->type,
            title: $block->title,
            note: $block->note,
            actorUserId: (int) $user->id,
        ));

        return back()->with('success', __('messages.schedule_block.updated'));
    }

    public function destroy(
        Request $request,
        StaffScheduleBlock $block,
        ScheduleBlockService $service,
    ): RedirectResponse {
        $date = $block->work_date?->toDateString();
        $service->delete($block, $request->user());

        return redirect()
            ->route('admin.schedule.index', $this->scheduleReturnParams($request, (string) $date))
            ->with('success', __('messages.schedule_block.deleted'));
    }

    /**
     * 台帳の軸・スタッフ絞り込み表示状態を保ったまま予約台帳へ戻る（date だけの
     * リダイレクトだと軸が「スタッフ」にリセットされる／パネルの block=,panel= が
     * 残って再度開いてしまうのを防ぐ）。
     *
     * @return array<string, string>
     */
    private function scheduleReturnParams(Request $request, string $date): array
    {
        $params = ['date' => $date];

        foreach (['view', 'axis', 'staff_id'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $params[$key] = (string) $value;
            }
        }

        return $params;
    }

    private function atTime(CarbonImmutable $date, string $hm): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$hm.':00');
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function userFor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
