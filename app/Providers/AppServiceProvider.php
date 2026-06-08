<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\RegisterParticipant;
use App\As4\AmsClient;
use App\As4\AmsMpsPeerEndpointResolver;
use App\As4\As4DeliveryService;
use App\As4\As4EnvelopeBuilder;
use App\As4\As4EnvelopeSigner;
use App\As4\ConfigPeerEndpointResolver;
use App\As4\Http\HttpAs4DeliveryService;
use App\As4\PeerEndpointResolver;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\Http\HttpFiscalizationService;
use App\Pki\AccessPointCredential;
use App\Pki\TestPkiGenerator;
use App\Pki\TrustStore;
use App\Validation\UblValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
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

        $this->app->singleton(AmsClient::class, fn (): AmsClient => new AmsClient(
            baseUrl: (string) config('services.ams.base_url'),
            timeout: (int) config('services.as4.timeout'),
        ));

        $this->app->singleton(PeerEndpointResolver::class, fn (Application $app): AmsMpsPeerEndpointResolver => new AmsMpsPeerEndpointResolver(
            ams: $app->make(AmsClient::class),
            fallback: new ConfigPeerEndpointResolver(
                map: $this->parsePeerMap((string) config('services.as4.peers')),
                defaultPeerUrl: (string) config('services.as4.default_peer_url'),
            ),
            timeout: (int) config('services.as4.timeout'),
        ));

        $this->app->singleton(RegisterParticipant::class, fn (Application $app): RegisterParticipant => new RegisterParticipant(
            ams: $app->make(AmsClient::class),
            mpsBaseUrl: (string) config('services.mps.base_url'),
        ));

        $this->app->singleton(As4DeliveryService::class, fn (Application $app): HttpAs4DeliveryService => new HttpAs4DeliveryService(
            builder: $app->make(As4EnvelopeBuilder::class),
            signer: $app->make(As4EnvelopeSigner::class),
            peers: $app->make(PeerEndpointResolver::class),
            timeout: (int) config('services.as4.timeout'),
        ));

        $this->app->singleton(TestPkiGenerator::class, fn (): TestPkiGenerator => new TestPkiGenerator(
            disk: Storage::disk((string) config('pki.disk')),
            opensslConfig: (string) config('pki.openssl_config'),
            caDays: (int) config('pki.ca_days'),
            leafDays: (int) config('pki.leaf_days'),
        ));

        $this->app->singleton(AccessPointCredential::class, fn (): AccessPointCredential => new AccessPointCredential(
            disk: Storage::disk((string) config('pki.disk')),
        ));

        // Trusts both test roots: party (FINA-like) and access point (OpenPEPPOL-like).
        // The anchors load lazily (only when a signature is verified) so resolving
        // the verifier never fails before the PKI has been generated.
        $this->app->singleton(TrustStore::class, fn (Application $app): TrustStore => new TrustStore(
            loader: function () use ($app): array {
                $generator = $app->make(TestPkiGenerator::class);

                return [
                    $generator->caCertificatePem(TestPkiGenerator::FINA_CA_CERT),
                    $generator->caCertificatePem(TestPkiGenerator::PEPPOL_CA_CERT),
                ];
            },
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
            if ($pair === '') {
                continue;
            }
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$oib, $url] = explode('=', $pair, 2);
            $oib = trim($oib);
            $url = trim($url);
            if ($oib === '') {
                continue;
            }
            if ($url === '') {
                continue;
            }

            $map[$oib] = $url;
        }

        return $map;
    }
}
