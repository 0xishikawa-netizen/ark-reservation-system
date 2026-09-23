<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Security\PiiHasher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class JapanesePhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('messages.otp.invalid_phone'));

            return;
        }

        $normalized = PiiHasher::normalizePhone($value);

        if ($normalized === null
            || ! in_array(strlen($normalized), [10, 11], true)
            || ! str_starts_with($normalized, '0')) {
            $fail(__('messages.otp.invalid_phone'));
        }
    }
}
