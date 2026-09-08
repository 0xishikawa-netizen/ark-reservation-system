<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Auth\MfaPolicy;
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
            // 国内の携帯番号を想定。ハイフン有無を許容し、正規化は PiiHasher が行う。
            'phone' => ['required', 'string', 'max:20', 'regex:/\A[0-9+\-() ]{10,20}\z/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => '電話番号の形式が正しくありません。',
        ];
    }
}
