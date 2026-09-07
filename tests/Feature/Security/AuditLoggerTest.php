<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_is_truncated_to_500_characters_without_suffix(): void
    {
        app(AuditLogger::class)->log(
            'test.summary_truncation',
            summary: str_repeat('監', 501),
            actor: null,
        );

        $auditLog = AuditLog::query()->sole();

        $this->assertSame(500, mb_strlen($auditLog->summary));
        $this->assertSame(str_repeat('監', 500), $auditLog->summary);
    }
}
