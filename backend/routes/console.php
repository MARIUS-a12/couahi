<?php

use App\Jobs\LibererReservationsExpirees;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Toutes les tâches planifiées s'exécutent DANS le processus du
 * planificateur (voir decisions.md, 2026-10-20) : aucune file d'attente ni
 * aucun sous-processus dont l'échec serait invisible. Chacune a un test qui
 * l'exécute PAR le planificateur (ExecutionParLePlanificateurTest) — voir la
 * règle correspondante dans AGENTS.md.
 */

// Connexion "sync" : exécuté immédiatement par le planificateur, sans
// worker de file à faire tourner à côté.
Schedule::job(new LibererReservationsExpirees, connection: 'sync')
    ->everyMinute()
    ->name('commandes:liberer-reservations-expirees');

// Étape 12 — voir ActualiserStatutsAbonnements : idempotente, une seconde
// exécution le même jour ne change rien. Schedule::call() plutôt que
// Schedule::command() : ce dernier lance un sous-processus dont la sortie
// part dans NUL — un échec y passerait inaperçu, et aucun test ne pourrait
// l'exécuter par le planificateur sans toucher à une autre base que la
// sienne.
Schedule::call(fn () => Artisan::call('abonnements:actualiser-statuts'))
    ->dailyAt('00:05')
    ->name('abonnements:actualiser-statuts');
