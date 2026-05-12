<?php

declare(strict_types=1);

namespace App\As4;

final readonly class ConfigPeerEndpointResolver implements PeerEndpointResolver
{
    /**
     * @param  array<string, string>  $map  OIB → peer AP base URL
     */
    public function __construct(
        private array $map,
        private ?string $defaultPeerUrl,
    ) {}

    public function resolve(string $recipientOib): ?string
    {
        $explicit = $this->map[$recipientOib] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return $this->defaultPeerUrl !== '' ? $this->defaultPeerUrl : null;
    }
}
