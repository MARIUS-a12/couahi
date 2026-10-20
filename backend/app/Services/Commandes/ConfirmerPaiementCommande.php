<?php

namespace App\Services\Commandes;

use App\Models\Commande;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Étape 13 — le commerçant constate qu'un paiement est arrivé. Ne touche
 * JAMAIS au statut de la commande : payer et confirmer sont deux machines
 * d'état distinctes (une commande peut être confirmée sans être payée, et
 * inversement) — d'où une entrée d'historique marquée "evenement" dont
 * l'ancien et le nouveau statut sont identiques.
 *
 * Le montant reçu est enregistré TEL QUEL, même s'il diffère du total : un
 * client qui envoie 20 000 au lieu de 20 309 est un cas courant, pas une
 * erreur à bloquer. L'écart est écrit dans l'historique, en clair, pour que
 * le commerçant le voie sans avoir à refaire le calcul.
 *
 * Mise à jour conditionnelle (paiement_confirme_le encore nul) : deux clics,
 * ou deux membres de l'équipe en même temps, ne confirment jamais deux fois.
 */
class ConfirmerPaiementCommande
{
    public function executer(Commande $commande, int $montantRecu, ?string $reference, User $utilisateur, ?string $adresseIp): bool
    {
        return DB::transaction(function () use ($commande, $montantRecu, $reference, $utilisateur, $adresseIp) {
            $maintenant = now();

            $affectee = Commande::pourTousEtablissements()
                ->whereKey($commande->id)
                ->whereNull('paiement_confirme_le')
                ->update([
                    'paiement_confirme_le' => $maintenant,
                    'paiement_confirme_par' => $utilisateur->id,
                    'paiement_montant_recu' => $montantRecu,
                    'paiement_reference' => $reference,
                ]);

            if ($affectee === 0) {
                return false;
            }

            $ecart = $montantRecu - $commande->total;

            $commande->historique()->make([
                'ancien_statut' => $commande->statut,
                'nouveau_statut' => $commande->statut,
                'utilisateur_id' => $utilisateur->id,
                'motif' => $this->motif($montantRecu, $commande->total, $ecart, $reference),
                'evenement' => 'paiement_confirme',
            ])->pourEtablissement($commande->etablissement_id)->save();

            JournalAudit::create([
                'utilisateur_id' => $utilisateur->id,
                'action' => 'paiement_commande_confirme',
                'details' => [
                    'etablissement_id' => $commande->etablissement_id,
                    'commande_id' => $commande->id,
                    'numero' => $commande->numero,
                    'mode_paiement' => $commande->mode_paiement?->value,
                    'montant_recu' => $montantRecu,
                    'total' => $commande->total,
                    'ecart' => $ecart,
                    'reference' => $reference,
                ],
                'adresse_ip' => $adresseIp,
            ]);

            return true;
        });
    }

    private function motif(int $montantRecu, int $total, int $ecart, ?string $reference): string
    {
        $phrase = 'Paiement reçu : '.$this->montant($montantRecu);

        if ($reference !== null) {
            $phrase .= " (réf. {$reference})";
        }

        $phrase .= '.';

        if ($ecart < 0) {
            $phrase .= ' Écart : '.$this->montant(-$ecart).' de moins que le total ('.$this->montant($total).').';
        } elseif ($ecart > 0) {
            $phrase .= ' Écart : '.$this->montant($ecart).' de plus que le total ('.$this->montant($total).').';
        }

        return $phrase;
    }

    private function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', "\u{202F}").' FCFA';
    }
}
