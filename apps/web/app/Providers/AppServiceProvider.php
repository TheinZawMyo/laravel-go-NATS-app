<?php

namespace App\Providers;

use App\Services\NullPresenceStore;
use App\Services\PresenceStore;
use App\Services\RedisPresenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PresenceStore::class, function (): PresenceStore {
            if (! config('chat.presence.enabled')) {
                return new NullPresenceStore;
            }

            try {
                $connection = Redis::connection((string) config('chat.presence.connection'));

                // The lease store pipelines commands, which is only exposed by
                // the phpredis connection in this application.
                if (! $connection instanceof PhpRedisConnection) {
                    return new NullPresenceStore;
                }

                return new RedisPresenceStore($connection);
            } catch (Throwable) {
                // Presence is decoration: a missing Redis must not take the
                // REST API down with it.
                return new NullPresenceStore;
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
