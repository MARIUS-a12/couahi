<?php

namespace App\Services\Stock;

use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Models\Commande;
use App\Models\Produit;
use Illuminate\Validation\ValidationException;

class ChangerModeStock
{
    /**
     * Le mode de stock se change tant qu'aucune commande en cours ne
     * dépend de l'ancien (voir decisions.md, « Le mode de stock se change
     * tant qu'aucune commande en cours n'en dépend ») :
     *
     * - compte → interrupteur : une réservation active a déjà incrémenté
     *   quantite_reservee ; elle serait ensuite consommée ou libérée sur un
     *   produit qui ne lit plus ces colonnes.
     * - interrupteur → compte : une commande en attente n'a réservé aucun
     *   stock ; confirmée après la bascule, elle ne décompterait rien
     *   (ConsommerReservation ne consomme que des réservations).
     *
     * D'où une seule règle : aucune commande en attente contenant le
     * produit, ni aucune réservation active sur lui ou ses variantes.
     *
     * À appeler dans la transaction qui écrit le nouveau mode : la ligne du
     * produit est verrouillée jusqu'à la fin de cette transaction, ce qui
     * fait attendre toute réservation concurrente sur le stock propre du
     * produit. Une commande qui a lu l'ancien mode juste avant la bascule
     * peut encore aboutir selon lui : la fenêtre est assumée, ses effets
     * restent bornés (voir la même entrée de decisions.md).
     *
     * @throws ValidationException quand une commande en cours en dépend
     */
    public function verifier(Produit $produit, string $nouveauMode): void
    {
        $verrouille = Produit::query()->whereKey($produit->id)->lockForUpdate()->firstOrFail();

        if ($verrouille->mode_stock->value === $nouveauMode) {
            return;
        }

        $commandesEnCours = Commande::query()
            ->where(fn ($requete) => $requete
                ->where(fn ($enAttente) => $enAttente
                    ->where('statut', StatutCommande::AttentePaiement)
                    ->whereHas('lignes', fn ($lignes) => $lignes->where('produit_id', $produit->id)))
                ->orWhereHas('reservations', fn ($reservations) => $reservations
                    ->where('produit_id', $produit->id)
                    ->where('statut', StatutReservation::Active)))
            ->count();

        if ($commandesEnCours === 0) {
            return;
        }

        $commandes = $commandesEnCours === 1
            ? '1 commande en cours contient ce produit'
            : "{$commandesEnCours} commandes en cours contiennent ce produit";

        throw ValidationException::withMessages([
            'mode_stock' => "Impossible de changer le mode de stock : {$commandes}. Confirmez-les ou annulez-les d'abord.",
        ]);
    }
}
