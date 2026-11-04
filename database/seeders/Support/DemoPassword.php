<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use RuntimeException;

/**
 * デモ／開発用アカウントのパスワード（Task 11-36）。
 *
 * - local / development / testing：従来どおり 'password'（ローカル専用の既定値）。
 * - staging：STAGING_DEMO_PASSWORD を必須にする（公開 URL に既定値のアカウントを作らない）。
 */
final class DemoPassword
{
    public static function resolve(string $localDefault = 'password'): string
    {
        if (! app()->environment('staging')) {
            return $localDefault;
        }

        $password = (string) config('staging.demo_password', '');
        $min = (int) config('staging.demo_password_min_length', 12);

        if (mb_strlen($password) < $min) {
            throw new RuntimeException(__('messages.staging.demo_password_missing', ['min' => $min]));
        }

        return $password;
    }
}
