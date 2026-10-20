<?php

namespace App\Jobs;

use App\Enums\StatutCommande;
use App\Enums\StatutReservation;
use App\Models\Commande;
use App\Models\ReservationStock;
use App\Services\Stock\LibererReservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Planifié chaque minute (voir routes/console.php). Tourne sans contexte
 * d'établissement — `pourTousEtablissements()` est donc systématique ici,
 * plutôt que de compter sur l'échappatoire console du scope global, qui ne
 * vaudrait plus si ce job passait un jour sur une file synchrone au sein
 * d'une requête HTTP.
 */
class LibererReservationsExpirees implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function handle(LibererReservation $libererReservation): void
    {
        // Étape 13 : une commande dont le paiement a été CONFIRMÉ par le
        // commerçant n'expire jamais, et sa réservation n'est jamais
        // relâchée — l'argent est reçu, la marchandise est due. Elle retient
        // donc son stock tant que le commerçant ne la traite pas : c'est
        // voulu, et signalé en priorité dans sa liste (voir
        // Commande::payeeATraiterEnUrgence). paiement_confirme_le n'est
        // jamais renseigné hors Mobile Money : CinetPay et espèces gardent
        // exactement leur comportement d'expiration.
        $commandesPayees = Commande::pourTousEtablissements()->whereNotNull('paiement_confirme_le')->select('id');

        ReservationStock::pourTousEtablissements()
            ->where('statut', StatutReservation::Active)
            ->where('expire_le', '<=', now())
            ->whereNotIn('commande_id', $commandesPayees)
            ->each(fn (ReservationStock $reservation) => $libererReservation->executer($reservation));

        // Une commande à la fois (plutôt qu'un UPDATE en masse) : chacune a
        // besoin de sa propre ligne d'historique, dans la même transaction
        // que son passage à `expiree`.
        Commande::pourTousEtablissements()
            ->where('statut', StatutCommande::AttentePaiement)
            ->whereNull('paiement_confirme_le')
            ->where('expire_le', '<=', now())
            ->get()
            ->each(fn (Commande $commande) => $this->expirerUneCommande($commande));
    }

    private function expirerUneCommande(Commande $commande): void
    {
        DB::transaction(function () use ($commande) {
            // Update conditionnel : une commande déjà passée à `expiree` (ou
            // payée/annulée entre-temps) par un autre appel ne matche plus ce
            // `where`, donc rejouer le job ne journalise jamais deux fois la
            // même expiration.
            $affectee = Commande::pourTousEtablissements()
                ->whereKey($commande->id)
                ->where('statut', StatutCommande::AttentePaiement)
                // Un paiement confirmé entre la lecture et cet UPDATE gagne.
                ->whereNull('paiement_confirme_le')
                ->update(['statut' => StatutCommande::Expiree]);

            if ($affectee === 0) {
                return;
            }

            $commande->historique()->make([
                'ancien_statut' => StatutCommande::AttentePaiement,
                'nouveau_statut' => StatutCommande::Expiree,
                'utilisateur_id' => null,
                'motif' => 'Expiration automatique : délai de paiement dépassé.',
            ])->pourEtablissement($commande->etablissement_id)->save();
        });
    }
}
