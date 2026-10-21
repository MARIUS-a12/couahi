<?php

namespace App\Services\Commandes;

use App\Enums\StatutCommande;
use App\Models\Commande;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Le commerçant indique avoir rendu au client l'argent d'une commande
 * annulée ou expirée après paiement. Comme la confirmation du paiement,
 * c'est un constat fait à la main (aucune intégration ne rembourse seule) :
 * il solde la mention "À rembourser" sans rien effacer — l'historique de la
 * commande et le journal d'audit gardent qui, quand et combien.
 *
 * Update conditionnel : deux clics, ou deux membres de l'équipe en même
 * temps, n'enregistrent jamais deux remboursements. Ne change pas le
 * statut de la commande.
 */
class EnregistrerRemboursementCommande
{
    public function executer(Commande $commande, int $montant, ?string $reference, User $utilisateur, ?string $adresseIp): bool
    {
        return DB::transaction(function () use ($commande, $montant, $reference, $utilisateur, $adresseIp) {
            $affectee = Commande::pourTousEtablissements()
                ->whereKey($commande->id)
                ->whereNotNull('paiement_confirme_le')
                ->whereIn('statut', [StatutCommande::Annulee, StatutCommande::Expiree])
                ->whereNull('rembourse_le')
                ->update([
                    'rembourse_le' => now(),
                    'rembourse_par' => $utilisateur->id,
                    'remboursement_montant' => $montant,
                    'remboursement_reference' => $reference,
                ]);

            if ($affectee === 0) {
                return false;
            }

            $commande->historique()->make([
                'ancien_statut' => $commande->statut,
                'nouveau_statut' => $commande->statut,
                'utilisateur_id' => $utilisateur->id,
                'motif' => $this->motif($montant, (int) $commande->paiement_montant_recu, $reference),
                'evenement' => 'remboursement',
            ])->pourEtablissement($commande->etablissement_id)->save();

            JournalAudit::create([
                'utilisateur_id' => $utilisateur->id,
                'action' => 'remboursement_commande_enregistre',
                'details' => [
                    'etablissement_id' => $commande->etablissement_id,
                    'commande_id' => $commande->id,
                    'numero' => $commande->numero,
                    'montant_rembourse' => $montant,
                    'montant_recu' => $commande->paiement_montant_recu,
                    'reference' => $reference,
                ],
                'adresse_ip' => $adresseIp,
            ]);

            return true;
        });
    }

    private function motif(int $montant, int $recu, ?string $reference): string
    {
        $phrase = 'Remboursement effectué : '.$this->montant($montant);

        if ($reference !== null) {
            $phrase .= " (réf. {$reference})";
        }

        $phrase .= '.';

        if ($montant < $recu) {
            $phrase .= ' Moins que le montant reçu ('.$this->montant($recu).').';
        }

        return $phrase;
    }

    private function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', "\u{202F}").' FCFA';
    }
}
