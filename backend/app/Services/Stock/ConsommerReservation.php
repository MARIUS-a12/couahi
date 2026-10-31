<?php

namespace App\Services\Stock;

use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Models\Commande;
use App\Models\Produit;
use App\Models\ReservationStock;
use App\Models\VarianteProduit;
use Illuminate\Support\Facades\DB;

class ConsommerReservation
{
    /**
     * Au paiement confirmé d'une commande : décrémente définitivement
     * `quantite_stock` (et `quantite_reservee` avec) pour chacune de ses
     * réservations encore actives, et marque la commande `payee`.
     *
     * Idempotent à deux niveaux, chacun étant l'UPDATE conditionnel qui
     * décide : le passage de la commande à `payee` (rejouer sur une
     * commande déjà payée, expirée ou annulée ne fait rien), et par
     * réservation le passage à `consommee` (une réservation déjà consommée
     * ou libérée entre-temps n'est pas retouchée). Rejouer l'appel entier
     * ne décrémente donc jamais deux fois le même stock.
     */
    /**
     * $utilisateurId : qui confirme, pour l'historique. Null quand c'est le
     * système (paiement constaté automatiquement).
     */
    public function executer(Commande $commande, ?int $utilisateurId = null): void
    {
        DB::transaction(function () use ($commande, $utilisateurId) {
            // pourTousEtablissements() : voir la même remarque dans
            // LibererReservation — $commande est déjà l'instance de confiance
            // de l'appelant, pas une lecture tenant-scopée.
            $affectee = Commande::pourTousEtablissements()
                ->whereKey($commande->id)
                ->where('statut', StatutCommande::AttentePaiement)
                ->update(['statut' => StatutCommande::Payee, 'payee_le' => now()]);

            if ($affectee === 0) {
                return;
            }

            $commande->historique()->make([
                'ancien_statut' => StatutCommande::AttentePaiement,
                'nouveau_statut' => StatutCommande::Payee,
                'utilisateur_id' => $utilisateurId,
                'motif' => null,
            ])->pourEtablissement($commande->etablissement_id)->save();

            // $commande->reservations (accès de relation) appliquerait le
            // scope global de ReservationStock, qui lève sans contexte ambiant
            // — jamais posé ici, ce service opère sur une commande déjà
            // identifiée par id. pourTousEtablissements() explicite comme
            // partout ailleurs dans ce fichier.
            $reservations = ReservationStock::pourTousEtablissements()
                ->where('commande_id', $commande->id)
                ->get();

            foreach ($reservations as $reservation) {
                $this->consommerUneReservation($reservation);
            }
        });
    }

    private function consommerUneReservation(ReservationStock $reservation): void
    {
        // pourTousEtablissements() : borné par l'id d'une réservation de la
        // commande déjà résolue (voir executer()).
        $affectee = ReservationStock::pourTousEtablissements()
            ->whereKey($reservation->id)
            ->where('statut', StatutReservation::Active)
            ->update(['statut' => StatutReservation::Consommee]);

        if ($affectee === 0) {
            return;
        }

        if ($reservation->variante_id !== null) {
            VarianteProduit::consommerAtomiquement($reservation->variante_id, $reservation->quantite);
        } else {
            Produit::consommerAtomiquement($reservation->produit_id, $reservation->quantite);
        }
    }
}
