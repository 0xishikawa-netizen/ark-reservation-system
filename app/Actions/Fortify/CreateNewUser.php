<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'kana' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            Customer::query()->create([
                'user_id' => $user->id,
                'kana' => $input['kana'],
                'created_via' => 'web',
            ]);

            $user->assignRole('customer');

            return $user;
        });
    }
}
