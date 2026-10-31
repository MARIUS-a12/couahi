<?php

namespace App\Services\Commandes;

use App\Enums\StatutCommande;
use App\Models\Commande;
use Illuminate\Support\Facades\DB;

/**
 * "Confirmer" (ConsommerReservation) et "Annuler" (AnnulerCommande) ont des
 * effets sur le stock et des services dédiés. "Marquer prête" et "Marquer
 * livrée" (Étape 9) n'en ont aucun — un simple changement de statut avec son
 * entrée d'historique, identique dans sa forme pour les deux. Un seul
 * service générique plutôt que deux quasi-identiques.
 */
class TransitionnerStatutCommande
{
    public function executer(
        Commande $commande,
        StatutCommande $statutAttendu,
        StatutCommande $statutCible,
        ?int $utilisateurId,
    ): bool {
        return DB::transaction(function () use ($commande, $statutAttendu, $statutCible, $utilisateurId) {
            // pourTousEtablissements() : $commande est déjà résolue dans la
            // portée par l'appelant (route, ou saisie manuelle qui vient de
            // la créer) ; l'UPDATE ne vise que son id.
            $affectee = Commande::pourTousEtablissements()
                ->whereKey($commande->id)
                ->where('statut', $statutAttendu)
                ->update(['statut' => $statutCible]);

            if ($affectee === 0) {
                return false;
            }

            $commande->historique()->make([
                'ancien_statut' => $statutAttendu,
                'nouveau_statut' => $statutCible,
                'utilisateur_id' => $utilisateurId,
                'motif' => null,
            ])->pourEtablissement($commande->etablissement_id)->save();

            return true;
        });
    }
}
