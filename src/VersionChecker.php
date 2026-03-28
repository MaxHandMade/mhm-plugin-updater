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
