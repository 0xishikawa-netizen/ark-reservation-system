<?php

declare(strict_types=1);

namespace Tests\Feature\Validation;

use App\Http\Requests\Admin\StartPhoneVerificationRequest;
use App\Http\Requests\Booking\SendGuestLookupCodeRequest;
use App\Http\Requests\Booking\StoreGuestReservationRequest;
use App\Http\Requests\Booking\VerifyGuestLookupCodeRequest;
use App\Rules\JapanesePhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class JapanesePhoneNumberTest extends TestCase
{
    /** @var list<class-string<FormRequest>> */
    private const REQUEST_CLASSES = [
        StoreGuestReservationRequest::class,
        VerifyGuestLookupCodeRequest::class,
        SendGuestLookupCodeRequest::class,
        StartPhoneVerificationRequest::class,
    ];

    #[DataProvider('validPhoneNumbers')]
    public function test_all_phone_requests_accept_valid_japanese_numbers(string $phone): void
    {
        foreach (self::REQUEST_CLASSES as $requestClass) {
            $validator = Validator::make(
                ['phone' => $phone],
                ['phone' => $this->phoneRules($requestClass)],
            );

            $this->assertFalse($validator->fails(), $requestClass);
        }
    }

    /** @return array<string, array{string}> */
    public static function validPhoneNumbers(): array
    {
        return [
            '10-digit landline' => ['03-1234-5678'],
            '11-digit mobile' => ['090-1234-5678'],
        ];
    }

    #[DataProvider('invalidPhoneNumbers')]
    public function test_all_phone_requests_reject_invalid_digit_counts_and_prefixes(string $phone): void
    {
        foreach (self::REQUEST_CLASSES as $requestClass) {
            $validator = Validator::make(
                ['phone' => $phone],
                ['phone' => $this->phoneRules($requestClass)],
            );

            $this->assertTrue($validator->fails(), $requestClass);
            $this->assertSame(
                '電話番号の形式が正しくありません。',
                $validator->errors()->first('phone'),
                $requestClass,
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidPhoneNumbers(): array
    {
        return [
            'too short' => ['03-123-4567'],
            'previously accepted 12-digit formatted number' => ['090-12-3456789'],
            'not zero prefixed' => ['90-1234-5678'],
            'international format is unsupported' => ['+81-90-1234-5678'],
        ];
    }

    /**
     * @param  class-string<FormRequest>  $requestClass
     * @return list<mixed>
     */
    private function phoneRules(string $requestClass): array
    {
        $rules = (new $requestClass)->rules()['phone'];

        $this->assertCount(
            1,
            array_filter($rules, static fn (mixed $rule): bool => $rule instanceof JapanesePhoneNumber),
            $requestClass,
        );

        return $rules;
    }
}
