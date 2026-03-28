# MHM Plugin Updater — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** GitHub Releases API üzerinden WordPress eklentilerine güncelleme bildirimi ve tek tıkla güncelleme sağlayan bağımsız bir Composer paketi oluşturmak.

**Architecture:** Bağımsız Composer paketi (`MHM\PluginUpdater` namespace). Her eklenti `Updater::init()` ile kaydolur. Paket `pre_set_site_transient_update_plugins`, `plugins_api` ve `upgrader_post_install` hook'larıyla WordPress güncelleme sistemine entegre olur. GitHub API sonuçları transient ile cache'lenir.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, GitHub REST API v3, Composer PSR-4 autoload, PHPUnit

**Design Doc:** `docs/plans/2026-03-28-mhm-plugin-updater-design.md`

---

## Task 1: GitHub Repo ve Composer Paketi Oluştur

**Files:**
- Create: `composer.json`
- Create: `src/` (boş dizin, autoload için)
- Create: `LICENSE`

**Step 1: GitHub'da repo oluştur**

```bash
gh repo create MaxHandMade/mhm-plugin-updater --public --description "GitHub-based auto-updater for MHM WordPress plugins" --clone
cd mhm-plugin-updater
```

**Step 2: composer.json oluştur**

```json
{
    "name": "maxhandmade/mhm-plugin-updater",
    "description": "GitHub-based auto-updater for MHM WordPress plugins",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "minimum-stability": "stable",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "brain/monkey": "^2.6",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": {
            "MHM\\PluginUpdater\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "MHM\\PluginUpdater\\Tests\\": "tests/"
        }
    }
}
```

**Step 3: PHPUnit config oluştur**

Create `phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

Create `tests/bootstrap.php`:
```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Brain\Monkey setup for WordPress function mocking
\Brain\Monkey\setUp();
```

**Step 4: Bağımlılıkları yükle**

```bash
composer install
```

**Step 5: Commit**

```bash
git add composer.json phpunit.xml tests/bootstrap.php LICENSE
git commit -m "chore: initialize Composer package with PHPUnit and Brain\Monkey"
git push -u origin main
```

---

## Task 2: TokenManager — GitHub Token Yönetimi

**Files:**
- Create: `src/TokenManager.php`
- Create: `tests/Unit/TokenManagerTest.php`

**Step 1: Failing test yaz**

```php
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
```

**Step 2: Test'i çalıştır, FAIL olduğunu doğrula**

```bash
vendor/bin/phpunit tests/Unit/TokenManagerTest.php -v
```
Expected: FAIL — `TokenManager` class not found

**Step 3: Minimal implementasyon**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class TokenManager
{
    public function __construct(
        private readonly ?string $token = null
    ) {}

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * @return array<string, string>
     */
    public function getAuthHeaders(): array
    {
        if (!$this->hasToken()) {
            return [];
        }

        return ['Authorization' => 'token ' . $this->token];
    }
}
```

**Step 4: Test'i çalıştır, PASS olduğunu doğrula**

```bash
vendor/bin/phpunit tests/Unit/TokenManagerTest.php -v
```
Expected: 6 tests, 6 assertions, PASS

**Step 5: Commit**

```bash
git add src/TokenManager.php tests/Unit/TokenManagerTest.php
git commit -m "feat: add TokenManager for GitHub token handling"
```

---

## Task 3: VersionChecker — GitHub API Sorgusu ve Versiyon Karşılaştırma

**Files:**
- Create: `src/VersionChecker.php`
- Create: `tests/Unit/VersionCheckerTest.php`

**Step 1: Failing test yaz**

```php
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
```

Not: `WP_Error` mock'u için `tests/bootstrap.php`'ye ekle:
```php
// Minimal WP_Error stub for unit tests
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(public string $code = '', public string $message = '') {}
    }
}
```

**Step 2: Test'i çalıştır, FAIL doğrula**

```bash
vendor/bin/phpunit tests/Unit/VersionCheckerTest.php -v
```

