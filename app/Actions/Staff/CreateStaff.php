<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

class CreateStaff
{
    /**
     * @param  array{
     *     name: string,
     *     email: string,
     *     display_name: string,
     *     role: 'staff'|'manager',
     *     color?: string|null,
     *     is_bookable?: bool
     * }  $input
     */
    public function create(array $input): User
    {
        $user = DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Str::password(32),
            ]);

            // 管理者が登録したメールアドレスは招待先として確定済みとする。
            $user->markEmailAsVerified();

            Staff::query()->create([
                'user_id' => $user->id,
                'display_name' => $input['display_name'],
                'color' => $input['color'] ?? '#888888',
                'is_bookable' => $input['is_bookable'] ?? true,
                'sort_order' => 0,
            ]);

            $user->assignRole($input['role']);

            return $user;
        });

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw new RuntimeException("Failed to send the staff password reset link: {$status}");
        }

        return $user;
    }
}
