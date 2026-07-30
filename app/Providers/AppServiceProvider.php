<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \App\Core\Contracts\MediaServiceInterface::class,
            \App\Core\Services\MediaService::class
        );
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
