<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\DailyBusinessNote;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DailyBusinessNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_narratives_can_be_saved_separately_together_with_newlines_and_updated(): void
    {
        $admin = $this->admin();
        foreach ([
            ['business_condition' => "午前は静か\n夕方に集中", 'reflection' => null],
            ['business_condition' => null, 'reflection' => "案内を改善\n明日確認"],
            ['business_condition' => '予約多め', 'reflection' => '次回予約を案内'],
            ['business_condition' => null, 'reflection' => null],
        ] as $index => $data) {
            $date = sprintf('2026-09-%02d', $index + 1);
            $this->actingAs($admin)->put(route('admin.reports.daily-notes.update', $date), $data)->assertRedirect();
            $note = DailyBusinessNote::query()->whereDate('business_date', $date)->firstOrFail();
            $this->assertSame($data['business_condition'], $note->business_condition);
            $this->assertSame($data['reflection'], $note->reflection);
            $this->assertSame($admin->id, $note->created_by);
            $this->assertSame($admin->id, $note->updated_by);
        }
        $this->actingAs($admin)->put(route('admin.reports.daily-notes.update', '2026-09-01'), [
            'business_condition' => '変更後', 'reflection' => '再確認',
        ])->assertRedirect();
        $this->assertSame(4, DailyBusinessNote::query()->count());
        $this->assertDatabaseHas('daily_business_notes', ['business_date' => '2026-09-01', 'business_condition' => '変更後']);
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'reports.daily_note_updated')->count());
        $this->actingAs($admin)->get(route('admin.reports.daily-notes', ['month' => '2026-09']))
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Admin/Reports/DailyNotes')
            ->where('editable', true)
            ->where('days.0.business_condition', '変更後')
            ->where('days.0.reflection', '再確認'));
    }

    public function test_viewer_can_read_but_cannot_edit_and_staff_cannot_view(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $viewer->givePermissionTo(['admin.access', 'reports.view']);
        $this->actingAs($viewer)->get(route('admin.reports.daily-notes', ['month' => '2026-09']))
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page->where('editable', false));
        $this->actingAs($viewer)->put(route('admin.reports.daily-notes.update', '2026-09-01'), [
            'business_condition' => '禁止', 'reflection' => '禁止',
        ])->assertForbidden();
        $this->assertSame(0, DailyBusinessNote::query()->count());

        $staff = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('admin.reports.daily-notes'))->assertForbidden();
    }

    public function test_invalid_month_date_and_oversized_text_are_rejected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.reports.daily-notes', ['month' => '2026-13']))->assertSessionHasErrors();
        $this->actingAs($admin)->put(route('admin.reports.daily-notes.update', '2026-02-30'), [
            'business_condition' => 'invalid',
        ])->assertSessionHasErrors();
        $this->actingAs($admin)->put(route('admin.reports.daily-notes.update', '2026-09-01'), [
            'business_condition' => str_repeat('x', 10001),
        ])->assertSessionHasErrors();
        $this->assertSame(0, DailyBusinessNote::query()->count());
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        return $admin;
    }
}
