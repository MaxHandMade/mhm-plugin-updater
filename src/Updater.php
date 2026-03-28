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
