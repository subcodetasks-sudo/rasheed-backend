<?php

namespace App\Providers;

use App\Broadcasting\SocketIoBroadcaster;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \App\Core\Contracts\MediaServiceInterface::class,
            \App\Core\Services\MediaService::class
        );

        $this->app->singleton(BroadcastService::class);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Broadcast::extend('socketio', function ($app, array $config) {
            return new SocketIoBroadcaster($config);
        });
    }
}
