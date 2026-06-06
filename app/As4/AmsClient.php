<?php

declare(strict_types=1);

namespace App\As4;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the AMS (Adresar metapodatkovnih servisa) operated by Porezna uprava
 * — the central OIB → MPS-URL locator. A miss or an unreachable AMS is reported
 * as null so the resolver can fall back to its static peer map.
 */
final readonly class AmsClient
{
    public function __construct(
        private string $baseUrl,
        private int $timeout,
    ) {}

    /**
     * Resolve which MPS publishes the given participant.
     *
     * @return ?string The MPS base URL, or null when the AMS does not know the
     *                 OIB or cannot be reached.
     */
    public function locate(string $oib): ?string
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get('/ams/participants/'.$oib);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $mpsUrl = $response->json('mps_url');

        return is_string($mpsUrl) && $mpsUrl !== '' ? $mpsUrl : null;
    }

    /**
     * Publish that the given participant is served by our MPS. Best-effort: an
     * unreachable AMS is reported as a failed registration rather than thrown,
     * so onboarding never hinges on the directory being up.
     *
     * @return bool Whether the AMS accepted the registration.
     */
    public function register(string $oib, string $mpsUrl): bool
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->put('/ams/participants/'.$oib, ['mps_url' => $mpsUrl]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful();
    }
}
