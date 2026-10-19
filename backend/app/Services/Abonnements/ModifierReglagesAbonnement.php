<?php

namespace App\Services\Abonnements;

use App\Models\Abonnement;
use App\Models\JournalAudit;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Montant mensuel et jours de grâce, réglés par le super-admin. Le statut
 * est recalculé tout de suite : allonger le délai de grâce d'un
 * établissement suspendu doit remettre sa vitrine en ligne sans attendre
 * la tâche de la nuit (et le raccourcir peut la couper). L'échéance, elle,
 * ne bouge jamais ici — seul un paiement la déplace.
 */
class ModifierReglagesAbonnement
{
    public function executer(Abonnement $abonnement, int $montantMensuel, int $joursDeGrace, User $utilisateur, ?string $adresseIp): Abonnement
    {
        $avant = [
            'montant_mensuel' => $abonnement->montant_mensuel,
            'jours_de_grace' => $abonnement->jours_de_grace,
            'statut' => $abonnement->statut->value,
        ];

        $abonnement->montant_mensuel = $montantMensuel;
        $abonnement->jours_de_grace = $joursDeGrace;
        $abonnement->statut = $abonnement->statutAu(CarbonImmutable::today());

        if (! $abonnement->isDirty()) {
            return $abonnement;
        }

        $abonnement->save();

        JournalAudit::create([
            'utilisateur_id' => $utilisateur->id,
            'action' => 'abonnement_reglages_modifies',
            'details' => [
                'etablissement_id' => $abonnement->getAttribute('etablissement_id'),
                'avant' => $avant,
                'apres' => [
                    'montant_mensuel' => $abonnement->montant_mensuel,
                    'jours_de_grace' => $abonnement->jours_de_grace,
                    'statut' => $abonnement->statut->value,
                ],
            ],
            'adresse_ip' => $adresseIp,
        ]);

        return $abonnement;
    }
}
