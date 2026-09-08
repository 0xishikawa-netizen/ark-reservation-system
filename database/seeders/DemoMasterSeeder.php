<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Booth;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DemoMasterSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoMasterSeeder は local 環境専用です。スキップします。');

            return;
        }

        $this->call(RolePermissionSeeder::class);

        $admin = $this->createAdmin();
        $staff = $this->createStaff();

        $this->createServices($staff);
        $this->createBooths();
        $this->createStaffShifts($staff);
        $this->createCustomers();

        // admin も staff テーブルとの 1:1 関係を持つが、予約担当には含めない。
        Staff::query()->updateOrCreate(
            ['user_id' => $admin->id],
            [
                'display_name' => 'デモ管理者',
                'color' => '#455a64',
                'is_bookable' => false,
                'sort_order' => 0,
            ],
        );
    }

    private function createAdmin(): User
    {
        $admin = $this->upsertUser('デモ管理者', 'admin@ark.local');
        $admin->syncRoles('admin');

        // 自動生成した秘密は利用者が確認できないため、初回ログイン時に Fortify の画面で 2FA を設定する。
        // two_factor_confirmed_at は意図的に設定せず、EnsureStaffTwoFactor の案内を通す。

        return $admin;
    }

    /** @return list<Staff> */
    private function createStaff(): array
    {
        $definitions = [
            [
                'name' => 'デモスタッフ 山田',
                'email' => 'staff1@ark.local',
                'display_name' => '山田',
                'color' => '#1976d2',
                'sort_order' => 10,
            ],
            [
                'name' => 'デモスタッフ 佐藤',
                'email' => 'staff2@ark.local',
                'display_name' => '佐藤',
                'color' => '#e91e63',
                'sort_order' => 20,
            ],
        ];

        $staff = [];

        foreach ($definitions as $definition) {
            $user = $this->upsertUser($definition['name'], $definition['email']);
            $user->syncRoles('staff');

            $staff[] = Staff::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $definition['display_name'],
                    'color' => $definition['color'],
                    'is_bookable' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );
        }

        return $staff;
    }

    /** @param list<Staff> $staff */
    private function createServices(array $staff): void
    {
        $definitions = [
            [
                'name' => 'パーソナル 60 分',
                'duration_min' => 60,
                'price' => 8800,
                'category' => 'パーソナル',
                'color' => '#1976d2',
                'sort_order' => 10,
            ],
            [
                'name' => 'コンディショニング 30 分',
                'duration_min' => 30,
                'price' => 4400,
                'category' => 'コンディショニング',
                'color' => '#43a047',
                'sort_order' => 20,
            ],
        ];
        $staffIds = array_map(
            static fn (Staff $member): int => (int) $member->user_id,
            $staff,
        );

        foreach ($definitions as $definition) {
            $service = Service::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'duration_min' => $definition['duration_min'],
                    'price' => $definition['price'],
                    'category' => $definition['category'],
                    'is_online_bookable' => true,
                    'requires_staff' => true,
                    'color' => $definition['color'],
                    'is_active' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );

            $service->staff()->sync($staffIds);
        }
    }

    private function createBooths(): void
    {
        foreach ([
            ['name' => 'ブース A', 'sort_order' => 10],
            ['name' => 'ブース B', 'sort_order' => 20],
        ] as $definition) {
            Booth::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'sort_order' => $definition['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }

    /** @param list<Staff> $staff */
    private function createStaffShifts(array $staff): void
    {
        $weekStart = Carbon::now()->startOfWeek();
        $definitions = [
            ['day' => 0, 'start_at' => '10:00', 'end_at' => '14:00'],
            ['day' => 2, 'start_at' => '15:00', 'end_at' => '19:00'],
        ];

        foreach ($staff as $member) {
            foreach ($definitions as $definition) {
                StaffShift::query()->updateOrCreate(
                    [
                        'staff_id' => $member->user_id,
                        'start_at' => $definition['start_at'],
                    ],
                    [
                        'work_date' => $weekStart->copy()->addDays($definition['day'])->toDateString(),
                        'end_at' => $definition['end_at'],
                    ],
                );
            }
        }
    }

    private function createCustomers(): void
    {
        $definitions = [
            [
                'name' => '予約 太郎',
                'email' => 'customer1@ark.local',
                'kana' => 'ヨヤク タロウ',
                'phone' => '090-1234-5678',
                'birthday' => '1990-04-15',
                'gender' => 'male',
            ],
            [
                'name' => '予約 花子',
                'email' => 'customer2@ark.local',
                'kana' => 'ヨヤク ハナコ',
                'phone' => '080-9876-5432',
                'birthday' => '1995-10-20',
                'gender' => 'female',
            ],
        ];

        foreach ($definitions as $definition) {
            $user = $this->upsertUser($definition['name'], $definition['email']);
            $user->syncRoles('customer');

            // updateOrCreate で Eloquent の saving フックを通し、phone_hmac も再計算する。
            Customer::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'kana' => $definition['kana'],
                    'phone' => $definition['phone'],
                    'birthday' => $definition['birthday'],
                    'gender' => $definition['gender'],
                    'note' => null,
                    'created_via' => 'demo',
                ],
            );
        }
    }

    private function upsertUser(string $name, string $email): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
            ],
        );

        $attributes = [
            'name' => $name,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ];

        if (! Hash::check('password', $user->password)) {
            $attributes['password'] = Hash::make('password');
        }

        $user->forceFill($attributes)->save();

        return $user;
    }
}
