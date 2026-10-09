<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * ログイン中の利用者・顧客を取り出す（Task 11-34 共通化）。
 * 取れない時は 403 で止める（ルートの auth ミドルウェアを通っていれば通常は起きない）。
 */
trait ResolvesAuthenticatedUser
{
    private function userFor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    /** ログイン中の利用者の顧客レコード。顧客でなければ 403。 */
    private function customerFor(Request $request): Customer
    {
        $customer = $this->userFor($request)->customer;

        if (! $customer instanceof Customer) {
            abort(403);
        }

        return $customer;
    }
}
