<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Synchronisation automatique des données CoC toutes les 6 heures
Schedule::command('coc:sync-users --chunk=30')->everyTwoHours()->withoutOverlapping();
