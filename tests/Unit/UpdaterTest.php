<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\Updater;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class UpdaterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_init_registers_hooks(): void
    {
        Functions\expect('get_plugin_data')
            ->once()
            ->andReturn([
                'Name' => 'MHM Test Plugin',
                'Version' => '1.0.0',
            ]);

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('mhm-test-plugin/mhm-test-plugin.php');

        Functions\expect('add_filter')
            ->times(3); // pre_set_site_transient, plugins_api, upgrader_post_install

        Updater::init([
            'file' => '/path/to/mhm-test-plugin/mhm-test-plugin.php',
            'repo' => 'MaxHandMade/mhm-test-plugin',
        ]);

        $this->assertTrue(true); // Brain\Monkey expectations handle the real assertions
    }

    public function test_init_requires_file_param(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Updater::init([
            'repo' => 'MaxHandMade/mhm-test-plugin',
        ]);
    }

    public function test_init_requires_repo_param(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Updater::init([
            'file' => '/path/to/plugin.php',
        ]);
    }
}
