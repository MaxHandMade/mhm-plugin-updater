<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class UpdateHandler
{
    public function __construct(
        private readonly string $file,
        private readonly string $slug,
        private readonly string $currentVersion,
        private readonly VersionCheckerInterface $checker,
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
            'package'     => $release['zipball_url'],
        ];

        $transient->response[$this->file] = $update;

        return $transient;
    }

    /**
     * Fix folder name after GitHub zipball extraction.
     * GitHub creates folders like "Owner-Repo-hash", WordPress expects "plugin-slug".
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
}
