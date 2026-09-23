<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Auth\MfaPolicy;
use App\Rules\JapanesePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 電話番号の登録・変更は機微操作（PLAN §12）。
 * ルート側で password.confirm を要求する。
 */
class StartPhoneVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && app(MfaPolicy::class)->isRequiredFor($user)
            && $user->staff !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // 国内番号を想定。ハイフン有無を許容し、正規化は PiiHasher が行う。
            'phone' => ['required', 'string', 'max:20', new JapanesePhoneNumber],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => __('messages.otp.invalid_phone'),
        ];
    }
}
