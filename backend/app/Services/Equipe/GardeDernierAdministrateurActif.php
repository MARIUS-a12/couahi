<?php

namespace App\Services\Equipe;

use App\Enums\StatutMembre;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;

/**
 * Garde-fou partagé par ModifierMembreEquipe (rôles qui éloigneraient un
 * membre d'admin_etablissement) et ChangerStatutMembreEquipe
 * (désactivation) : "un établissement doit toujours conserver au moins un
 * administrateur actif". Ne décide PAS elle-même quand s'appliquer — chaque
 * appelant sait quelle action il tente et donc quand l'interroger.
 *
 * Lot F : la vérification seule ne suffit pas. Deux administrateurs qui se
 * retirent le rôle l'un l'autre au même instant voient chacun « il reste
 * l'autre », et la boutique n'en a plus aucun. verrouillerEquipe() prend le
 * verrou de la ligne de l'établissement : le second attend que le premier
 * ait fini, puis relit une équipe où l'autre n'est déjà plus administrateur.
 */
trait GardeDernierAdministrateurActif
{
    /**
     * À appeler dans la transaction, AVANT de relire les rôles de la cible.
     */
    private function verrouillerEquipe(EtablissementUtilisateur $cible): void
    {
        Etablissement::query()->whereKey($cible->etablissement_id)->lockForUpdate()->first();
    }

    private function existeAutreAdministrateurActif(EtablissementUtilisateur $cible): bool
    {
        return EtablissementUtilisateur::query()
            ->where('etablissement_id', $cible->etablissement_id)
            ->where('id', '!=', $cible->id)
            ->where('statut', StatutMembre::Actif)
            ->whereHas('roles', fn ($requete) => $requete->where('nom', 'admin_etablissement'))
            ->exists();
    }
}
