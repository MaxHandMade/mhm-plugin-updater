<?php

declare(strict_types=1);

namespace MHM\PluginUpdater;

interface VersionCheckerInterface
{
    /**
     * @return array{tag_name: string, zipball_url: string, body: string}|null
     */
    public function getLatestRelease(): ?array;
}
