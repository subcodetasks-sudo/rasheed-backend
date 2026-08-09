<?php

namespace Modules\Notifications\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Rules\InventoryStockNotificationRule;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Services\NotificationSseService;
use Modules\Notifications\Support\NotificationRuleRegistry;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class NotificationsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Notifications';

    protected string $nameLower = 'notifications';

    public function boot(): void
    {
        $this->registerConfig();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
        $this->app->register(EventServiceProvider::class);
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        $this->app->singleton(NotificationService::class);
        $this->app->singleton(NotificationSseService::class);

        $this->app->singleton(NotificationRuleRegistry::class, function ($app) {
            return new NotificationRuleRegistry([
                $app->make(InventoryStockNotificationRule::class),
            ]);
        });
    }

    protected function registerConfig(): void
    {
        $relativeConfigPath = config('modules.paths.generator.config.path');
        $configPath = module_path($this->name, $relativeConfigPath);

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $relativePath = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $configKey = $this->nameLower.'.'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $relativePath);
                    $key = ($relativePath === 'config.php') ? $this->nameLower : $configKey;

                    $this->publishes([$file->getPathname() => config_path($relativePath)], 'config');
                    $this->mergeConfigFrom($file->getPathname(), $key);
                }
            }
        }
    }
}
