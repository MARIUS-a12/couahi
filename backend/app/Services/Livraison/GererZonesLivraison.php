<?php

namespace App\Services\Livraison;

use App\Enums\StatutZoneLivraison;
use App\Models\Etablissement;
use App\Models\ZoneLivraison;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seul auteur des zones de livraison et du choix de les facturer. La
 * vitrine ne lit que Etablissement::zonesLivraisonProposees() ; la saisie
 * manuelle des commandes (lot C) lira la même chose.
 *
 * Une commande garde une copie de sa commune et de ses frais : modifier les
 * frais d'une zone ne change aucune commande passée. Une zone déjà portée
 * par une commande n'est pas effacée mais archivée, pour que la commande
 * garde le lien vers la zone qui l'a facturée ; une zone jamais utilisée
 * (une faute de frappe) s'efface pour de bon.
 *
 * « Autre commune » à frais fixes est une zone comme les autres, marquée
 * autre_commune : elle facture toute commune absente de la liste. Sans
 * elle, « Autre commune » reste à convenir.
 */
class GererZonesLivraison
{
    public function choisirFacturation(Etablissement $etablissement, bool $parCommune): void
    {
        $etablissement->parametreSite()->pourTousEtablissements()->firstOrFail()->update(['livraison_par_commune' => $parCommune]);
    }

    public function ajouter(Etablissement $etablissement, string $nom, int $frais): ZoneLivraison
    {
        return DB::transaction(function () use ($etablissement, $nom, $frais) {
            $this->verifierNomLibre($etablissement, $nom);

            return $etablissement->zonesLivraison()->create(['nom' => $nom, 'frais' => $frais]);
        });
    }

    public function modifier(ZoneLivraison $zone, string $nom, int $frais): ZoneLivraison
    {
        return DB::transaction(function () use ($zone, $nom, $frais) {
            $this->verifierNomLibre($zone->etablissement, $nom, sauf: $zone);
            $zone->update(['nom' => $nom, 'frais' => $frais]);

            return $zone;
        });
    }

    /**
     * Fixe les frais de toute commune absente de la liste, ou les retire
     * (null) : « Autre commune » redevient à convenir.
     */
    public function definirAutreCommune(Etablissement $etablissement, ?int $frais): void
    {
        DB::transaction(function () use ($etablissement, $frais) {
            $zone = $etablissement->zonesLivraison()
                ->pourTousEtablissements()
                ->where('statut', StatutZoneLivraison::Actif)
                ->where('autre_commune', true)
                ->lockForUpdate()
                ->first();

            if ($frais === null) {
                if ($zone !== null) {
                    $this->retirer($zone);
                }

                return;
            }

            if ($zone === null) {
                $etablissement->zonesLivraison()->create(['nom' => 'Autre commune', 'autre_commune' => true, 'frais' => $frais]);

                return;
            }

            $zone->update(['frais' => $frais]);
        });
    }

    /**
     * @return 'supprimee'|'archivee'
     */
    public function retirer(ZoneLivraison $zone): string
    {
        if ($zone->etablissement->commandes()->pourTousEtablissements()->where('zone_livraison_id', $zone->id)->exists()) {
            $zone->update(['statut' => StatutZoneLivraison::Inactif]);

            return 'archivee';
        }

        $zone->delete();

        return 'supprimee';
    }

    /**
     * Deux zones actives du même nom rendraient le choix du client ambigu :
     * la commune envoyée par la vitrine ne désignerait plus une seule zone.
     * Comparaison sans casse ni espaces de bord (« cocody » et « Cocody »).
     */
    private function verifierNomLibre(Etablissement $etablissement, string $nom, ?ZoneLivraison $sauf = null): void
    {
        $existe = $etablissement->zonesLivraison()
            ->pourTousEtablissements()
            ->where('statut', StatutZoneLivraison::Actif)
            ->where('autre_commune', false)
            ->whereRaw('lower(nom) = ?', [mb_strtolower(trim($nom))])
            ->when($sauf, fn ($requete) => $requete->whereKeyNot($sauf->id))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['nom' => ["La commune « {$nom} » a déjà une zone."]]);
        }
    }
}
