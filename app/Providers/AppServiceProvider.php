<?php

declare(strict_types=1);

namespace App\Providers;

use App\Validation\UblValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UblValidator::class, fn (): UblValidator => new UblValidator(
            schemasPath: resource_path('schemas'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
