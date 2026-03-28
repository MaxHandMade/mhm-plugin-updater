<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

final class PluginInfoProvider
{
    public function __construct(
        private readonly string $slug,
        private readonly string $pluginName,
        private readonly string $repo,
        private readonly VersionCheckerInterface $checker
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
        $html = nl2br(esc_html($markdown));
        return '<div class="mhm-changelog">' . $html . '</div>';
    }
}
