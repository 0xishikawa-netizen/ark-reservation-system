<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 顧客カルテの分析項目（来店動機・来店目的・紹介者・都道府県・市区町村）を更新する（Task 11-21）。
 * 監査ログには変更項目名だけを残し、紹介者名などの値は記録しない。
 */
final class UpdateCustomerKarte
{
    private const FIELDS = ['acquisition_channel_id', 'acquisition_note', 'visit_purpose_note', 'referrer_customer_id', 'referrer_name', 'prefecture', 'city'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $data */
    public function execute(Customer $customer, array $data, ?Authenticatable $actor): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor): Customer {
            if (isset($data['referrer_customer_id']) && (int) $data['referrer_customer_id'] === (int) $customer->user_id) {
                throw ValidationException::withMessages(['referrer_customer_id' => __('messages.customer.referrer_self')]);
            }
            $changed = [];
            foreach (self::FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $value = is_string($data[$field]) ? (trim($data[$field]) === '' ? null : trim($data[$field])) : $data[$field];
                if ($customer->getAttribute($field) != $value) {
                    $customer->setAttribute($field, $value);
                    $changed[] = $field;
                }
            }
            $customer->save();
            if (array_key_exists('visit_purpose_ids', $data)) {
                $result = $customer->visitPurposes()->sync(array_map('intval', $data['visit_purpose_ids'] ?? []));
                if ($result['attached'] !== [] || $result['detached'] !== []) {
                    $changed[] = 'visit_purpose_ids';
                }
            }
            $this->auditLogger->log(
                'customer.karte_updated',
                $customer,
                $changed === [] ? '更新項目なし' : '更新項目: '.implode(', ', $changed),
                $actor,
            );

            return $customer->refresh();
        });
    }
}
