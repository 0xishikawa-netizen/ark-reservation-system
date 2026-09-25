<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmploymentType;
use App\Models\PaymentMethod;
use App\Models\ServiceAnalysisCategory;
use App\Models\TaxCategory;
use Illuminate\Database\Seeder;

class Phase11MasterSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'M', 'name' => 'マッサージ', 'sort_order' => 10],
            ['code' => 'T', 'name' => 'トレーニング', 'sort_order' => 20],
            ['code' => 'A', 'name' => 'はり', 'sort_order' => 30],
            ['code' => 'M&T', 'name' => 'マッサージ＋トレーニング', 'sort_order' => 40],
            ['code' => 'A&T', 'name' => 'はり＋トレーニング', 'sort_order' => 50],
        ] as $definition) {
            ServiceAnalysisCategory::query()->firstOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'is_active' => true],
            );
        }

        foreach ([
            ['code' => 'standard', 'name' => '標準税率', 'sort_order' => 10],
            ['code' => 'reduced', 'name' => '軽減税率', 'sort_order' => 20],
            ['code' => 'exempt', 'name' => '非課税', 'sort_order' => 30],
            ['code' => 'out_of_scope', 'name' => '対象外', 'sort_order' => 40],
        ] as $definition) {
            // 税率そのものは業務確認前にseedしない。
            TaxCategory::query()->firstOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'is_active' => true],
            );
        }

        foreach ([
            ['code' => 'cash', 'name' => '現金', 'display_order' => 10, 'external_provider' => null],
            ['code' => 'paypay', 'name' => 'PayPay', 'display_order' => 20, 'external_provider' => 'paypay'],
            ['code' => 'airpay', 'name' => 'AirPAY', 'display_order' => 30, 'external_provider' => 'airpay'],
            ['code' => 'square', 'name' => 'Square', 'display_order' => 40, 'external_provider' => 'square'],
            ['code' => 'smart_payment', 'name' => 'スマート払い', 'display_order' => 50, 'external_provider' => null],
            ['code' => 'gift_certificate', 'name' => '商品券', 'display_order' => 60, 'external_provider' => null],
            ['code' => 'id', 'name' => 'iD', 'display_order' => 70, 'external_provider' => 'id'],
            ['code' => 'stripe', 'name' => 'Stripe', 'display_order' => 80, 'external_provider' => 'stripe'],
            ['code' => 'other', 'name' => 'その他', 'display_order' => 90, 'external_provider' => null],
        ] as $definition) {
            PaymentMethod::query()->firstOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'is_enabled' => true],
            );
        }

        foreach ([
            ['code' => 'employee', 'name' => '社員', 'sort_order' => 10],
            ['code' => 'part_time', 'name' => 'アルバイト', 'sort_order' => 20],
        ] as $definition) {
            EmploymentType::query()->firstOrCreate(
                ['code' => $definition['code']],
                [...$definition, 'is_active' => true],
            );
        }
    }
}
