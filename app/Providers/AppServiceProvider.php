<?php

namespace App\Providers;

use App\Contracts\DispatchNotificationSender;
use App\Enums\Capability;
use App\Models\Depot;
use App\Models\User;
use App\Services\Dispatch\MicrosoftGraphDispatchNotificationSender;
use App\Support\Database\ReadOnlySqlServerConnection;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Database\Connection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DispatchNotificationSender::class, fn () => match (config('dispatch-notifications.default')) {
            'microsoft-graph' => new MicrosoftGraphDispatchNotificationSender,
            default => throw new InvalidArgumentException('The configured dispatch notification driver is not supported.'),
        });

        // Any SQL Server connection declaring `readonly` is resolved to a
        // connection that refuses writes, so the RCPA source system stays
        // read-only no matter which entry point issues the query.
        Connection::resolverFor('sqlsrv', fn ($pdo, $database, $prefix, $config) => ($config['readonly'] ?? false)
            ? new ReadOnlySqlServerConnection($pdo, $database, $prefix, $config)
            : new SqlServerConnection($pdo, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentShield::prohibitDestructiveCommands($this->app->isProduction());

        foreach (Capability::cases() as $capability) {
            Gate::define(
                $capability->value,
                fn (User $user, Depot|int|null $depot = null): bool => $user
                    ->hasCapabilityForDepot($capability, $depot),
            );
        }
    }
}
