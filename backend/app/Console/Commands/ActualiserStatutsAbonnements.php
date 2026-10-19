<?php

namespace App\Console\Commands;

use App\Models\Abonnement;
use App\Models\JournalAudit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Tâche quotidienne (voir routes/console.php) : fait passer chaque
 * abonnement à l'état que ses dates imposent aujourd'hui — essai/actif,
 * puis en_retard, puis suspendu. Idempotente par construction : le statut
 * est RECALCULÉ depuis l'échéance (Abonnement::statutAu), jamais avancé
 * d'un cran ; l'échéance n'est jamais touchée ici. Deux exécutions le même
 * jour donnent donc le même résultat, la seconde ne change rien.
 *
 * --date permet de simuler un autre jour (vérification manuelle, tests),
 * sans jamais modifier les données pour autant.
 */
class ActualiserStatutsAbonnements extends Command
{
    protected $signature = 'abonnements:actualiser-statuts {--date= : Jour à considérer (AAAA-MM-JJ), aujourd\'hui par défaut}';

    protected $description = 'Met à jour le statut de chaque abonnement selon son échéance et ses jours de grâce';

    public function handle(): int
    {
        $jour = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : CarbonImmutable::today();
        $transitions = 0;

        // pourTousEtablissements() : traitement de fond sans établissement
        // courant, qui doit justement parcourir TOUS les abonnements.
        Abonnement::pourTousEtablissements()->orderBy('id')->each(function (Abonnement $abonnement) use ($jour, &$transitions) {
            $avant = $abonnement->statut;

            if (! $abonnement->actualiserStatut($jour)) {
                return;
            }

            $transitions++;

            JournalAudit::create([
                'utilisateur_id' => null,
                'action' => 'abonnement_statut_change',
                'details' => [
                    'etablissement_id' => $abonnement->getAttribute('etablissement_id'),
                    'avant' => $avant->value,
                    'apres' => $abonnement->statut->value,
                    'date_echeance' => $abonnement->date_echeance->toDateString(),
                ],
            ]);
        });

        $this->info("{$transitions} abonnement(s) ont changé de statut au {$jour->toDateString()}.");

        return self::SUCCESS;
    }
}
