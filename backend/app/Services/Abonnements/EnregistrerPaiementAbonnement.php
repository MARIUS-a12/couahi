<?php

namespace App\Services\Abonnements;

use App\Enums\MoyenPaiementAbonnement;
use App\Enums\StatutAbonnement;
use App\Models\Abonnement;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\PaiementAbonnement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre un paiement d'abonnement reçu et en tire les conséquences, en
 * une seule transaction : l'échéance avance jusqu'à la fin de la période
 * payée (jamais en arrière — un paiement tardif pour une période déjà
 * couverte ne retire rien), et le statut est recalculé AUSSITÔT, sans
 * attendre la tâche quotidienne : un commerçant suspendu qui paie voit sa
 * vitrine revenir immédiatement. Rien d'autre n'a besoin d'être
 * "réactivé" : une suspension n'a jamais rien effacé (voir
 * VerifierAbonnementVitrine, qui ne fait que masquer la vitrine).
 *
 * Unique point d'entrée d'un paiement, qu'il soit saisi par le super-admin
 * (enregistrePar renseigné) ou, plus tard, confirmé par CinetPay (nul).
 */
class EnregistrerPaiementAbonnement
{
    public function executer(
        Etablissement $etablissement,
        int $montant,
        CarbonImmutable $periodeDebut,
        CarbonImmutable $periodeFin,
        MoyenPaiementAbonnement $moyen,
        ?string $reference,
        ?User $enregistrePar,
        ?string $adresseIp,
    ): PaiementAbonnement {
        return DB::transaction(function () use ($etablissement, $montant, $periodeDebut, $periodeFin, $moyen, $reference, $enregistrePar, $adresseIp) {
            /** @var Abonnement $abonnement */
            $abonnement = $etablissement->abonnement()->lockForUpdate()->firstOrFail();

            $paiement = new PaiementAbonnement([
                'montant' => $montant,
                'periode_debut' => $periodeDebut,
                'periode_fin' => $periodeFin,
                'moyen' => $moyen,
                'reference' => $reference,
                'enregistre_par' => $enregistrePar?->id,
                'paye_le' => now(),
            ]);
            $paiement->pourEtablissement($etablissement->id)->save();

            $ancienneEcheance = $abonnement->date_echeance;
            $ancienStatut = $abonnement->statut;

            if ($periodeFin->greaterThan($ancienneEcheance)) {
                $abonnement->date_echeance = $periodeFin;
            }

            // Un paiement met fin à l'essai : statutAu() ne conserve
            // "essai" que pour un abonnement qui n'a encore jamais payé.
            $abonnement->statut = StatutAbonnement::Actif;
            $abonnement->statut = $abonnement->statutAu(CarbonImmutable::today());
            $abonnement->save();

            JournalAudit::create([
                'utilisateur_id' => $enregistrePar?->id,
                'action' => 'abonnement_paiement_enregistre',
                'details' => [
                    'etablissement_id' => $etablissement->id,
                    'paiement_abonnement_id' => $paiement->id,
                    'montant' => $montant,
                    'periode_debut' => $periodeDebut->toDateString(),
                    'periode_fin' => $periodeFin->toDateString(),
                    'moyen' => $moyen->value,
                    'reference' => $reference,
                    'statut_avant' => $ancienStatut->value,
                    'statut_apres' => $abonnement->statut->value,
                ],
                'adresse_ip' => $adresseIp,
            ]);

            if (! $abonnement->date_echeance->equalTo($ancienneEcheance)) {
                JournalAudit::create([
                    'utilisateur_id' => $enregistrePar?->id,
                    'action' => 'abonnement_echeance_modifiee',
                    'details' => [
                        'etablissement_id' => $etablissement->id,
                        'avant' => $ancienneEcheance->toDateString(),
                        'apres' => $abonnement->date_echeance->toDateString(),
                        'cause' => 'paiement_abonnement_id:'.$paiement->id,
                    ],
                    'adresse_ip' => $adresseIp,
                ]);
            }

            return $paiement;
        });
    }
}