**Step 3: Implementasyon**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class VersionChecker
{
    private const API_BASE = 'https://api.github.com/repos/';
    private const CACHE_TTL = 43200; // 12 hours

    private string $cacheKey;

    public function __construct(
        private readonly string $repo,
        private readonly TokenManager $tokenManager
    ) {
        $this->cacheKey = 'mhm_updater_' . str_replace('/', '-', strtolower($repo));
    }

    public static function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }

    public static function isNewer(string $remote, string $local): bool
    {
        return version_compare(
            self::normalizeVersion($remote),
            self::normalizeVersion($local),
            'gt'
        );
    }

    /**
     * @return array{tag_name: string, zipball_url: string, body: string}|null
     */
    public function getLatestRelease(): ?array
    {
        $cached = get_transient($this->cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $url = self::API_BASE . $this->repo . '/releases/latest';

        $args = [
            'headers' => array_merge(
                ['Accept' => 'application/vnd.github.v3+json'],
                $this->tokenManager->getAuthHeaders()
            ),
            'timeout' => 10,
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['tag_name'])) {
            return null;
        }

        $release = [
            'tag_name'    => $data['tag_name'],
            'zipball_url' => $data['zipball_url'] ?? '',
            'body'        => $data['body'] ?? '',
        ];

        set_transient($this->cacheKey, $release, self::CACHE_TTL);

        return $release;
    }
}
```

**Step 4: Test'i çalıştır, PASS doğrula**

```bash
vendor/bin/phpunit tests/Unit/VersionCheckerTest.php -v
```
Expected: 7 tests, 7 assertions, PASS

**Step 5: Commit**

```bash
git add src/VersionChecker.php tests/Unit/VersionCheckerTest.php tests/bootstrap.php
git commit -m "feat: add VersionChecker with GitHub API and caching"
```

---

## Task 4: UpdateHandler — WordPress Güncelleme Hook'ları

**Files:**
- Create: `src/UpdateHandler.php`
- Create: `tests/Unit/UpdateHandlerTest.php`

**Step 1: Failing test yaz**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\UpdateHandler;
use MHM\PluginUpdater\VersionChecker;
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

        // VersionChecker mock
        $checker = \Mockery::mock(VersionChecker::class);
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

        $checker = \Mockery::mock(VersionChecker::class);
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

        $checker = \Mockery::mock(VersionChecker::class);
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
}
```

**Step 2: Test'i çalıştır, FAIL doğrula**

```bash
vendor/bin/phpunit tests/Unit/UpdateHandlerTest.php -v
```

**Step 3: Implementasyon**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class UpdateHandler
{
    public function __construct(
        private readonly string $file,
        private readonly string $slug,
        private readonly string $currentVersion,
        private readonly VersionChecker $checker,
        private readonly TokenManager $tokenManager,
        private readonly string $repo
    ) {}

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);
        add_filter('upgrader_post_install', [$this, 'afterInstall'], 10, 3);
    }

    public function checkForUpdate(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->checker->getLatestRelease();

        if ($release === null) {
            return $transient;
        }

        $remoteVersion = VersionChecker::normalizeVersion($release['tag_name']);

        if (!VersionChecker::isNewer($remoteVersion, $this->currentVersion)) {
            return $transient;
        }

        $update = (object) [
            'slug'        => $this->slug,
            'plugin'      => $this->file,
            'new_version' => $remoteVersion,
            'url'         => 'https://github.com/' . $this->repo,
            'package'     => $this->getDownloadUrl($release),
        ];

        $transient->response[$this->file] = $update;

        return $transient;
    }

    /**
     * @param bool $response
     * @param array<string, mixed> $hookExtra
     * @param array<string, string> $result
     * @return array<string, string>|bool
     */
    public function afterInstall(mixed $response, array $hookExtra, array $result): mixed
    {
        if (!isset($hookExtra['plugin']) || $hookExtra['plugin'] !== $this->file) {
            return $result;
        }

        global $wp_filesystem;

        $pluginDir = WP_PLUGIN_DIR . '/' . $this->slug;
        $wp_filesystem->move($result['destination'], $pluginDir);
        $result['destination'] = $pluginDir;

        activate_plugin($this->file);

        return $result;
    }

    private function getDownloadUrl(array $release): string
    {
        $url = $release['zipball_url'];

        // Private repo'lar için token'ı URL'ye ekle
        if ($this->tokenManager->hasToken()) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . 'access_token=' . $this->tokenManager->getToken();
        }

        return $url;
    }
}
```

**Step 4: Test'i çalıştır, PASS doğrula**

```bash
vendor/bin/phpunit tests/Unit/UpdateHandlerTest.php -v
```
Expected: 3 tests, assertions PASS

**Step 5: Commit**

```bash
git add src/UpdateHandler.php tests/Unit/UpdateHandlerTest.php
git commit -m "feat: add UpdateHandler for WordPress update hooks"
```

---

## Task 5: PluginInfoProvider — Eklenti Detay Popup'ı

**Files:**
- Create: `src/PluginInfoProvider.php`
- Create: `tests/Unit/PluginInfoProviderTest.php`

**Step 1: Failing test yaz**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater\Tests\Unit;

use MHM\PluginUpdater\PluginInfoProvider;
use MHM\PluginUpdater\VersionChecker;
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

        $checker = \Mockery::mock(VersionChecker::class);
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
        $checker = \Mockery::mock(VersionChecker::class);

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
        $checker = \Mockery::mock(VersionChecker::class);

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
```

