<?php

namespace App\Providers;

use App\Services\Khaya\KhayaSpeechRecognizer;
use App\Services\Speech\FakeSpeechRecognizer;
use App\Services\Speech\SpeechRecognizer;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\TelephonyProviders;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SpeechRecognizer::class, fn (Application $app): SpeechRecognizer => match (config('services.khaya.driver')) {
            'khaya' => $app->make(KhayaSpeechRecognizer::class),
            default => new FakeSpeechRecognizer,
        });

        $this->app->bind(TelephonyProvider::class, fn (Application $app): TelephonyProvider => $app->make(TelephonyProviders::class)->default());
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
