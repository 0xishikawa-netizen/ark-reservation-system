<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 電話予約などで、まだ会員登録していないお客様の予約を台帳から取るための「仮登録」。
 *
 * 電話口では「名前が聞き取れない」「漢字が分からない」ことが普通にあるため、
 * 氏名・カナ・電話番号のうち **分かったものだけ** で作れるようにしている。
 * 後から顧客詳細画面で正しい氏名・カナ・連絡先に上書きする運用を前提とする。
 *
 * パスワードは作らない（本人がWeb登録した時に正式な会員になる）。メール未入力時は
 * users.email の NOT NULL + unique を満たすため、RFC 2606 で予約されている .invalid の
 * ダミー値を使うので、実在アドレスと衝突したり誤送信したりすることはない。
 */
final readonly class CreateProvisionalCustomer
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * @param  array{name?: string|null, kana?: string|null, phone?: string|null, gender?: string|null}  $input
     */
    public function execute(array $input, ?Authenticatable $actor = null): Customer
    {
        return $this->create($input, 'admin', $actor);
    }

    /**
     * @param  array{name: string, phone: string, email?: string|null}  $input
     */
    public function executeGuest(array $input): Customer
    {
        return $this->create($input, 'web', null);
    }

    /**
     * @param  array{name?: string|null, kana?: string|null, phone?: string|null, email?: string|null, gender?: string|null}  $input
     */
    private function create(array $input, string $createdVia, ?Authenticatable $actor): Customer
    {
        $name = $this->normalize($input['name'] ?? null);
        $kana = $this->normalize($input['kana'] ?? null);
        $phone = $this->normalize($input['phone'] ?? null);
        $gender = $this->normalize($input['gender'] ?? null);
        $email = $this->normalize($input['email'] ?? null);
        $email = $email === null ? null : Str::lower($email);

        if ($email !== null && $this->emailExists($email)) {
            $this->throwEmailCollision();
        }

        try {
            return DB::transaction(function () use ($name, $kana, $phone, $gender, $email, $createdVia, $actor): Customer {
                $user = User::query()->create([
                    'name' => $name ?? $this->fallbackName($kana, $phone),
                    'email' => $email ?? $this->placeholderEmail(),
                    'password' => null,
                ]);

                $user->assignRole('customer');

                $customer = Customer::query()->create([
                    'user_id' => $user->id,
                    // customers.kana は NOT NULL。聞けていない場合は空文字で作り、後から埋める。
                    'kana' => $kana ?? '',
                    'phone' => $phone,
                    'gender' => $gender,
                    'created_via' => $createdVia,
                ]);

                $this->auditLogger->log(
                    'customer.provisional_created',
                    $customer,
                    "仮登録の顧客を作成 #{$user->id} {$user->name}",
                    $actor,
                );

                return $customer;
            });
        } catch (QueryException $exception) {
            // 事前確認との間に同じメールが登録された競合も、DB例外を利用者へ露出させない。
            if ($email !== null
                && (string) $exception->getCode() === '23000'
                && $this->emailExists($email)) {
                $this->throwEmailCollision();
            }

            throw $exception;
        }
    }

    private function normalize(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** 名前が聞けていない時の表示名。誰の予約か後から辿れる手掛かりを残す。 */
    private function fallbackName(?string $kana, ?string $phone): string
    {
        if ($kana !== null) {
            return $kana;
        }

        if ($phone !== null) {
            return "未確認（{$phone}）";
        }

        return '未確認のお客様';
    }

    private function placeholderEmail(): string
    {
        return 'provisional+'.Str::lower((string) Str::ulid()).'@ark.invalid';
    }

    private function emailExists(string $email): bool
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->exists();
    }

    private function throwEmailCollision(): never
    {
        throw ValidationException::withMessages([
            'email' => __('messages.auth.email_already_registered'),
        ]);
    }
}
