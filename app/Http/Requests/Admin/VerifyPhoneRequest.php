<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Auth\MfaPolicy;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:10'],
        ];
    }
}
