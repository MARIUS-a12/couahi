<?php

namespace App\Policies;

use App\Models\Commande;
use App\Models\User;

/**
 * Lot F : gerer_commandes découpé en trois droits qui ne se recouvrent pas
 * (voir decisions.md). Faire avancer une commande, toucher l'argent, et
 * défaire une vente payée sont trois gestes différents.
 */
class CommandePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->peut('voir_commandes');
    }

    public function view(User $user, Commande $commande): bool
    {
        return $user->peut('voir_commandes');
    }

    /**
     * Confirmer, marquer prête, marquer livrée.
     */
    public function traiter(User $user, Commande $commande): bool
    {
        return $user->peut('traiter_commandes');
    }

    /**
     * Confirmer un paiement reçu.
     */
    public function encaisser(User $user, Commande $commande): bool
    {
        return $user->peut('encaisser');
    }

    /**
     * « Payée » dès qu'un paiement est enregistré, même partiel : un
     * encaissement inférieur d'1 FCFA au total ne doit pas rendre la
     * commande annulable par qui n'a pas annuler_payees. La course avec un
     * encaissement simultané est fermée par AnnulerCommande
     * ($seulementNonPayee), pas ici.
     */
    public function annuler(User $user, Commande $commande): bool
    {
        return $commande->paiementEstConfirme()
            ? $this->annulerPayees($user)
            : $user->peut('traiter_commandes');
    }

    public function annulerPayees(User $user): bool
    {
        return $user->peut('annuler_payees');
    }

    public function rembourser(User $user, Commande $commande): bool
    {
        return $user->peut('annuler_payees');
    }
}