**Step 2: Test'i çalıştır, FAIL doğrula**

```bash
vendor/bin/phpunit tests/Unit/PluginInfoProviderTest.php -v
```

**Step 3: Implementasyon**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class PluginInfoProvider
{
    public function __construct(
        private readonly string $slug,
        private readonly string $pluginName,
        private readonly string $repo,
        private readonly VersionChecker $checker
    ) {}

    public function register(): void
    {
        add_filter('plugins_api', [$this, 'getPluginInfo'], 20, 3);
    }

    public function getPluginInfo(mixed $result, string $action, object $args): mixed
    {
        if ($action !== 'plugin_information') {
            return false;
        }

        if (!isset($args->slug) || $args->slug !== $this->slug) {
            return false;
        }

        $release = $this->checker->getLatestRelease();

        if ($release === null) {
            return false;
        }

        return (object) [
            'name'          => $this->pluginName,
            'slug'          => $this->slug,
            'version'       => VersionChecker::normalizeVersion($release['tag_name']),
            'author'        => '<a href="https://maxhandmade.com">MHM Development Team</a>',
            'homepage'      => 'https://github.com/' . $this->repo,
            'download_link' => $release['zipball_url'],
            'sections'      => [
                'description' => $this->pluginName,
                'changelog'   => $this->formatChangelog($release['body']),
            ],
        ];
    }

    private function formatChangelog(string $markdown): string
    {
        // Basit markdown → HTML dönüşümü (WP'nin Parsedown'ı yoksa)
        $html = nl2br(esc_html($markdown));

        return '<div class="mhm-changelog">' . $html . '</div>';
    }
}
```

Not: `formatChangelog` içinde `esc_html` kullanılıyor. Brain\Monkey ile mock'lanmalı. Test bootstrap'a ekle:
```php
// tests/bootstrap.php'ye ekle:
if (!function_exists('esc_html')) {
    function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
```

**Step 4: Test'i çalıştır, PASS doğrula**

```bash
vendor/bin/phpunit tests/Unit/PluginInfoProviderTest.php -v
```
Expected: 3 tests, PASS

**Step 5: Commit**

```bash
git add src/PluginInfoProvider.php tests/Unit/PluginInfoProviderTest.php tests/bootstrap.php
git commit -m "feat: add PluginInfoProvider for plugin details popup"
```

---

## Task 6: Updater — Ana Giriş Noktası

**Files:**
- Create: `src/Updater.php`
- Create: `tests/Unit/UpdaterTest.php`

**Step 1: Failing test yaz**

```php
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

        // add_filter 3 kez çağrıldıysa hook'lar kayıt olmuş demektir
        $this->assertTrue(true); // Brain\Monkey expectations handle assertion
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
```

**Step 2: Test'i çalıştır, FAIL doğrula**

```bash
vendor/bin/phpunit tests/Unit/UpdaterTest.php -v
```

**Step 3: Implementasyon**

```php
<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class Updater
{
    /** @var array<string, UpdateHandler> */
    private static array $instances = [];

    /**
     * @param array{file: string, repo: string, token?: ?string} $config
     */
    public static function init(array $config): void
    {
        if (!isset($config['file'])) {
            throw new \InvalidArgumentException('Updater::init() requires "file" parameter');
        }

        if (!isset($config['repo'])) {
            throw new \InvalidArgumentException('Updater::init() requires "repo" parameter');
        }

        $file = $config['file'];
        $repo = $config['repo'];
        $token = $config['token'] ?? null;

        $pluginData = get_plugin_data($file);
        $pluginFile = plugin_basename($file);
        $slug = dirname($pluginFile);

        $tokenManager = new TokenManager($token);
        $checker = new VersionChecker($repo, $tokenManager);

        $handler = new UpdateHandler(
            file: $pluginFile,
            slug: $slug,
            currentVersion: $pluginData['Version'],
            checker: $checker,
            tokenManager: $tokenManager,
            repo: $repo
        );
        $handler->register();

        $infoProvider = new PluginInfoProvider(
            slug: $slug,
            pluginName: $pluginData['Name'],
            repo: $repo,
            checker: $checker
        );
        $infoProvider->register();

        self::$instances[$slug] = $handler;
    }
}
```

**Step 4: Test'i çalıştır, PASS doğrula**

```bash
vendor/bin/phpunit tests/Unit/UpdaterTest.php -v
```
Expected: 3 tests, PASS

**Step 5: Tüm testleri çalıştır**

```bash
vendor/bin/phpunit -v
```
Expected: ~19 tests, hepsi PASS

**Step 6: Commit**

```bash
git add src/Updater.php tests/Unit/UpdaterTest.php
git commit -m "feat: add Updater entry point — wires all components together"
```

---

## Task 7: Private Repo Download Desteği

**Files:**
- Modify: `src/UpdateHandler.php` (getDownloadUrl metodu)

**Açıklama:** GitHub zipball URL'si private repo'larda `access_token` query parametresi ile çalışmaz (deprecated). Bunun yerine `wp_remote_get` ile Authorization header kullanarak zip'i indirip geçici dosyaya yazmak gerekir.

**Step 1: Failing test yaz**

`tests/Unit/UpdateHandlerTest.php`'ye ekle:
```php
public function test_private_repo_uses_auth_header_in_download_url(): void
{
    $transient = new \stdClass();
    $transient->response = [];
    $transient->checked = [
        'mhm-test-plugin/mhm-test-plugin.php' => '1.0.0',
    ];

    $release = [
        'tag_name' => 'v2.0.0',
        'zipball_url' => 'https://api.github.com/repos/MaxHandMade/mhm-test-plugin/zipball/v2.0.0',
        'body' => 'New',
    ];

    $tokenManager = new TokenManager('ghp_secret123');

    $checker = \Mockery::mock(VersionChecker::class);
    $checker->shouldReceive('getLatestRelease')->once()->andReturn($release);

    Functions\expect('add_filter')
        ->once()
        ->with('http_request_args', \Mockery::type('array'), 10, 2);

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
```

**Step 2: UpdateHandler'da getDownloadUrl'ü güncelle**

Private repo'lar için `access_token` query parametresi yerine `http_request_args` filtresi ile Authorization header ekle:

```php
private function getDownloadUrl(array $release): string
{
    $url = $release['zipball_url'];

    if ($this->tokenManager->hasToken()) {
        // Download sırasında Authorization header ekle
        add_filter('http_request_args', [$this, 'injectAuthHeader'], 10, 2);
    }

    return $url;
}

public function injectAuthHeader(array $args, string $url): array
{
    if (str_contains($url, 'github.com') && str_contains($url, $this->repo)) {
        $args['headers'] = array_merge(
            $args['headers'] ?? [],
            $this->tokenManager->getAuthHeaders()
        );
    }

    return $args;
}
```

**Step 3: Testleri çalıştır, PASS doğrula**

```bash
vendor/bin/phpunit -v
```

**Step 4: Commit**

```bash
git add src/UpdateHandler.php tests/Unit/UpdateHandlerTest.php
git commit -m "fix: use auth header instead of query param for private repos"
```

---

## Task 8: README ve İlk Release

**Files:**
- Create: `README.md`

**Step 1: README yaz**

```markdown
# MHM Plugin Updater

GitHub Releases API üzerinden WordPress eklentilerine otomatik güncelleme bildirimi sağlayan Composer paketi.

## Kurulum

```bash
composer require maxhandmade/mhm-plugin-updater
```

## Kullanım

Eklentinizin ana dosyasına ekleyin:

```php
\MHM\PluginUpdater\Updater::init([
    'file'  => __FILE__,
    'repo'  => 'MaxHandMade/your-plugin-repo',
    'token' => defined('MHM_GITHUB_TOKEN') ? MHM_GITHUB_TOKEN : null,
]);
```

## Private Repo'lar

`wp-config.php` dosyasına ekleyin:

```php
define('MHM_GITHUB_TOKEN', 'ghp_your_personal_access_token');
```

## Gereksinimler

- PHP 8.1+
- WordPress 6.5+

## Lisans

GPL v2 or later
```

**Step 2: Commit ve tag**

```bash
git add README.md
git commit -m "docs: add README with usage instructions"
git tag v1.0.0
git push origin main --tags
```

**Step 3: GitHub Release oluştur**

```bash
gh release create v1.0.0 --title "v1.0.0" --notes "İlk sürüm — GitHub tabanlı WordPress eklenti güncelleme sistemi"
```

---

## Özet

| Task | Bileşen | Test Sayısı |
|------|---------|-------------|
| 1 | Repo + Composer init | — |
| 2 | TokenManager | 6 |
| 3 | VersionChecker | 7 |
| 4 | UpdateHandler | 3 |
| 5 | PluginInfoProvider | 3 |
| 6 | Updater (entry point) | 3 |
| 7 | Private repo auth fix | 1 |
| 8 | README + Release | — |
| **Toplam** | | **~23 test** |

**Tahmini commit sayısı:** 8-9 commit
