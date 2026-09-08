<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateCustomerProfile
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param  array<string, mixed>  $data */
    public function execute(
        Customer $customer,
        array $data,
        ?Authenticatable $actor = null,
    ): Customer {
        return DB::transaction(function () use ($customer, $data, $actor): Customer {
            $changedFields = [];
            $user = $customer->user()->firstOrFail();

            if (array_key_exists('name', $data)) {
                $user->name = (string) $data['name'];

                if ($user->isDirty('name')) {
                    $changedFields[] = 'name';
                    $user->save();
                }
            }

            $attributes = [];

            foreach (['kana', 'phone', 'birthday', 'gender', 'note'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $newValue = $field === 'birthday' && $data[$field] !== null
                    ? Carbon::parse((string) $data[$field])->toDateString()
                    : $data[$field];
                $currentValue = $field === 'birthday'
                    ? $customer->birthday?->toDateString()
                    : $customer->getAttribute($field);

                if ($currentValue === $newValue) {
                    continue;
                }

                $attributes[$field] = $newValue;
                $changedFields[] = $field;
            }

            if ($attributes !== []) {
                $customer->fill($attributes);
                $customer->save();
            }

            $this->auditLogger->log(
                'customer.profile_updated',
                $customer,
                $changedFields === []
                    ? '更新項目なし'
                    : '更新項目: '.implode(', ', array_values(array_unique($changedFields))),
                $actor ?? auth()->user(),
            );

            return $customer->refresh();
        });
    }
}
