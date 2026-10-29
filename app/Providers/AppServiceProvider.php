<?php

namespace App\Providers;

use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\EnsureUnlocked;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale('da');

        // Livewire-opdateringer på tavle og log skal også kræve oplåst enhed og valgt medarbejder.
        Livewire::addPersistentMiddleware([EnsureUnlocked::class, EnsureOperator::class]);
    }
}
