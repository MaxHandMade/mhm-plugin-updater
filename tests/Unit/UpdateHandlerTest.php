<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\UpdateHandler;
use MHM\PluginUpdater\VersionCheckerInterface;
use MHM\PluginUpdater\TokenManager;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class UpdateHandlerTest extends TestCase
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

    public function test_injects_update_into_transient_when_newer(): void
    {
        $transient = new \stdClass();
        $transient->response = [];
        $transient->checked = [
            'mhm-test-plugin/mhm-test-plugin.php' => '1.0.0',
        ];

        $release = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://api.github.com/repos/MaxHandMade/mhm-test-plugin/zipball/v2.0.0',
            'body' => 'New features',
        ];

        $tokenManager = new TokenManager(null);

        $checker = \Mockery::mock(VersionCheckerInterface::class);
        $checker->shouldReceive('getLatestRelease')->once()->andReturn($release);

        $handler = new UpdateHandler(
            file: 'mhm-test-plugin/mhm-test-plugin.php',
            slug: 'mhm-test-plugin',
            currentVersion: '1.0.0',
            checker: $checker,
            tokenManager: $tokenManager,
            repo: 'MaxHandMade/mhm-test-plugin'
        );

        $result = $handler->checkForUpdate($transient);

        $this->assertArrayHasKey('mhm-test-plugin/mhm-test-plugin.php', $result->response);
        $update = $result->response['mhm-test-plugin/mhm-test-plugin.php'];
        $this->assertSame('mhm-test-plugin', $update->slug);
        $this->assertSame('2.0.0', $update->new_version);
        $this->assertSame($release['zipball_url'], $update->package);
    }

    public function test_does_not_inject_when_same_version(): void
    {
        $transient = new \stdClass();
        $transient->response = [];
        $transient->checked = [
            'mhm-test-plugin/mhm-test-plugin.php' => '2.0.0',
        ];

        $release = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://example.com/zip',
            'body' => '',
        ];

        $checker = \Mockery::mock(VersionCheckerInterface::class);
        $checker->shouldReceive('getLatestRelease')->once()->andReturn($release);

        $handler = new UpdateHandler(
            file: 'mhm-test-plugin/mhm-test-plugin.php',
            slug: 'mhm-test-plugin',
            currentVersion: '2.0.0',
            checker: $checker,
            tokenManager: new TokenManager(null),
            repo: 'MaxHandMade/mhm-test-plugin'
        );

        $result = $handler->checkForUpdate($transient);

        $this->assertEmpty($result->response);
    }

    public function test_does_not_inject_when_api_fails(): void
    {
        $transient = new \stdClass();
        $transient->response = [];

        $checker = \Mockery::mock(VersionCheckerInterface::class);
        $checker->shouldReceive('getLatestRelease')->once()->andReturn(null);

        $handler = new UpdateHandler(
            file: 'mhm-test-plugin/mhm-test-plugin.php',
            slug: 'mhm-test-plugin',
            currentVersion: '1.0.0',
            checker: $checker,
            tokenManager: new TokenManager(null),
            repo: 'MaxHandMade/mhm-test-plugin'
        );

        $result = $handler->checkForUpdate($transient);

        $this->assertEmpty($result->response);
    }

    public function test_registers_auth_filter_for_private_repo(): void
    {
        $transient = new \stdClass();
        $transient->response = [];

        $release = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://api.github.com/repos/MaxHandMade/mhm-test-plugin/zipball/v2.0.0',
            'body' => 'New',
        ];

        $tokenManager = new TokenManager('ghp_secret123');

        $checker = \Mockery::mock(VersionCheckerInterface::class);
        $checker->shouldReceive('getLatestRelease')->once()->andReturn($release);

        $handler = new UpdateHandler(
            file: 'mhm-test-plugin/mhm-test-plugin.php',
            slug: 'mhm-test-plugin',
            currentVersion: '1.0.0',
            checker: $checker,
            tokenManager: $tokenManager,
            repo: 'MaxHandMade/mhm-test-plugin'
        );

        $result = $handler->checkForUpdate($transient);

        // Private repo'larda download URL'ye token eklenmemeli (header ile gidecek)
        $update = $result->response['mhm-test-plugin/mhm-test-plugin.php'];
        $this->assertStringNotContainsString('access_token', $update->package);
    }

    public function test_inject_auth_header_adds_token_for_matching_url(): void
    {
        $tokenManager = new TokenManager('ghp_secret123');
        $checker = \Mockery::mock(VersionCheckerInterface::class);

        $handler = new UpdateHandler(
            file: 'mhm-test-plugin/mhm-test-plugin.php',
            slug: 'mhm-test-plugin',
            currentVersion: '1.0.0',
            checker: $checker,
            tokenManager: $tokenManager,
            repo: 'MaxHandMade/mhm-test-plugin'
        );

        $args = ['headers' => []];
        $url = 'https://api.github.com/repos/MaxHandMade/mhm-test-plugin/zipball/v2.0.0';

        $result = $handler->injectAuthHeader($args, $url);

        $this->assertSame('token ghp_secret123', $result['headers']['Authorization']);
    }

    public function test_inject_auth_header_skips_non_matching_url(): void
    {
        $tokenManager = new TokenManager('ghp_secret123');
        $checker = \Mockery::mock(VersionCheckerInterface::class);

        $handler = new UpdateHandler(
            file: 'mhm-test-plugin/mhm-test-plugin.php',
            slug: 'mhm-test-plugin',
            currentVersion: '1.0.0',
            checker: $checker,
            tokenManager: $tokenManager,
            repo: 'MaxHandMade/mhm-test-plugin'
        );

        $args = ['headers' => []];
        $url = 'https://wordpress.org/plugins/some-plugin.zip';

        $result = $handler->injectAuthHeader($args, $url);

        $this->assertArrayNotHasKey('Authorization', $result['headers']);
    }
}
