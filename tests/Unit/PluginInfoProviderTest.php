<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\PluginInfoProvider;
use MHM\PluginUpdater\VersionChecker;
use MHM\PluginUpdater\VersionCheckerInterface;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class PluginInfoProviderTest extends TestCase
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

    public function test_returns_plugin_info_for_matching_slug(): void
    {
        $release = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://example.com/zip',
            'body' => '## Changes\n- New feature',
        ];

        $checker = \Mockery::mock(VersionCheckerInterface::class);
        $checker->shouldReceive('getLatestRelease')->once()->andReturn($release);

        $provider = new PluginInfoProvider(
            slug: 'mhm-test-plugin',
            pluginName: 'MHM Test Plugin',
            repo: 'MaxHandMade/mhm-test-plugin',
            checker: $checker
        );

        $args = (object) ['slug' => 'mhm-test-plugin'];
        $result = $provider->getPluginInfo(false, 'plugin_information', $args);

        $this->assertIsObject($result);
        $this->assertSame('mhm-test-plugin', $result->slug);
        $this->assertSame('MHM Test Plugin', $result->name);
        $this->assertSame('2.0.0', $result->version);
    }

    public function test_returns_false_for_non_matching_slug(): void
    {
        $checker = \Mockery::mock(VersionCheckerInterface::class);

        $provider = new PluginInfoProvider(
            slug: 'mhm-test-plugin',
            pluginName: 'MHM Test Plugin',
            repo: 'MaxHandMade/mhm-test-plugin',
            checker: $checker
        );

        $args = (object) ['slug' => 'some-other-plugin'];
        $result = $provider->getPluginInfo(false, 'plugin_information', $args);

        $this->assertFalse($result);
    }

    public function test_returns_false_for_wrong_action(): void
    {
        $checker = \Mockery::mock(VersionCheckerInterface::class);

        $provider = new PluginInfoProvider(
            slug: 'mhm-test-plugin',
            pluginName: 'MHM Test Plugin',
            repo: 'MaxHandMade/mhm-test-plugin',
            checker: $checker
        );

        $args = (object) ['slug' => 'mhm-test-plugin'];
        $result = $provider->getPluginInfo(false, 'hot_tags', $args);

        $this->assertFalse($result);
    }
}
