<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\TokenManager;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

class TokenManagerTest extends TestCase
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

    public function test_returns_token_from_init_config(): void
    {
        $manager = new TokenManager('ghp_test123');
        $this->assertSame('ghp_test123', $manager->getToken());
    }

    public function test_returns_null_when_no_token(): void
    {
        $manager = new TokenManager(null);
        $this->assertNull($manager->getToken());
    }

    public function test_has_token_returns_true_when_set(): void
    {
        $manager = new TokenManager('ghp_test123');
        $this->assertTrue($manager->hasToken());
    }

    public function test_has_token_returns_false_when_null(): void
    {
        $manager = new TokenManager(null);
        $this->assertFalse($manager->hasToken());
    }

    public function test_builds_auth_header_when_token_exists(): void
    {
        $manager = new TokenManager('ghp_test123');
        $headers = $manager->getAuthHeaders();
        $this->assertSame(['Authorization' => 'token ghp_test123'], $headers);
    }

    public function test_returns_empty_headers_when_no_token(): void
    {
        $manager = new TokenManager(null);
        $headers = $manager->getAuthHeaders();
        $this->assertSame([], $headers);
    }
}
