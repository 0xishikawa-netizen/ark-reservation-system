<?php

declare(strict_types=1);

namespace Tests\Feature\Staging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 開発用メニューの「HP デザイン案（実装イメージ）」（Task 11-38）。
 */
final class HpDesignTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/hp-designs-'.uniqid());
        File::ensureDirectoryExists($this->base.'/B案');
        File::ensureDirectoryExists($this->base.'/A案/img');
        File::put($this->base.'/A案/index.html', '<html><link rel="stylesheet" href="style.css">A</html>');
        File::put($this->base.'/A案/style.css', 'body{}');
        File::put($this->base.'/B案/mock.pdf', '%PDF-1.4');
        File::put($this->base.'/C案.url', "https://www.canva.com/design/example\n");
        File::put($this->base.'/悪い例.url', 'javascript:alert(1)');
        File::put($this->base.'/README.md', 'not a design');
        File::put(dirname($this->base).'/secret.txt', 'outside');

        config([
            'staging.hp_designs_path' => $this->base,
            'staging.dev_menu' => true,
            'staging.basic_auth.user' => null,
        ]);
        $this->app['env'] = 'staging';
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        File::deleteDirectory($this->base);
        File::delete(dirname($this->base).'/secret.txt');

        parent::tearDown();
    }

    public function test_dev_menu_lists_folders_and_links_in_name_order(): void
    {
        $this->get('/dev-menu')->assertOk()->assertInertia(fn ($page) => $page
            ->has('hpDesigns', 3)
            ->where('hpDesigns.0.name', 'A案')
            ->where('hpDesigns.0.url', '/hp-designs/'.rawurlencode('A案').'/index.html')
            ->where('hpDesigns.0.external', false)
            ->where('hpDesigns.1.url', '/hp-designs/'.rawurlencode('B案').'/mock.pdf')
            ->where('hpDesigns.2.name', 'C案')
            ->where('hpDesigns.2.url', 'https://www.canva.com/design/example')
            ->where('hpDesigns.2.external', true));
    }

    public function test_design_files_are_served_without_the_ark_csp(): void
    {
        $this->get('/hp-designs/A案/index.html')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeaderMissing('Content-Security-Policy');

        $this->get('/hp-designs/A案/style.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get('/hp-designs/A案')->assertOk();
    }

    public function test_paths_outside_the_design_folder_are_not_served(): void
    {
        $this->get('/hp-designs/A案/../../secret.txt')->assertNotFound();
        $this->get('/hp-designs/..%2Fsecret.txt')->assertNotFound();
        $this->get('/hp-designs/存在しない/index.html')->assertNotFound();
        $this->get('/hp-designs/A案/nope.html')->assertNotFound();
    }

    public function test_designs_are_hidden_outside_local_and_staging(): void
    {
        $this->app['env'] = 'production';

        $this->get('/hp-designs/A案/index.html')->assertNotFound();
    }
}
