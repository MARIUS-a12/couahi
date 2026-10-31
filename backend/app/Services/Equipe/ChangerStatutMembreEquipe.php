<?php

namespace App\Services\Equipe;

use App\Enums\StatutMembre;
use App\Models\EtablissementUtilisateur;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Désactiver (ou réactiver) un membre, jamais le supprimer — voir la
 * docblock de la migration etablissement_utilisateurs. Désactiver invalide
 * IMMÉDIATEMENT toutes ses sessions (même procédé que
 * ModifierMotDePasseUtilisateur, mais ici TOUTES les sessions, il n'y en a
 * pas une "en cours" à préserver puisque ce n'est jamais soi-même qui agit
 * sur soi-même, voir le garde-fou ci-dessous).
 */
class ChangerStatutMembreEquipe
{
    use GardeDernierAdministrateurActif;

    public function executer(
        EtablissementUtilisateur $membre,
        StatutMembre $nouveauStatut,
        User $acteur,
        ?string $adresseIp,
    ): EtablissementUtilisateur {
        if ($nouveauStatut === StatutMembre::Suspendu && $membre->utilisateur_id === $acteur->id) {
            throw ValidationException::withMessages([
                'statut' => ['Vous ne pouvez pas vous désactiver vous-même.'],
            ]);
        }

        return DB::transaction(function () use ($membre, $nouveauStatut, $acteur, $adresseIp) {
            $this->verrouillerEquipe($membre);
            $membre->refresh()->load('roles');

            if (
                $nouveauStatut === StatutMembre::Suspendu
                && $membre->statut === StatutMembre::Actif
                && $membre->estAdministrateur()
                && ! $this->existeAutreAdministrateurActif($membre)
            ) {
                throw ValidationException::withMessages([
                    'statut' => ['Cet établissement doit conserver au moins un administrateur actif.'],
                ]);
            }

            $membre->update(['statut' => $nouveauStatut]);

            if ($nouveauStatut === StatutMembre::Suspendu) {
                DB::table('sessions')->where('user_id', $membre->utilisateur_id)->delete();
            }

            JournalAudit::create([
                'utilisateur_id' => $acteur->id,
                'action' => $nouveauStatut === StatutMembre::Suspendu ? 'equipe_membre_suspendu' : 'equipe_membre_reactive',
                'details' => ['membre_id' => $membre->utilisateur_id],
                'adresse_ip' => $adresseIp,
            ]);

            return $membre->refresh();
        });
    }
}
