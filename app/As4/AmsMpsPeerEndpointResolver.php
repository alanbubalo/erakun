<?php

declare(strict_types=1);

namespace App\As4;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Discovery-backed peer resolution: OIB → (AMS) → MPS URL → (MPS) → AS4 endpoint.
 *
 * Any miss along the chain — the AMS does not know the OIB, the MPS does not
 * serve it, or either hop is unreachable — falls through to a static fallback
 * resolver (the AS4_PEER_MAP config). Resolutions are memoised per instance so a
 * single delivery lifecycle never re-hits the network for the same OIB.
 */
final class AmsMpsPeerEndpointResolver implements PeerEndpointResolver
{
    private const string MPS_PATH = '/api/mps/participants/';

    /** @var array<string, ?string> */
    private array $cache = [];

    public function __construct(
        private readonly AmsClient $ams,
        private readonly PeerEndpointResolver $fallback,
        private readonly int $timeout,
    ) {}

    public function resolve(string $recipientOib): ?string
    {
        if (array_key_exists($recipientOib, $this->cache)) {
            return $this->cache[$recipientOib];
        }

        return $this->cache[$recipientOib] = $this->discover($recipientOib);
    }

    private function discover(string $oib): ?string
    {
        $mpsUrl = $this->ams->locate($oib);

        if ($mpsUrl === null) {
            return $this->fallback->resolve($oib);
        }

        return $this->mpsLookup($mpsUrl, $oib) ?? $this->fallback->resolve($oib);
    }

    private function mpsLookup(string $mpsUrl, string $oib): ?string
    {
        try {
            $response = Http::baseUrl($mpsUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get(self::MPS_PATH.$oib);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $endpoint = $response->json('as4_endpoint');

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }
}
