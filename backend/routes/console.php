<?php

use App\Jobs\LibererReservationsExpirees;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new LibererReservationsExpirees)->everyMinute();

// Étape 12 — voir ActualiserStatutsAbonnements : idempotente, une seconde
// exécution le même jour ne change rien.
Schedule::command('abonnements:actualiser-statuts')->dailyAt('00:05');
