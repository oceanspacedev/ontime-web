<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Horizon and the Log Viewer are wired the same way as dnd-web, gais-web and helpdesk-web.
 */
class ObservabilityPackagesTest extends TestCase
{
    public function test_composer_requires_horizon_and_log_viewer(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertArrayHasKey('laravel/horizon', $composer['require']);
        $this->assertArrayHasKey('opcodesio/log-viewer', $composer['require']);
    }

    public function test_providers_and_support_classes_are_wired(): void
    {
        $root = dirname(__DIR__, 2);
        $providers = (string) file_get_contents($root.'/bootstrap/providers.php');

        $this->assertFileExists($root.'/app/Providers/HorizonServiceProvider.php');
        $this->assertFileExists($root.'/app/Support/ObservabilityAccess.php');
        $this->assertFileExists($root.'/config/horizon.php');
        $this->assertFileExists($root.'/config/log-viewer.php');
        $this->assertStringContainsString('HorizonServiceProvider::class', $providers);
        $this->assertStringContainsString('ObservabilityAccess::filamentNavigationItems()', (string) file_get_contents($root.'/app/Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringContainsString('horizon:snapshot', (string) file_get_contents($root.'/routes/console.php'));
        $this->assertStringContainsString("'queue' => ['notifications', 'default', 'exports']", (string) file_get_contents($root.'/config/horizon.php'));
        $this->assertStringContainsString('php artisan horizon', (string) file_get_contents($root.'/composer.json'));
    }
}
