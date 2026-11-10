<?php

namespace App\Providers;

use App\Services\ClamAvMalwareScanner;
use App\Services\MalwareScanner;
use App\Services\NullMalwareScanner;
use App\Services\NullPresenceStore;
use App\Services\PresenceStore;
use App\Services\RedisPresenceStore;
use App\Services\Translation\DeepLTranslator;
use App\Services\Translation\LibreTranslateTranslator;
use App\Services\Translation\MyMemoryTranslator;
use App\Services\Translation\NullTranslator;
use App\Services\Translation\Translator;
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

        $this->app->singleton(Translator::class, function (): Translator {
            $timeout = (int) config('translation.timeout', 8);

            return match (config('translation.provider')) {
                'deepl' => new DeepLTranslator(
                    key: $this->stringConfig('translation.deepl.key'),
                    endpoint: $this->stringConfig('translation.deepl.endpoint'),
                    timeout: $timeout,
                ),
                'libretranslate' => new LibreTranslateTranslator(
                    url: $this->stringConfig('translation.libretranslate.url') ?? 'http://127.0.0.1:5000',
                    apiKey: $this->stringConfig('translation.libretranslate.api_key'),
                    timeout: $timeout,
                ),
                'mymemory' => new MyMemoryTranslator(
                    url: $this->stringConfig('translation.mymemory.url') ?? 'https://api.mymemory.translated.net/get',
                    email: $this->stringConfig('translation.mymemory.email'),
                    timeout: $timeout,
                ),
                default => new NullTranslator,
            };
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

        RateLimiter::for('messages', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip(),
            );
        });

        // Contact requests are a classic spam vector; a tighter hourly bucket
        // keeps repeated requests from flooding a target's inbox.
        RateLimiter::for('contact-requests', function (Request $request): Limit {
            return Limit::perHour(30)->by(
                $request->user()?->id ?: $request->ip(),
            );
        });

        RateLimiter::for('reports', function (Request $request): Limit {
            return Limit::perHour(10)->by(
                $request->user()?->id ?: $request->ip(),
            );
        });

        RateLimiter::for('search', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip(),
            );
        });

        // Each translation may call a paid provider, so the bucket stays
        // tighter than ordinary reads. Cached translations still count so a
        // client cannot hammer the endpoint for cache hits.
        RateLimiter::for('translations', function (Request $request): Limit {
            return Limit::perMinute(30)->by(
                $request->user()?->id ?: $request->ip(),
            );
        });
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
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
