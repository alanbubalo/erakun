<?php

declare(strict_types=1);

namespace App\Providers;

use App\Fiscalization\FiscalizationService;
use App\Fiscalization\Http\HttpFiscalizationService;
use App\Validation\UblValidator;
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
    }

    public function boot(): void
    {
        //
    }
}
