<?php

namespace App\Services\Stock;

use App\Enums\StatutReservation;
use App\Models\Produit;
use App\Models\ReservationStock;
use App\Models\VarianteProduit;
use Illuminate\Support\Facades\DB;

/**
 * Étape 9 — l'opération qui manquait : annuler une commande déjà confirmée
 * (statut "payee" ou "prete") doit redonner le stock physique que
 * ConsommerReservation avait retiré, pas seulement relâcher une réservation
 * "active" qui n'existe plus (voir LibererReservation, qui ne touche jamais
 * quantite_stock et ne s'applique qu'aux réservations encore actives).
 *
 * La réservation repasse à "liberee" (pas de 4e valeur dédiée) : du point de
 * vue du stock, "consommee" restaurée ou "active" jamais consommée aboutit
 * au même état final, une réservation qui ne compte plus contre rien.
 */
class RestaurerReservation
{
    public function executer(ReservationStock $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            // pourTousEtablissements() : $reservation est déjà une instance
            // chargée par l'appelant ; l'UPDATE ne vise que son id.
            $affectee = ReservationStock::pourTousEtablissements()
                ->whereKey($reservation->id)
                ->where('statut', StatutReservation::Consommee)
                ->update(['statut' => StatutReservation::Liberee, 'liberee_le' => now()]);

            if ($affectee === 0) {
                return;
            }

            if ($reservation->variante_id !== null) {
                VarianteProduit::restaurerAtomiquement($reservation->variante_id, $reservation->quantite);
            } else {
                Produit::restaurerAtomiquement($reservation->produit_id, $reservation->quantite);
            }
        });
    }
}
