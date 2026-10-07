<?php

namespace App\Services\Etablissements;

use App\Exceptions\EtablissementAvecCommandesException;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Destruction définitive et irréversible d'un établissement : jamais
 * autorisée tant qu'il reste la moindre commande (voir
 * EtablissementAvecCommandesException), car un commerçant peut avoir besoin
 * de son historique de ventes des années plus tard.
 *
 * Tout le reste (domaines, produits, catégories, clients, zones de
 * livraison, médias) part par cascade au niveau base de données — voir les
 * migrations correspondantes — sauf les fichiers physiques des médias, que
 * la base ne sait pas effacer, et les comptes utilisateurs exclusivement
 * rattachés à CET établissement, qui n'ont plus de raison d'exister une
 * fois leur seul rattachement parti.
 */
class SupprimerEtablissement
{
    /**
     * Aucun contournement du scope global sur commandes()/medias() ici : le
     * seul appelant (EtablissementController::destroy(), route
     * "/etablissements/{etablissement}") pose déjà le contexte sur CET
     * établissement via DefinirEtablissementCible — le filtrage tenant
     * s'applique donc normalement.
     */
    public function executer(Etablissement $etablissement): void
    {
        $nombreCommandes = $etablissement->commandes()->count();

        if ($nombreCommandes > 0) {
            throw EtablissementAvecCommandesException::pour($nombreCommandes);
        }

        DB::transaction(function () use ($etablissement) {
            foreach ($etablissement->medias()->get() as $media) {
                $media->supprimerFichiersDisque();
            }

            // Capturé avant la suppression : une fois le rattachement parti
            // (cascade), on ne pourrait plus distinguer "n'appartenait qu'à
            // celui-ci" de "appartenait à plusieurs".
            $idsUtilisateursExclusifs = User::query()
                ->whereHas('appartenances', fn ($requete) => $requete->where('etablissement_id', $etablissement->id))
                ->whereDoesntHave('appartenances', fn ($requete) => $requete->where('etablissement_id', '!=', $etablissement->id))
                ->pluck('id');

            $nom = $etablissement->nom;
            $sousDomaine = $etablissement->slug;
            $utilisateurConnecte = auth()->id();

            $etablissement->delete();

            User::whereIn('id', $idsUtilisateursExclusifs)->delete();

            JournalAudit::create([
                'utilisateur_id' => $utilisateurConnecte,
                'action' => 'etablissement_supprime',
                'details' => ['nom' => $nom, 'sous_domaine' => $sousDomaine],
                'adresse_ip' => request()?->ip(),
            ]);
        });
    }
}
