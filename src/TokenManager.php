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
