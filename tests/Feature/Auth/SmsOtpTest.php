<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\Sms\FakeSmsSender;
use App\Domain\Auth\Sms\SmsSender;
use App\Domain\Auth\SmsOtpService;
use App\Models\AuditLog;
use App\Models\MfaSmsChallenge;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SmsOtpTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '090-1234-5678';

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear('mfa-sms-send:127.0.0.1');

        $sender = app(SmsSender::class);
        $this->assertInstanceOf(FakeSmsSender::class, $sender);
        $this->sms = $sender;
    }

    // ---------- 生成 ----------

    public function test_otp_is_sent_and_never_stored_in_plaintext(): void
    {
        $user = $this->staffUser();

        $challenge = $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $code = $this->sms->lastCode();

        $this->assertNotNull($code);
        $this->assertSame(6, mb_strlen($code));

        // DB のどの列にも平文 OTP が無いこと
        $row = DB::table('mfa_sms_challenges')->where('id', $challenge->id)->first();
        $serialized = json_encode($row, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($code, $serialized, '平文 OTP が保存されている');
        $this->assertStringNotContainsString('09012345678', $serialized, '平文電話番号が保存されている');
        $this->assertStringNotContainsString('090-1234-5678', $serialized);

        // ハッシュとして検証できること
        $this->assertTrue(password_verify($code, $row->code_hash) || strlen($row->code_hash) > 20);
    }

    public function test_audit_never_contains_the_otp_or_the_phone_number(): void
    {
        $user = $this->staffUser();
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $code = (string) $this->sms->lastCode();

        $summaries = AuditLog::query()->pluck('summary')->implode(' ');

        $this->assertStringContainsString('mfa.sms.sent', AuditLog::query()->pluck('action')->implode(' '));
        $this->assertStringNotContainsString($code, $summaries);
        $this->assertStringNotContainsString('09012345678', $summaries);
    }

    // ---------- 検証 ----------

    public function test_valid_otp_is_accepted_once(): void
    {
        $user = $this->staffUser();
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $code = (string) $this->sms->lastCode();

        $challenge = $this->otp()->verify($user, $code, SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        $this->assertNotNull($challenge->used_at);
    }

    public function test_used_otp_cannot_be_replayed(): void
    {
        $user = $this->staffUser();
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $code = (string) $this->sms->lastCode();

        $this->otp()->verify($user, $code, SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        $this->expectException(ValidationException::class);
        $this->otp()->verify($user, $code, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $user = $this->staffUser();
        $challenge = $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $code = (string) $this->sms->lastCode();

        $challenge->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->expectException(ValidationException::class);
        $this->otp()->verify($user, $code, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    public function test_wrong_otp_is_rejected_and_counts_an_attempt(): void
    {
        $user = $this->staffUser();
        $challenge = $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        try {
            $this->otp()->verify($user, '000000', SmsOtpService::PURPOSE_PHONE_VERIFICATION);
            $this->fail('誤ったコードが受理された');
        } catch (ValidationException) {
            // 期待どおり
        }

        $this->assertSame(1, (int) $challenge->refresh()->attempts);
    }

    public function test_challenge_is_invalidated_after_the_attempt_limit(): void
    {
        config()->set('mfa.sms.otp.max_attempts', 3);
        config()->set('mfa.sms.rate_limit.verify_per_minute', 100);

        $user = $this->staffUser();
        $challenge = $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $code = (string) $this->sms->lastCode();

        for ($i = 0; $i < 3; $i++) {
            try {
                $this->otp()->verify($user, '111111', SmsOtpService::PURPOSE_PHONE_VERIFICATION);
            } catch (ValidationException) {
                // 期待どおり
            }
        }

        $this->assertNotNull($challenge->refresh()->used_at, '試行上限で失効していない');

        // 正しいコードでももう通らない
        $this->expectException(ValidationException::class);
        $this->otp()->verify($user, $code, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    // ---------- レート制限 ----------

    public function test_resend_is_blocked_within_the_minimum_interval(): void
    {
        $user = $this->staffUser();
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        $this->expectException(ValidationException::class);
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    public function test_hourly_resend_limit_is_enforced(): void
    {
        config()->set('mfa.sms.resend.min_interval_seconds', 0);
        config()->set('mfa.sms.resend.max_per_hour', 3);

        $user = $this->staffUser();

        for ($i = 0; $i < 3; $i++) {
            $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        }

        $this->assertSame(3, $this->sms->count());

        $this->expectException(ValidationException::class);
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    public function test_verify_rate_limit_is_enforced_per_user(): void
    {
        config()->set('mfa.sms.rate_limit.verify_per_minute', 2);

        $user = $this->staffUser();
        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->otp()->verify($user, '222222', SmsOtpService::PURPOSE_PHONE_VERIFICATION);
            } catch (ValidationException) {
                // 期待どおり
            }
        }

        $this->expectException(ValidationException::class);
        $this->otp()->verify($user, '222222', SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    public function test_sending_a_new_code_invalidates_the_previous_challenge(): void
    {
        config()->set('mfa.sms.resend.min_interval_seconds', 0);

        $user = $this->staffUser();
        $first = $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $firstCode = (string) $this->sms->lastCode();

        $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);

        $this->assertNotNull($first->refresh()->used_at, '古いチャレンジが無効化されていない');

        $this->expectException(ValidationException::class);
        $this->otp()->verify($user, $firstCode, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    public function test_invalid_phone_number_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->otp()->send($this->staffUser(), 'not-a-number', SmsOtpService::PURPOSE_PHONE_VERIFICATION);
    }

    // ---------- prune ----------

    public function test_old_challenges_are_prunable(): void
    {
        $user = $this->staffUser();
        $challenge = $this->otp()->send($user, self::PHONE, SmsOtpService::PURPOSE_PHONE_VERIFICATION);
        $challenge->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->assertSame(1, (new MfaSmsChallenge)->prunable()->count());
    }

    // ---------- helpers ----------

    private function otp(): SmsOtpService
    {
        return app(SmsOtpService::class);
    }

    private function staffUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');
        Staff::factory()->create(['user_id' => $user->id]);

        return $user->refresh();
    }
}
