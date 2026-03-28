<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\VersionChecker;
use MHM\PluginUpdater\TokenManager;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class VersionCheckerTest extends TestCase
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

    public function test_strips_v_prefix_from_tag(): void
    {
        $this->assertSame('1.2.3', VersionChecker::normalizeVersion('v1.2.3'));
    }

    public function test_keeps_version_without_prefix(): void
    {
        $this->assertSame('1.2.3', VersionChecker::normalizeVersion('1.2.3'));
    }

    public function test_detects_newer_version(): void
    {
        $this->assertTrue(VersionChecker::isNewer('2.0.0', '1.0.0'));
    }

    public function test_detects_same_version(): void
    {
        $this->assertFalse(VersionChecker::isNewer('1.0.0', '1.0.0'));
    }

    public function test_detects_older_version(): void
    {
        $this->assertFalse(VersionChecker::isNewer('0.9.0', '1.0.0'));
    }

    public function test_returns_release_data_from_cache(): void
    {
        $cachedData = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://api.github.com/repos/Owner/Repo/zipball/v2.0.0',
            'body' => '## Changelog',
        ];

        Functions\expect('get_transient')
            ->once()
            ->with('mhm_updater_owner-repo')
            ->andReturn($cachedData);

        $tokenManager = new TokenManager(null);
        $checker = new VersionChecker('Owner/Repo', $tokenManager);
        $result = $checker->getLatestRelease();

        $this->assertSame($cachedData, $result);
    }

    public function test_fetches_from_github_when_no_cache(): void
    {
        $apiResponse = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://api.github.com/repos/Owner/Repo/zipball/v2.0.0',
            'body' => '## Changelog',
        ];

        Functions\expect('get_transient')
            ->once()
            ->with('mhm_updater_owner-repo')
            ->andReturn(false);

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(['response' => ['code' => 200], 'body' => json_encode($apiResponse)]);

        Functions\expect('is_wp_error')
            ->once()
            ->andReturn(false);

        Functions\expect('wp_remote_retrieve_response_code')
            ->once()
            ->andReturn(200);

        Functions\expect('wp_remote_retrieve_body')
            ->once()
            ->andReturn(json_encode($apiResponse));

        Functions\expect('set_transient')
            ->once()
            ->with('mhm_updater_owner-repo', \Mockery::type('array'), 43200);

        $tokenManager = new TokenManager(null);
        $checker = new VersionChecker('Owner/Repo', $tokenManager);
        $result = $checker->getLatestRelease();

        $this->assertSame('v2.0.0', $result['tag_name']);
    }

    public function test_returns_null_on_api_error(): void
    {
        Functions\expect('get_transient')
            ->once()
            ->andReturn(false);

        Functions\expect('wp_remote_get')
            ->once()
            ->andReturn(new \WP_Error('http_error', 'Connection failed'));

        Functions\expect('is_wp_error')
            ->once()
            ->andReturn(true);

        $tokenManager = new TokenManager(null);
        $checker = new VersionChecker('Owner/Repo', $tokenManager);
        $result = $checker->getLatestRelease();

        $this->assertNull($result);
    }
}
