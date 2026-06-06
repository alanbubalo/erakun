<?php

declare(strict_types=1);

namespace App\Providers;

use App\As4\As4DeliveryService;
use App\As4\As4EnvelopeBuilder;
use App\As4\As4EnvelopeSigner;
use App\As4\ConfigPeerEndpointResolver;
use App\As4\Http\HttpAs4DeliveryService;
use App\As4\PeerEndpointResolver;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\Http\HttpFiscalizationService;
use App\Validation\UblValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;

class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(UblValidator::class, fn (): UblValidator => new UblValidator(
            schemasPath: resource_path('schemas'),
        ));

        $this->app->singleton(FiscalizationService::class, fn (): HttpFiscalizationService => new HttpFiscalizationService(
            baseUrl: (string) config('services.fiscalization.url'),
            timeout: (int) config('services.fiscalization.timeout'),
        ));

        $this->app->singleton(PeerEndpointResolver::class, fn (): ConfigPeerEndpointResolver => new ConfigPeerEndpointResolver(
            map: $this->parsePeerMap((string) config('services.as4.peers')),
            defaultPeerUrl: (string) config('services.as4.default_peer_url'),
        ));

        $this->app->singleton(As4DeliveryService::class, fn (Application $app): HttpAs4DeliveryService => new HttpAs4DeliveryService(
            builder: $app->make(As4EnvelopeBuilder::class),
            signer: $app->make(As4EnvelopeSigner::class),
            peers: $app->make(PeerEndpointResolver::class),
            timeout: (int) config('services.as4.timeout'),
        ));
    }

    public function boot(): void
    {
        //
    }

    /**
     * @return array<string, string>
     */
    private function parsePeerMap(string $raw): array
    {
        $map = [];

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }

            [$oib, $url] = explode('=', $pair, 2);
            $oib = trim($oib);
            $url = trim($url);

            if ($oib === '' || $url === '') {
                continue;
            }

            $map[$oib] = $url;
        }

        return $map;
    }
}
