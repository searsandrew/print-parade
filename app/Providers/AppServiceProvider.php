<?php

namespace App\Providers;

use App\Labels\DataSources\LabelDataSourceRegistry;
use App\Labels\DataSources\NetSuite\NetSuiteItemRepository;
use App\Labels\DataSources\NetSuite\NetSuiteLabelDataSource;
use App\Labels\DataSources\NetSuite\SuiteQlNetSuiteItemRepository;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NetSuiteItemRepository::class, SuiteQlNetSuiteItemRepository::class);
        $this->app->singleton(LabelDataSourceRegistry::class, fn ($app): LabelDataSourceRegistry => new LabelDataSourceRegistry([
            $app->make(NetSuiteLabelDataSource::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', Provider::class);
        });

        $this->configureDefaults();
        $this->configureRateLimiting();
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

    private function configureRateLimiting(): void
    {
        RateLimiter::for('print-submissions', fn (Request $request): array => [
            Limit::perMinute(60)->by((string) $request->ip()),
            Limit::perMinute(5)->by($request->ip().'|'.(string) $request->input('user_id')),
        ]);
    }
}
