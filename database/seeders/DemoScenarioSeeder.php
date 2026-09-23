<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Booth;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TicketProduct;
use App\Models\TicketTransaction;
use App\Models\TicketWallet;
use App\Models\User;
use App\Support\Settings\Settings;
use App\Support\SlotKey;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 本番相当のボリューム・分布のデモデータを生成する（local 専用）。
 *
 * マスタ（スタッフ/サービス/ブース/回数券商品/会員プラン）は upsert で用意し、
 * トランザクション層（予約・決済・回数券・会員・監査ログ等）は一度クリアしてから
 * 生成し直す。何度実行しても一貫したシナリオへ収束する。
 *
 * 実行:  ./vendor/bin/sail artisan db:seed --class=DemoScenarioSeeder
 */
final class DemoScenarioSeeder extends Seeder
{
    private const DEMO_EMAIL_DOMAIN = '@demo.ark';

    private const CUSTOMER_COUNT = 52;

    /** 生成する予約期間（今日を基準にした日数）。 */
    private const PAST_DAYS = 35;

    private const FUTURE_DAYS = 21;

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoScenarioSeeder は local 環境専用です。スキップします。');

            return;
        }

        $this->command?->info('マスタを整備しています…');
        $this->call(RolePermissionSeeder::class);
        $this->call(SettingsSeeder::class);
        // 公式サイトの営業時間（平日 10:00〜21:00。日祝は 19:00 までをシフトで表現する）。
        app(Settings::class)->set('business_hours.open', '10:00');
        app(Settings::class)->set('business_hours.close', '21:00');

        $staff = $this->ensureStaff();
        $services = $this->ensureServices($staff);
        $booths = $this->ensureBooths();
        $products = $this->ensureTicketProducts();
        $plans = $this->ensureMembershipPlans();

        $this->command?->info('デモのトランザクションデータをクリアしています…');
        $this->clearDemoLayer();

        $this->command?->info('顧客を作成しています…');
        $customers = $this->createCustomers();

        $this->command?->info('シフトを作成しています…');
        $this->createShifts($staff);

        $this->command?->info('予約を作成しています…');
        $reservations = $this->createReservations($customers, $staff, $services, $booths);
        $this->markRecentOnlineArrivals($reservations);

        $this->command?->info('決済を作成しています…');
        $this->createPayments($reservations, $staff);

        $this->command?->info('回数券を作成しています…');
        $this->createTicketWallets($customers, $products, $staff);

        $this->command?->info('会員を作成しています…');
        $this->createMemberships($customers, $plans);

        $this->command?->info('監査ログ・失敗ジョブを作成しています…');
        $this->createAuditLogs($staff);
        $this->createSampleFailedJob();

        $this->printSummary();
    }

    // ------------------------------------------------------------------
    // マスタ
    // ------------------------------------------------------------------

    /** @return list<Staff> */
    private function ensureStaff(): array
    {
        // ARK 公式サイト（スタッフ紹介）のスタッフ。担当範囲はサイトに記載が無いため全メニュー担当にする。
        $definitions = [
            ['name' => '本間 慈基', 'email' => 'staff.shigeki'.self::DEMO_EMAIL_DOMAIN, 'display_name' => '本間 慈基', 'color' => '#1E88E5', 'role' => 'staff', 'sort' => 10],
            ['name' => '本間 奨基', 'email' => 'staff.shoki'.self::DEMO_EMAIL_DOMAIN, 'display_name' => '本間 奨基', 'color' => '#00897B', 'role' => 'staff', 'sort' => 20],
            ['name' => '齋藤 美由紀', 'email' => 'staff.saito'.self::DEMO_EMAIL_DOMAIN, 'display_name' => '齋藤 美由紀', 'color' => '#D81B60', 'role' => 'staff', 'sort' => 30],
        ];

        $staff = [];

        foreach ($definitions as $d) {
            $user = User::query()->firstOrCreate(
                ['email' => $d['email']],
                ['name' => $d['name'], 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
            $user->forceFill(['name' => $d['name'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $user->syncRoles([$d['role']]);

            $staff[] = Staff::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $d['display_name'],
                    'color' => $d['color'],
                    'is_bookable' => true,
                    'sort_order' => $d['sort'],
                ],
            );
        }

        return $staff;
    }

    /**
     * @param  list<Staff>  $staff
     * @return list<Service>
     */
    private function ensureServices(array $staff): array
    {
        // ARK 公式サイトの料金表（https://ark-conditioning.com/price）に合わせる。
        // ARKコンディショニングは時間制で、中身（カラダチェック・整体・鍼灸・トレーニング）は当日の状態で組み合わせる。
        // ダイエットコースは回数券（コース契約）専用のため、1回あたりの金額（132,000円÷12回）を単価にしオンライン予約は不可。
        $definitions = [
            ['name' => 'ARKコンディショニング 45分', 'duration_min' => 45, 'price' => 7700, 'category' => 'ARKコンディショニング', 'color' => '#3949AB', 'online' => true, 'sort' => 10],
            ['name' => 'ARKコンディショニング 60分', 'duration_min' => 60, 'price' => 9900, 'category' => 'ARKコンディショニング', 'color' => '#1A2653', 'online' => true, 'sort' => 20],
            ['name' => 'ARKコンディショニング 75分', 'duration_min' => 75, 'price' => 12100, 'category' => 'ARKコンディショニング', 'color' => '#5E35B1', 'online' => true, 'sort' => 30],
            ['name' => 'ARKコンディショニング 90分', 'duration_min' => 90, 'price' => 14300, 'category' => 'ARKコンディショニング', 'color' => '#00838F', 'online' => true, 'sort' => 40],
            ['name' => 'ジュニア・シニア 30分', 'duration_min' => 30, 'price' => 5500, 'category' => 'ジュニア・シニア', 'color' => '#43A047', 'online' => true, 'sort' => 50],
            ['name' => 'ジュニア・シニア 45分', 'duration_min' => 45, 'price' => 7700, 'category' => 'ジュニア・シニア', 'color' => '#2E7D32', 'online' => true, 'sort' => 60],
            ['name' => 'ダイエットコース 60分', 'duration_min' => 60, 'price' => 11000, 'category' => 'ダイエット', 'color' => '#EF6C00', 'online' => false, 'sort' => 70],
            ['name' => 'ペアトレーニング 60分', 'duration_min' => 60, 'price' => 17600, 'category' => 'ペアトレーニング', 'color' => '#C2185B', 'online' => true, 'sort' => 80],
            ['name' => 'ペアトレーニング 90分', 'duration_min' => 90, 'price' => 26400, 'category' => 'ペアトレーニング', 'color' => '#AD1457', 'online' => true, 'sort' => 90],
        ];

        $staffIds = array_map(static fn (Staff $s): int => (int) $s->user_id, $staff);
        $services = [];

        foreach ($definitions as $d) {
            $service = Service::query()->updateOrCreate(
                ['name' => $d['name']],
                [
                    'duration_min' => $d['duration_min'],
                    'price' => $d['price'],
                    'category' => $d['category'],
                    'is_online_bookable' => $d['online'],
                    'requires_staff' => true,
                    'color' => $d['color'],
                    'is_active' => true,
                    'sort_order' => $d['sort'],
                ],
            );
            $service->staff()->sync($staffIds);
            $services[] = $service;
        }

        return $services;
    }

    /** @return list<Booth> */
    private function ensureBooths(): array
    {
        $booths = [];

        // 店舗で使っているブース構成（管理画面で登録されていたもの）。
        foreach (['パーソナルA', 'パーソナルB', 'ベットA', 'ベットB'] as $i => $name) {
            $booths[] = Booth::query()->updateOrCreate(
                ['name' => $name],
                ['sort_order' => ($i + 1) * 10, 'is_active' => true],
            );
        }

        return $booths;
    }

    /** @return list<TicketProduct> */
    private function ensureTicketProducts(): array
    {
        // 公式サイトの料金表どおり。有効期限はサイトに記載が無いため仮の値
        // （4回券90日・5回券120日・8回券180日・12回券365日）。ダイエットコースはサイト記載の期間。
        $definitions = [];

        foreach ([45 => [28600, 56100, 81400], 60 => [37400, 73700, 107800], 75 => [46200, 91300, 134200], 90 => [55000, 108900, 160600]] as $minutes => [$four, $eight, $twelve]) {
            $definitions[] = ['name' => "ARKコンディショニング {$minutes}分 4回券", 'total_count' => 4, 'price' => $four, 'validity_days' => 90];
            $definitions[] = ['name' => "ARKコンディショニング {$minutes}分 8回券", 'total_count' => 8, 'price' => $eight, 'validity_days' => 180];
            $definitions[] = ['name' => "ARKコンディショニング {$minutes}分 12回券", 'total_count' => 12, 'price' => $twelve, 'validity_days' => 365];
        }

        array_push(
            $definitions,
            ['name' => 'ジュニア・シニア 30分 4回券', 'total_count' => 4, 'price' => 19800, 'validity_days' => 90],
            ['name' => 'ジュニア・シニア 45分 4回券', 'total_count' => 4, 'price' => 28600, 'validity_days' => 90],
            ['name' => 'ダイエットコース 60分×12回（3ヶ月）', 'total_count' => 12, 'price' => 132000, 'validity_days' => 90],
            ['name' => 'ダイエットコース 60分×16回（2ヶ月）', 'total_count' => 16, 'price' => 176000, 'validity_days' => 60],
            ['name' => 'ペアトレーニング 60分 5回券', 'total_count' => 5, 'price' => 85800, 'validity_days' => 120],
            ['name' => 'ペアトレーニング 90分 5回券', 'total_count' => 5, 'price' => 129800, 'validity_days' => 120],
        );

        $products = [];

        foreach ($definitions as $i => $d) {
            $products[] = TicketProduct::query()->updateOrCreate(
                ['name' => $d['name']],
                [
                    'total_count' => $d['total_count'],
                    'price' => $d['price'],
                    'validity_days' => $d['validity_days'],
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                ],
            );
        }

        return $products;
    }

    /** @return list<MembershipPlan> */
    private function ensureMembershipPlans(): array
    {
        // 公式サイトの「月額コース」（平日12:00〜18:00・指名不可・1日1回まで）。
        $definitions = [
            ['name' => '月額 45分×4回', 'price' => 24200, 'count' => 4],
            ['name' => '月額 45分×8回', 'price' => 44000, 'count' => 8],
            ['name' => '月額 60分×4回', 'price' => 33000, 'count' => 4],
            ['name' => '月額 60分×8回', 'price' => 61600, 'count' => 8],
            ['name' => '月額 75分×4回', 'price' => 41800, 'count' => 4],
            ['name' => '月額 75分×8回', 'price' => 79200, 'count' => 8],
            ['name' => '月額 90分×4回', 'price' => 50600, 'count' => 4],
            ['name' => '月額 90分×8回', 'price' => 96800, 'count' => 8],
        ];

        $plans = [];

        foreach ($definitions as $i => $d) {
            $plans[] = MembershipPlan::query()->updateOrCreate(
                ['name' => $d['name']],
                [
                    'price' => $d['price'],
                    'usage_count_per_period' => $d['count'],
                    'billing_interval' => 'month',
                    'stripe_price_id' => 'price_demo_'.Str::lower(Str::random(20)),
                    'is_active' => true,
                    'sort_order' => ($i + 1) * 10,
                ],
            );
        }

        return $plans;
    }

    // ------------------------------------------------------------------
    // クリア
    // ------------------------------------------------------------------

    private function clearDemoLayer(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'reservation_resource_slots',
            'ticket_reservation_usages',
            'ticket_transactions',
            'ticket_wallets',
            'membership_reservation_usages',
            'membership_usage_transactions',
            'memberships',
            'payment_refunds',
            'payments',
            'reservations',
            'staff_shifts',
            'audit_logs',
            'failed_jobs',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        // デモ顧客（@demo.ark）とその User だけを削除する。スタッフ/管理者は残す。
        $demoUserIds = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('users.email', 'like', '%'.self::DEMO_EMAIL_DOMAIN)
            ->pluck('users.id')
            ->all();

        if ($demoUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('user_social_accounts')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->whereIn('model_id', $demoUserIds)
                ->delete();
            DB::table('users')->whereIn('id', $demoUserIds)->delete();
        }

        Schema::enableForeignKeyConstraints();
    }

    // ------------------------------------------------------------------
    // 顧客
    // ------------------------------------------------------------------

    /** @return list<Customer> */
    private function createCustomers(): array
    {
        $sei = ['佐藤', '鈴木', '高橋', '田中', '伊藤', '渡辺', '山本', '中村', '小林', '加藤', '吉田', '山田', '松本', '井上', '木村', '林', '清水', '斎藤', '山口', '森'];
        $seiKana = ['サトウ', 'スズキ', 'タカハシ', 'タナカ', 'イトウ', 'ワタナベ', 'ヤマモト', 'ナカムラ', 'コバヤシ', 'カトウ', 'ヨシダ', 'ヤマダ', 'マツモト', 'イノウエ', 'キムラ', 'ハヤシ', 'シミズ', 'サイトウ', 'ヤマグチ', 'モリ'];
        $meiM = ['翔太', '大輝', '健太', '拓也', '直樹', '雄大', '和也', '亮', '涼介', '智也'];
        $meiMKana = ['ショウタ', 'ダイキ', 'ケンタ', 'タクヤ', 'ナオキ', 'ユウダイ', 'カズヤ', 'リョウ', 'リョウスケ', 'トモヤ'];
        $meiF = ['美咲', '陽菜', '結衣', '愛', 'さくら', '七海', '真央', '彩', '奈々', '瞳'];
        $meiFKana = ['ミサキ', 'ヒナ', 'ユイ', 'アイ', 'サクラ', 'ナナミ', 'マオ', 'アヤ', 'ナナ', 'ヒトミ'];

        $customers = [];

        for ($i = 1; $i <= self::CUSTOMER_COUNT; $i++) {
            $s = array_rand($sei);
            $isMale = ($i % 2) === 0;
            $m = $isMale ? array_rand($meiM) : array_rand($meiF);

            $name = $sei[$s].' '.($isMale ? $meiM[$m] : $meiF[$m]);
            $kana = $seiKana[$s].' '.($isMale ? $meiMKana[$m] : $meiFKana[$m]);

            $email = sprintf('cust%02d%s', $i, self::DEMO_EMAIL_DOMAIN);

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'email_verified_at' => $i % 9 === 0 ? null : now()->subDays(random_int(1, 400)),
            ]);
            $user->assignRole('customer');

            $customers[] = Customer::query()->create([
                'user_id' => $user->id,
                'kana' => $kana,
                'phone' => sprintf('0%d0-%04d-%04d', random_int(7, 9), random_int(1000, 9999), random_int(1000, 9999)),
                'birthday' => now()->subYears(random_int(20, 60))->subDays(random_int(0, 364))->toDateString(),
                'gender' => $isMale ? 'male' : 'female',
                'note' => null,
                'created_via' => [
                    'web', 'web', 'web', 'google', 'demo',
                ][array_rand(['web', 'web', 'web', 'google', 'demo'])],
            ]);
        }

        return $customers;
    }

    // ------------------------------------------------------------------
    // シフト
    // ------------------------------------------------------------------

    /** @param list<Staff> $staff */
    private function createShifts(array $staff): void
    {
        $start = Carbon::today()->subDays(self::PAST_DAYS);
        $end = Carbon::today()->addDays(self::FUTURE_DAYS);
        $rows = [];

        foreach ($staff as $index => $member) {
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $shift = $this->shiftFor($index, $day);

                if ($shift === null) {
                    continue;
                }

                $rows[] = [
                    'staff_id' => $member->user_id,
                    'work_date' => $day->toDateString(),
                    'start_at' => $shift[0].':00',
                    'end_at' => $shift[1].':00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('staff_shifts')->insert($chunk);
        }
    }

    /**
     * スタッフの勤務時間（公式サイトの営業時間：平日 10:00〜21:00、日祝 10:00〜19:00）。
     * 各自週1日休み、平日は早番（10:00〜19:00）と遅番（12:00〜21:00）を交互にする。
     *
     * @return array{0: string, 1: string}|null
     */
    private function shiftFor(int $staffIndex, Carbon $day): ?array
    {
        // 休みは火・水・木で分散させる（全員が同じ日に休まない）。
        if ($day->dayOfWeek === 2 + ($staffIndex % 3)) {
            return null;
        }

        if ($day->isSunday()) {
            return ['10:00', '19:00'];
        }

        return ($day->dayOfYear + $staffIndex) % 2 === 0
            ? ['10:00', '19:00']
            : ['12:00', '21:00'];
    }

    // ------------------------------------------------------------------
    // 予約
    // ------------------------------------------------------------------

    /**
     * @param  list<Customer>  $customers
     * @param  list<Staff>  $staff
     * @param  list<Service>  $services
     * @param  list<Booth>  $booths
     * @return list<Reservation>
     */
    private function createReservations(array $customers, array $staff, array $services, array $booths): array
    {
        $reservations = [];
        // スタッフ・ブースごとの使用済み時間帯。開始時刻の一致だけでなく、時間が
        // 少しでも重なる予約は作らない（ブース・担当のダブルブッキングを台帳に出さない）。
        /** @var array<string, list<array{0: int, 1: int}>> $usedRanges */
        $usedRanges = [];
        // 本物の予約（ReservationService）と同じく、占有スロット行も作る。無いと DB 一意制約の
        // 重複防止が効かず、デモ予約と被る予約（ブースのダブルブッキング）が作れてしまう。
        $slotKey = SlotKey::fromSettings();
        $slotRows = [];

        $start = Carbon::today()->subDays(self::PAST_DAYS);
        $end = Carbon::today()->addDays(self::FUTURE_DAYS);

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $offset = (int) Carbon::today()->diffInDays($day, false);

            // 台帳の見た目を実店舗に近づけるため、1日あたりの件数を多めにする。
            // 日曜は出勤が少ないぶん、件数も控えめにする。
            // スタッフ3名の店舗なので、1日あたりの「予約の試行回数」は控えめにする
            // （担当・ブースが重なる候補はスキップされるため、実際の件数はこれより少ない）。
            $count = $offset < 0
                ? random_int(10, 16)
                : ($offset === 0 ? random_int(10, 14) : random_int(7, 12));

            if ($day->isSunday()) {
                $count = (int) ceil($count * 0.6);
            }

            for ($n = 0; $n < $count; $n++) {
                $staffIndex = array_rand($staff);
                $member = $staff[$staffIndex];
                $service = $this->pickService($services);
                $customer = $customers[array_rand($customers)];
                $booth = $booths[array_rand($booths)];

                // その日の勤務時間内に収まる開始時刻（5分刻み）を選ぶ。休みの日は作らない。
                $shift = $this->shiftFor($staffIndex, $day);

                if ($shift === null) {
                    continue;
                }

                [$shiftStartH, $shiftStartM] = array_map('intval', explode(':', $shift[0]));
                [$shiftEndH, $shiftEndM] = array_map('intval', explode(':', $shift[1]));
                $latestStart = ($shiftEndH * 60 + $shiftEndM) - (int) $service->duration_min;
                $earliestStart = $shiftStartH * 60 + $shiftStartM;

                if ($latestStart < $earliestStart) {
                    continue;
                }

                $startMinute = $earliestStart + random_int(0, intdiv($latestStart - $earliestStart, 5)) * 5;
                $startsAt = $day->copy()->setTime(intdiv($startMinute, 60), $startMinute % 60);
                $endsAt = $startsAt->copy()->addMinutes((int) $service->duration_min);

                $range = [$startsAt->timestamp, $endsAt->timestamp];
                $staffKey = 'staff:'.$member->user_id;
                $boothKey = 'booth:'.$booth->id;
                if ($this->overlapsAny($usedRanges[$staffKey] ?? [], $range)
                    || $this->overlapsAny($usedRanges[$boothKey] ?? [], $range)) {
                    continue;
                }
                $usedRanges[$staffKey][] = $range;
                $usedRanges[$boothKey][] = $range;

                [$status, $paymentStatus, $method, $attendedAt, $canceledAt, $cancelReason]
                    = $this->reservationOutcome($offset, $startsAt);

                $source = $this->weighted([
                    'ARK_WEB' => 50, 'ADMIN' => 30, 'HOTPEPPER' => 12, 'EPARK' => 8,
                ]);
                $external = in_array($source, ['HOTPEPPER', 'EPARK'], true);

                $r = new Reservation;
                $r->customer_id = $customer->user_id;
                $r->service_id = $service->id;
                $r->staff_id = $member->user_id;
                $r->booth_id = $booth->id;
                $r->starts_at = $startsAt;
                $r->ends_at = $endsAt;
                $r->source = $source;
                $r->payment_method = $method;
                $r->payment_status = $paymentStatus;
                $r->payment_expires_at = $status === 'pending_payment'
                    ? ($this->weighted(['past' => 45, 'future' => 55]) === 'past'
                        ? now()->subHours(random_int(2, 40))
                        : $startsAt->copy()->subDay())
                    : null;
                $r->status = $status;
                $r->attended_at = $attendedAt;
                $r->canceled_at = $canceledAt;
                $r->cancel_reason = $cancelReason;
                $r->final_amount = in_array($status, ['completed', 'confirmed'], true) && $method !== 'onsite'
                    ? (int) $service->price
                    : null;
                $r->external_provider = $external ? $source : null;
                $r->external_reservation_id = $external ? Str::upper(Str::random(10)) : null;
                $r->sync_status = $external ? 'SYNCED' : 'NOT_REQUIRED';
                $r->synced_at = $external ? $startsAt->copy()->subDay() : null;
                $r->version = random_int(0, 3);
                $r->notes = random_int(1, 6) === 1 ? '受付メモ: 追加リクエストあり' : null;
                $r->created_by = $source === 'ADMIN' ? $member->user_id : null;
                // 「いつ予約されたか」。全件を now() にすると、台帳のオンライン予約通知
                // （直近24時間の新着が対象）が何百件も出てしまうため、予約日の少し前に散らす。
                $r->created_at = $this->bookedAt($startsAt);
                $r->updated_at = $r->created_at;
                $r->save();

                // キャンセル・期限切れは枠を解放する（ReservationService と同じ扱い）。
                if (! in_array($status, ['canceled', 'expired'], true)) {
                    foreach ($slotKey->occupiedSlots(
                        CarbonImmutable::instance($startsAt),
                        CarbonImmutable::instance($endsAt),
                        true,
                    ) as $slot) {
                        foreach ([['staff', $member->user_id], ['booth', $booth->id]] as [$type, $resourceId]) {
                            $slotRows[] = [
                                'resource_type' => $type,
                                'resource_id' => $resourceId,
                                'slot_start' => $slot,
                                'reservation_id' => $r->id,
                                'created_at' => $r->created_at,
                            ];
                        }
                    }
                }

                $reservations[] = $r;
            }
        }

        foreach (array_chunk($slotRows, 500) as $chunk) {
            DB::table('reservation_resource_slots')->insert($chunk);
        }

        return $reservations;
    }

    /**
     * 実店舗に近い比率でメニューを選ぶ（60分・75分のコンディショニングが中心）。
     *
     * @param  list<Service>  $services
     */
    private function pickService(array $services): Service
    {
        $weights = [
            'ARKコンディショニング 45分' => 14,
            'ARKコンディショニング 60分' => 34,
            'ARKコンディショニング 75分' => 20,
            'ARKコンディショニング 90分' => 12,
            'ジュニア・シニア 30分' => 5,
            'ジュニア・シニア 45分' => 5,
            'ダイエットコース 60分' => 5,
            'ペアトレーニング 60分' => 3,
            'ペアトレーニング 90分' => 2,
        ];
        $pool = [];

        foreach ($services as $service) {
            $pool = array_merge($pool, array_fill(0, $weights[$service->name] ?? 1, $service));
        }

        return $pool[array_rand($pool)];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @param  array{0: int, 1: int}  $range
     */
    private function overlapsAny(array $ranges, array $range): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($range[0] < $end && $start < $range[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * 台帳のオンライン予約通知を確認できるよう、これから来店する予約のうち数件だけ
     * 「ついさっきWebから入った予約」に仕立てる（通知が数百件出ないよう件数を絞る）。
     *
     * @param  list<Reservation>  $reservations
     */
    private function markRecentOnlineArrivals(array $reservations, int $count = 3): void
    {
        // source / status は enum にキャストされるため、値（string）で比較する。
        $candidates = array_values(array_filter(
            $reservations,
            static function (Reservation $r): bool {
                $source = $r->source instanceof BackedEnum ? $r->source->value : (string) $r->source;
                $status = $r->status instanceof BackedEnum ? $r->status->value : (string) $r->status;

                return $r->starts_at->isFuture()
                    && in_array($source, ['ARK_WEB', 'HOTPEPPER', 'EPARK'], true)
                    && in_array($status, ['confirmed', 'pending_payment'], true);
            },
        ));

        shuffle($candidates);

        foreach (array_slice($candidates, 0, $count) as $index => $reservation) {
            $reservation->forceFill([
                'created_at' => Carbon::now()->subMinutes(random_int(5, 300) + $index),
            ])->saveQuietly();
        }
    }

    /**
     * 予約が入った日時。予約日の 1〜30 日前を基本とし、未来の予約でも「今より後に
     * 予約された」ことにならないよう今日以前へ収める。
     * さらに 2 日以内に寄りすぎないようにして、通知が大量に出るのを防ぐ。
     */
    private function bookedAt(Carbon $startsAt): Carbon
    {
        $candidate = $startsAt->copy()->subDays(random_int(1, 30))->setTime(
            random_int(8, 22),
            random_int(0, 59),
        );

        $latest = Carbon::now()->subDays(2);

        return $candidate->greaterThan($latest) ? $latest->copy()->subHours(random_int(0, 48)) : $candidate;
    }

    /**
     * @return array{0:string,1:string,2:string,3:?Carbon,4:?Carbon,5:?string}
     */
    private function reservationOutcome(int $offset, Carbon $startsAt): array
    {
        $method = $this->weighted([
            'single' => 40, 'onsite' => 30, 'ticket' => 20, 'membership' => 10,
        ]);

        if ($offset < 0 || ($offset === 0 && $startsAt->isPast())) {
            $outcome = $this->weighted(['completed' => 74, 'canceled' => 15, 'no_show' => 11]);

            return match ($outcome) {
                'completed' => ['completed', $method === 'onsite' ? 'paid' : 'paid', $method, $startsAt->copy()->addMinutes(3), null, null],
                'no_show' => ['no_show', 'unpaid', $method, null, null, null],
                default => ['canceled', $this->weighted(['refunded' => 30, 'unpaid' => 70]), $method, null, $startsAt->copy()->subHours(random_int(3, 72)), $this->weighted(['体調不良のため' => 50, '予定変更のため' => 35, '店舗都合' => 15])],
            };
        }

        if ($offset === 0) {
            return ['confirmed', $method === 'onsite' ? 'unpaid' : ($method === 'single' ? 'authorized' : 'paid'), $method, null, null, null];
        }

        $outcome = $this->weighted(['confirmed' => 82, 'pending_payment' => 12, 'pending_external_sync' => 6]);

        return match ($outcome) {
            'pending_payment' => ['pending_payment', 'pending_payment', 'single', null, null, null],
            'pending_external_sync' => ['pending_external_sync', 'unpaid', $method, null, null, null],
            default => ['confirmed', $method === 'onsite' ? 'unpaid' : ($method === 'single' ? 'authorized' : 'paid'), $method, null, null, null],
        };
    }

    // ------------------------------------------------------------------
    // 決済
    // ------------------------------------------------------------------

    /**
     * @param  list<Reservation>  $reservations
     * @param  list<Staff>  $staff
     */
    private function createPayments(array $reservations, array $staff): void
    {
        $manager = $staff[0];
        $singlePayments = [];

        foreach ($reservations as $r) {
            if (($r->payment_method?->value) !== 'single') {
                continue;
            }

            $amount = (int) ($r->final_amount ?? 8800);
            $rStatus = $r->status?->value;

            [$status, $extra] = match (true) {
                $rStatus === 'completed' => ['succeeded', [
                    'capture_method' => 'automatic',
                    'stripe_payment_intent_id' => 'pi_demo_'.Str::lower(Str::random(20)),
                    'stripe_charge_id' => 'ch_demo_'.Str::lower(Str::random(20)),
                    'authorized_at' => $r->starts_at,
                    'paid_at' => $r->ends_at ?? $r->starts_at,
                    'last_synced_at' => $r->ends_at ?? $r->starts_at,
                ]],
                $rStatus === 'confirmed' => ['authorized', [
                    'capture_method' => 'manual',
                    'stripe_payment_intent_id' => 'pi_demo_'.Str::lower(Str::random(20)),
                    'authorized_at' => now()->subHours(random_int(1, 72)),
                    'payment_expires_at' => $r->starts_at,
                ]],
                $rStatus === 'pending_payment' => ['pending', [
                    'capture_method' => 'manual',
                    'payment_expires_at' => $r->payment_expires_at,
                ]],
                $rStatus === 'canceled' && $r->payment_status?->value === 'refunded' => ['refunded', [
                    'capture_method' => 'automatic',
                    'stripe_payment_intent_id' => 'pi_demo_'.Str::lower(Str::random(20)),
                    'stripe_charge_id' => 'ch_demo_'.Str::lower(Str::random(20)),
                    'authorized_at' => $r->starts_at->copy()->subDays(2),
                    'paid_at' => $r->starts_at->copy()->subDays(2),
                    'refunded_amount' => $amount,
                ]],
                default => [null, []],
            };

            if ($status === null) {
                continue;
            }

            $payment = new Payment;
            $payment->forceFill([
                'reservation_id' => $r->id,
                'customer_id' => $r->customer_id,
                'kind' => 'single',
                'provider' => 'stripe',
                'payment_operation_id' => (string) Str::uuid(),
                'amount' => $amount,
                'currency' => 'jpy',
                'refunded_amount' => 0,
                'needs_attention' => false,
                'created_by' => null,
                'status' => $status,
            ] + $extra)->save();

            if ($status === 'refunded') {
                $this->createRefund($payment, $amount, 'キャンセルポリシーに基づく全額返金', $manager->user_id);
            }

            if ($status === 'succeeded') {
                $singlePayments[] = $payment;
            }
        }

        // 「要対応決済」: 決済失敗フラグを数件立てる
        foreach (array_slice($singlePayments, 0, 3) as $payment) {
            $payment->forceFill([
                'status' => 'failed',
                'needs_attention' => true,
                'paid_at' => null,
                'failure_code' => 'card_declined',
                'failure_message' => 'カード会社により決済が拒否されました。',
            ])->save();
        }

        // 一部返金の例
        foreach (array_slice($singlePayments, 3, 2) as $payment) {
            $refund = (int) round($payment->amount * 0.3);
            $payment->forceFill([
                'status' => 'partially_refunded',
                'refunded_amount' => $refund,
            ])->save();
            $this->createRefund($payment, $refund, '施術時間短縮による一部返金', $manager->user_id);
        }
    }

    private function createRefund(Payment $payment, int $amount, string $reason, int $actorId): void
    {
        $refund = new PaymentRefund;
        $refund->forceFill([
            'payment_id' => $payment->id,
            'refund_operation_id' => (string) Str::uuid(),
            'amount' => $amount,
            'reason' => $reason,
            'status' => 'succeeded',
            'stripe_refund_id' => 're_demo_'.Str::lower(Str::random(20)),
            'created_by' => $actorId,
        ])->save();
    }

    // ------------------------------------------------------------------
    // 回数券
    // ------------------------------------------------------------------

    /**
     * @param  list<Customer>  $customers
     * @param  list<TicketProduct>  $products
     * @param  list<Staff>  $staff
     */
    private function createTicketWallets(array $customers, array $products, array $staff): void
    {
        $picked = array_slice($this->shuffled($customers), 0, 20);
        $manager = $staff[0];

        foreach ($picked as $index => $customer) {
            $product = $products[array_rand($products)];
            $count = (int) $product->total_count;

            // 分布: 通常 / 期限間近 / 残ゼロ / 期限切れ / 使い切り
            $bucket = match (true) {
                $index < 13 => 'normal',
                $index < 16 => 'expiring',
                $index === 16 => 'zero',
                $index < 19 => 'expired',
                default => 'exhausted',
            };

            [$balance, $expiresAt, $status] = match ($bucket) {
                'expiring' => [random_int(1, 3), Carbon::today()->addDays(random_int(3, 12)), 'active'],
                'zero' => [0, Carbon::today()->addDays(random_int(30, 90)), 'active'],
                'expired' => [random_int(0, 2), Carbon::today()->subDays(random_int(1, 40)), 'expired'],
                'exhausted' => [0, Carbon::today()->addDays(random_int(20, 80)), 'exhausted'],
                default => [random_int(1, max(1, $count - 1)), Carbon::today()->addDays(random_int(40, 200)), 'active'],
            };

            $wallet = TicketWallet::query()->create([
                'customer_id' => $customer->user_id,
                'ticket_product_id' => $product->id,
                'purchased_count' => $count,
                'balance' => $balance,
                'expires_at' => $expiresAt->toDateString(),
                'status' => $status,
            ]);

            // 台帳: 付与 + 消費で balance と整合させる
            TicketTransaction::query()->create([
                'ticket_wallet_id' => $wallet->id,
                'type' => 'GRANT',
                'delta' => $count,
                'reason' => '回数券購入',
                'dedupe_key' => 'demo:grant:'.$wallet->id,
                'created_at' => now()->subDays(random_int(10, 120)),
            ]);

            $consumed = $count - $balance;
            if ($consumed > 0) {
                TicketTransaction::query()->create([
                    'ticket_wallet_id' => $wallet->id,
                    'type' => $status === 'expired' ? 'EXPIRE' : 'CONSUME',
                    'delta' => -$consumed,
                    'staff_id' => $manager->user_id,
                    'reason' => $status === 'expired' ? '有効期限切れ' : '施術で消費',
                    'dedupe_key' => 'demo:consume:'.$wallet->id,
                    'created_at' => now()->subDays(random_int(1, 9)),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 会員
    // ------------------------------------------------------------------

    /**
     * @param  list<Customer>  $customers
     * @param  list<MembershipPlan>  $plans
     */
    private function createMemberships(array $customers, array $plans): void
    {
        $picked = array_slice($this->shuffled($customers), 0, 15);

        foreach ($picked as $index => $customer) {
            $plan = $plans[array_rand($plans)];

            $status = match (true) {
                $index < 9 => 'active',
                $index < 11 => 'grace',
                $index === 11 => 'paused',
                $index < 13 => 'canceling',
                $index === 13 => 'canceled',
                default => 'pending',
            };

            $periodStart = Carbon::now()->startOfMonth();
            $periodEnd = $periodStart->copy()->addMonth();

            Membership::query()->create([
                'customer_id' => $customer->user_id,
                'membership_plan_id' => $plan->id,
                'stripe_subscription_id' => $status === 'pending' ? null : 'sub_demo_'.Str::lower(Str::random(22)),
                'membership_operation_id' => (string) Str::uuid(),
                'pending_operation' => $status === 'pending' ? 'create' : null,
                'status' => $status,
                'current_period_start' => $status === 'pending' ? null : $periodStart->toDateString(),
                'current_period_end' => $status === 'pending' ? null : $periodEnd->toDateString(),
                'cancel_at_period_end' => $status === 'canceling',
                'grace_until' => $status === 'grace' ? now()->addDays(random_int(2, 6)) : null,
                'period_available' => $status === 'active' ? random_int(0, (int) $plan->usage_count_per_period) : 0,
                'started_at' => $status === 'pending' ? null : now()->subMonths(random_int(1, 10)),
                'canceled_at' => $status === 'canceled' ? now()->subDays(random_int(3, 20)) : null,
                'last_synced_at' => $status === 'pending' ? null : now()->subHours(random_int(1, 40)),
                'needs_attention' => in_array($status, ['grace', 'paused'], true),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 監査ログ / 失敗ジョブ
    // ------------------------------------------------------------------

    /** @param list<Staff> $staff */
    private function createAuditLogs(array $staff): void
    {
        $actorIds = array_map(static fn (Staff $s): int => (int) $s->user_id, $staff);
        $entries = [
            ['auth.login', 'ログイン: {email}'],
            ['auth.logout', 'ログアウト: {email}'],
            ['admin.reservation.updated', '予約 #{n} を更新'],
            ['admin.reservation.canceled', '予約 #{n} をキャンセル（理由記録あり）'],
            ['payments.refund', '決済 #{n} を一部返金'],
            ['ticket.grant', '顧客 #{n} に回数券を付与'],
            ['membership.adjust', '会員 #{n} の残回数を調整'],
            ['auth.two_factor_enabled', '二要素認証を有効化: {email}'],
            ['settings.reservation.update', '予約ポリシー設定を更新'],
        ];

        $rows = [];
        for ($i = 0; $i < 44; $i++) {
            [$action, $tpl] = $entries[array_rand($entries)];
            $rows[] = [
                'actor_user_id' => $actorIds[array_rand($actorIds)],
                'action' => $action,
                'entity_type' => null,
                'entity_id' => null,
                'summary' => str_replace(['{email}', '{n}'], ['staff@demo.ark', (string) random_int(100, 999)], $tpl),
                'ip' => '192.0.2.'.random_int(1, 254),
                'created_at' => now()->subDays(random_int(0, 21))->subMinutes(random_int(0, 1439)),
            ];
        }

        DB::table('audit_logs')->insert($rows);
    }

    private function createSampleFailedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SyncReservationToProvider',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            ], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: 外部予約プロバイダの応答がタイムアウトしました。\n#0 [internal] demo sample",
            'failed_at' => now()->subDays(2),
        ]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @param array<string,int> $weights */
    private function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);
        $cursor = 0;
        foreach ($weights as $key => $weight) {
            $cursor += $weight;
            if ($roll <= $cursor) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function shuffled(array $items): array
    {
        shuffle($items);

        return $items;
    }

    private function printSummary(): void
    {
        $this->command?->table(['テーブル', '件数'], [
            ['users', DB::table('users')->count()],
            ['customers', DB::table('customers')->count()],
            ['staff', DB::table('staff')->count()],
            ['services', DB::table('services')->count()],
            ['staff_shifts', DB::table('staff_shifts')->count()],
            ['reservations', DB::table('reservations')->count()],
            ['payments', DB::table('payments')->count()],
            ['payment_refunds', DB::table('payment_refunds')->count()],
            ['ticket_wallets', DB::table('ticket_wallets')->count()],
            ['memberships', DB::table('memberships')->count()],
            ['audit_logs', DB::table('audit_logs')->count()],
            ['failed_jobs', DB::table('failed_jobs')->count()],
        ]);
    }
}
