<?php

namespace App\Console\Commands;

use App\Models\Etablissement;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\VarianteProduit;
use App\Services\Catalogue\GererVariantes;
use Illuminate\Console\Command;

/**
 * Contrôle a posteriori ce que la base ne sait pas garantir : chaque
 * variante porte une valeur pour CHAQUE option de son produit. Signale
 * aussi une signature ou un nom d'affichage qui ne correspondent plus à
 * ses valeurs. Lecture seule : ne corrige rien, se lance en production
 * sans ouvrir la base. Code de sortie 1 dès qu'une incohérence existe.
 */
class VerifierCoherenceVariantes extends Command
{
    protected $signature = 'catalogue:verifier-variantes {--etablissement= : Slug d\'un seul établissement à vérifier}';

    protected $description = 'Signale les variantes à qui il manque une valeur, ou dont la signature ou le nom ne correspond plus à leurs valeurs';

    public function handle(GererVariantes $gererVariantes): int
    {
        // pourTousEtablissements() partout : contrôle de fond sans
        // établissement courant, qui doit justement parcourir tout le
        // catalogue (ou celui d'un établissement nommé).
        $produits = Produit::pourTousEtablissements()
            ->whereHas('variantes', fn ($requete) => $requete->pourTousEtablissements())
            ->when($this->option('etablissement'), fn ($requete, $slug) => $requete->where('etablissement_id', Etablissement::where('slug', $slug)->value('id') ?? 0))
            ->orderBy('etablissement_id')->orderBy('id')
            ->get();

        $slugs = Etablissement::whereIn('id', $produits->pluck('etablissement_id')->unique())->pluck('slug', 'id');
        $incoherences = [];
        $nombreVariantes = 0;

        foreach ($produits as $produit) {
            $options = OptionProduit::pourTousEtablissements()->where('produit_id', $produit->id)->orderBy('ordre')->get();
            $variantes = VarianteProduit::pourTousEtablissements()
                ->where('produit_id', $produit->id)
                ->with(['valeurs' => fn ($requete) => $requete->pourTousEtablissements()])
                ->orderBy('id')
                ->get();

            foreach ($variantes as $variante) {
                $nombreVariantes++;
                $prefixe = "{$slugs[$produit->etablissement_id]} / {$produit->nom} / variante #{$variante->id} « {$variante->nom} »";
                $optionsPortees = $variante->valeurs->pluck('pivot.option_id')->all();

                foreach ($options as $option) {
                    if (! in_array($option->id, $optionsPortees, true)) {
                        $incoherences[] = "{$prefixe} : aucune valeur pour l'option « {$option->nom} »";
                    }
                }

                if ($variante->signature !== VarianteProduit::signaturePour($variante->valeurs->pluck('id'))) {
                    $incoherences[] = "{$prefixe} : signature « {$variante->signature} » qui ne correspond pas à ses valeurs";
                }

                $nomAttendu = $gererVariantes->nomAttendu($variante);
                if ($variante->nom !== $nomAttendu) {
                    $incoherences[] = "{$prefixe} : nom d'affichage attendu « {$nomAttendu} »";
                }
            }
        }

        if ($incoherences === []) {
            $this->info("Aucune incohérence : {$nombreVariantes} variante(s) de {$produits->count()} produit(s) vérifiée(s).");

            return self::SUCCESS;
        }

        $this->error(count($incoherences).' incohérence(s) sur '.$nombreVariantes.' variante(s) :');

        foreach ($incoherences as $incoherence) {
            $this->line("  {$incoherence}");
        }

        return self::FAILURE;
    }
}
