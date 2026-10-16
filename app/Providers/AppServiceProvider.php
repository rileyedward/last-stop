<?php

namespace App\Providers;

use App\Admin\AdminDefaults;
use App\Game\Data\GameData;
use App\Game\Engine\Engine;
use App\Lobby\Identity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Random\Randomizer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameData::class, fn ($app) => $app->make(AdminDefaults::class)->gameData());
        $this->app->bind(Engine::class, fn ($app) => new Engine($app->make(GameData::class), new Randomizer));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Guest players authenticate their channels with the token cookie.
        Auth::viaRequest('player-token', fn (Request $request) => Identity::guest($request));
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
    }
}
