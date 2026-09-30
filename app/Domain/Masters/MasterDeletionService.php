<?php

declare(strict_types=1);

namespace App\Domain\Masters;

use App\Models\Booth;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketProduct;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * マスタ（メニュー・ブース・商品・スタッフ・回数券・月額プラン）の論理削除と復元。
 *
 * 予約・会計・施術・過去の勤務などの実績から参照されているものは削除しない（過去の集計・会計を壊さないため）。
 * 使用中かどうかは DB の外部キー（information_schema）から参照元の表を自動で集め、設定用の表
 * （担当スタッフ・利用ブース・必要資格・勤務テンプレートなど）を除いて判定する。表が増えても判定が漏れない。
 * 削除時は有効フラグも落とし、DB::table で有効なものだけを読む選択肢・空き枠計算からも確実に外す。
 */
final class MasterDeletionService
{
    /**
     * @var array<string, array{model: class-string<Model>, label: string, flag: string, config: list<string>}>
     */
    public const TYPES = [
        'services' => ['model' => Service::class, 'label' => 'メニュー', 'flag' => 'is_active',
            'config' => ['booth_service', 'qualification_service', 'service_staff']],
        'booths' => ['model' => Booth::class, 'label' => 'ブース', 'flag' => 'is_active',
            'config' => ['booth_service']],
        'products' => ['model' => Product::class, 'label' => '商品', 'flag' => 'is_active', 'config' => []],
        'staff' => ['model' => Staff::class, 'label' => 'スタッフ', 'flag' => 'is_bookable',
            'config' => ['qualification_staff', 'service_staff', 'staff_employment_periods', 'staff_shift_templates', 'staff_shift_exceptions']],
        'ticket-products' => ['model' => TicketProduct::class, 'label' => '回数券', 'flag' => 'is_active', 'config' => []],
        'membership-plans' => ['model' => MembershipPlan::class, 'label' => '月額プラン', 'flag' => 'is_active', 'config' => []],
    ];

    /** 参照元の表名 → 画面に出す名前。 */
    private const USAGE_LABELS = [
        'reservations' => '予約',
        'reservation_segments' => '予約の施術構成',
        'visits' => '来店',
        'visit_treatments' => '施術実績',
        'visit_treatment_staff' => '施術実績',
        'visit_staff_nominations' => '指名',
        'checkout_lines' => '会計',
        'staff_revenue_allocations' => '会計',
        'staff_schedule_blocks' => 'スタッフ予定',
        'staff_shifts' => '過去の勤務',
        'staff_attendances' => '出退勤',
        'ticket_wallets' => 'お客様の回数券',
        'ticket_transactions' => '回数券の履歴',
        'memberships' => '月額契約',
        'membership_usage_transactions' => '月額の利用履歴',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return class-string<Model> */
    public function modelFor(string $type): string
    {
        if (! isset(self::TYPES[$type])) {
            abort(404);
        }

        return self::TYPES[$type]['model'];
    }

    /**
     * 使用中の参照元（画面表示用の名前）。空なら削除できる。
     *
     * @return list<string>
     */
    public function usages(string $type, Model $model): array
    {
        $table = $model->getTable();
        $key = $model->getKey();
        $config = self::TYPES[$type]['config'];
        $references = DB::select(
            'select TABLE_NAME as t, COLUMN_NAME as c from information_schema.KEY_COLUMN_USAGE
             where REFERENCED_TABLE_SCHEMA = database() and REFERENCED_TABLE_NAME = ?',
            [$table],
        );
        $used = [];
        foreach ($references as $reference) {
            $referencing = (string) $reference->t;
            if (in_array($referencing, $config, true)) {
                continue;
            }
            $query = DB::table($referencing)->where((string) $reference->c, $key);
            // 未来の勤務は「予定」なので削除を妨げない（過去の勤務は稼働率の実績）。
            if ($referencing === 'staff_shifts') {
                $query->where('work_date', '<', now('Asia/Tokyo')->toDateString());
            }
            if ($query->exists()) {
                $used[] = self::USAGE_LABELS[$referencing] ?? $referencing;
            }
        }

        return array_values(array_unique($used));
    }

    public function delete(string $type, Model $model, ?Authenticatable $actor): void
    {
        $label = self::TYPES[$type]['label'];
        $usages = $this->usages($type, $model);
        if ($usages !== []) {
            throw ValidationException::withMessages([
                'delete' => __('messages.masters.in_use', ['label' => $label, 'usages' => implode('・', $usages)]),
            ]);
        }

        DB::transaction(function () use ($type, $model): void {
            $model->forceFill([self::TYPES[$type]['flag'] => false])->save();
            $model->delete();
            // 未来の勤務は削除済みスタッフの枠として残さない。
            if ($model instanceof Staff) {
                DB::table('staff_shifts')->where('staff_id', $model->getKey())
                    ->where('work_date', '>=', now('Asia/Tokyo')->toDateString())->delete();
            }
        });
        $this->audit->log("master.deleted.{$type}", $model, "{$label}を削除: ".$this->nameOf($model), $actor);
    }

    public function restore(string $type, Model $model, ?Authenticatable $actor): void
    {
        $model->restore(); // @phpstan-ignore method.notFound（SoftDeletes を使うモデルだけを TYPES に登録している）
        $this->audit->log("master.restored.{$type}", $model, self::TYPES[$type]['label'].'を復元: '.$this->nameOf($model), $actor);
    }

    public function nameOf(Model $model): string
    {
        return (string) ($model->getAttribute('name') ?? $model->getAttribute('display_name') ?? $model->getKey());
    }

    /**
     * 一覧画面の「削除済み」に出す行。
     *
     * @return list<array{id: int, name: string, deleted_at: string|null}>
     */
    public function trashed(string $type): array
    {
        $class = $this->modelFor($type);

        return $class::onlyTrashed()->orderByDesc('deleted_at')->get()
            ->map(fn (Model $model): array => [
                'id' => (int) $model->getKey(),
                'name' => $this->nameOf($model),
                'deleted_at' => $model->getAttribute('deleted_at')?->timezone('Asia/Tokyo')->format('Y-m-d H:i'),
            ])->values()->all();
    }
}
