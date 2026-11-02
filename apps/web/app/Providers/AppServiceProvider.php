<?php

namespace App\Providers;

use App\Services\ClamAvMalwareScanner;
use App\Services\MalwareScanner;
use App\Services\NullMalwareScanner;
use App\Services\NullPresenceStore;
use App\Services\PresenceStore;
use App\Services\RedisPresenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->singleton(MalwareScanner::class, function (): MalwareScanner {
            if (! config('chat.media.scan.enabled')) {
                return new NullMalwareScanner;
            }

            return new ClamAvMalwareScanner(
                host: (string) config('chat.media.scan.host', '127.0.0.1'),
                port: (int) config('chat.media.scan.port', 3310),
                timeout: (float) config('chat.media.scan.timeout', 5),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Upload-specific buckets: presigning is per authenticated user, while the
     * signed receiver only knows the client IP.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('uploads', function (Request $request): Limit {
            return Limit::perMinute(30)->by(
                $request->user()?->id ?: $request->ip(),
            );
        });

        RateLimiter::for('upload-receive', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });
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
