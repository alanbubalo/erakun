<?php

namespace App\Providers;

use App\Validation\UblValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UblValidator::class, fn () => new UblValidator(
            schemasPath: resource_path('schemas'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
