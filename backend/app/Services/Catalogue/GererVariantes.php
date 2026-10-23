<?php

namespace App\Services\Catalogue;

use App\Models\Media;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le SEUL auteur de la composition des variantes : options, valeurs, liens
 * variante-valeur, signature et nom d'affichage. Aucun contrôleur, aucune
 * commande artisan, aucun seeder n'y écrit directement
 * (EcrituresVariantesCentraliseesTest le vérifie) : la garantie « une
 * valeur pour chaque option », que la base ne sait pas exprimer, ne tient
 * que si un seul code écrit ces lignes. catalogue:verifier-variantes la
 * contrôle a posteriori.
 *
 * Le stock d'une variante (réservation, consommation) reste du ressort des
 * services de stock : ce service ne compose que ce qu'est la variante.
 *
 * Deux libellés à ne pas confondre :
 * - le nom d'affichage de la variante (« 39 / Noir »), dérivé de ses
 *   valeurs dans l'ordre des options, recalculé ici à chaque changement ;
 * - le libellé recopié sur une ligne de commande, gelé pour toujours :
 *   c'est un document comptable. Ce service n'y touche jamais.
 */
class GererVariantes
{
    /**
     * Ajouter une dimension à un produit qui a déjà des variantes exige de
     * dire quelle valeur elles prennent (« vos M, L, XL sont de quelle
     * couleur ? ») : elles la reçoivent dans la même transaction, gardent
     * leur identifiant, leur stock, leurs réservations et leurs commandes.
     * Il n'existe jamais d'instant où une variante manque d'une valeur.
     */
    public function ajouterOption(Produit $produit, string $nom, ?string $valeurDesVariantesExistantes = null): OptionProduit
    {
        return DB::transaction(function () use ($produit, $nom, $valeurDesVariantesExistantes) {
            $variantes = $this->variantesDe($produit);

            if ($variantes->isNotEmpty() && blank($valeurDesVariantesExistantes)) {
                throw ValidationException::withMessages([
                    'valeur_des_variantes_existantes' => "Ce produit a déjà {$variantes->count()} variante(s) : indiquez la valeur de « {$nom} » qu'elles prennent.",
                ]);
            }

            $option = (new OptionProduit([
                'produit_id' => $produit->id,
                'nom' => trim($nom),
                'ordre' => (int) OptionProduit::pourTousEtablissements()->where('produit_id', $produit->id)->max('ordre') + 1,
            ]))->pourEtablissement($produit->etablissement_id);
            $option->save();

            if ($variantes->isNotEmpty()) {
                $valeur = $this->ajouterValeur($option, $valeurDesVariantesExistantes);

                foreach ($variantes as $variante) {
                    $variante->valeurs()->attach($valeur->id, ['option_id' => $option->id, 'produit_id' => $produit->id]);
                    $this->recalculer($variante);
                }
            }

            return $option;
        });
    }

    /**
     * La nouvelle valeur se place en dernier ; le commerçant la déplace
     * ensuite (l'ordre ne se devine jamais depuis le libellé).
     */
    public function ajouterValeur(OptionProduit $option, string $libelle): ValeurOption
    {
        $valeur = (new ValeurOption([
            'option_id' => $option->id,
            'libelle' => trim($libelle),
            'ordre' => (int) ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->max('ordre') + 1,
        ]))->pourEtablissement($option->etablissement_id);
        $valeur->save();

        return $valeur;
    }

    /**
     * Renommer « Noir » en « Noir mat » change le nom d'affichage des
     * variantes qui la portent. La signature (identifiants) ne bouge pas,
     * et les lignes de commande gardent « 39 / Noir » : ce qui a été vendu
     * ce jour-là.
     */
    public function renommerValeur(ValeurOption $valeur, string $libelle): ValeurOption
    {
        return DB::transaction(function () use ($valeur, $libelle) {
            $valeur->update(['libelle' => trim($libelle)]);

            foreach ($this->variantesPortant($valeur) as $variante) {
                $this->recalculer($variante);
            }

            return $valeur;
        });
    }

    /**
     * Une variante est une combinaison d'exactement une valeur par option
     * du produit — ni plus, ni moins, ni valeur archivée, ni valeur d'un
     * autre produit. Elle n'existe que parce qu'on la crée ici.
     *
     * @param  array<int, ValeurOption>  $valeurs
     * @param  array{quantite_stock?: int, prix?: int|null, media_id?: int|null, reference?: string|null, disponible?: bool, statut?: string}  $attributs
     */
    public function creerVariante(Produit $produit, array $valeurs, array $attributs = []): VarianteProduit
    {
        return DB::transaction(function () use ($produit, $valeurs, $attributs) {
            $valeurs = collect($valeurs);
            $options = OptionProduit::pourTousEtablissements()->where('produit_id', $produit->id)->get();
            $optionsCouvertes = $valeurs->pluck('option_id');

            if ($options->isEmpty() || $optionsCouvertes->sort()->values()->all() !== $options->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'valeurs' => 'Une variante porte exactement une valeur pour chaque option du produit ('.$options->pluck('nom')->join(', ').').',
                ]);
            }

            if ($valeurs->contains(fn (ValeurOption $valeur) => $valeur->archivee_le !== null)) {
                throw ValidationException::withMessages(['valeurs' => 'Une valeur archivée ne peut pas composer une nouvelle variante.']);
            }

            $variante = (new VarianteProduit(['produit_id' => $produit->id, 'nom' => $this->nomPour($valeurs, $options), ...$attributs]))
                ->pourEtablissement($produit->etablissement_id);
            $variante->signature = VarianteProduit::signaturePour($valeurs->pluck('id'));
            $variante->save();

            foreach ($valeurs as $valeur) {
                $variante->valeurs()->attach($valeur->id, ['option_id' => $valeur->option_id, 'produit_id' => $produit->id]);
            }

            return $variante;
        });
    }

    /**
     * Une photo retirée du produit cesse d'être l'image de ses variantes :
     * elles reviennent aux photos du produit. La base refuserait sinon le
     * détachement (clé composée vers media_produit).
     */
    public function retirerImage(Produit $produit, Media $media): void
    {
        VarianteProduit::pourTousEtablissements()
            ->where('produit_id', $produit->id)
            ->where('media_id', $media->id)
            ->update(['media_id' => null]);
    }

    /**
     * Le nom d'affichage attendu d'une variante, d'après ses valeurs
     * actuelles : sert aussi à catalogue:verifier-variantes.
     */
    public function nomAttendu(VarianteProduit $variante): string
    {
        $valeurs = $variante->valeurs()->pourTousEtablissements()->get();

        return $this->nomPour($valeurs, OptionProduit::pourTousEtablissements()->where('produit_id', $variante->produit_id)->get());
    }

    /**
     * @param  Collection<int, ValeurOption>  $valeurs
     * @param  Collection<int, OptionProduit>  $options
     */
    private function nomPour(Collection $valeurs, Collection $options): string
    {
        $rangOption = $options->sortBy([['ordre', 'asc'], ['id', 'asc']])->values()->pluck('id')->flip();

        return $valeurs->sortBy(fn (ValeurOption $valeur) => $rangOption[$valeur->option_id] ?? PHP_INT_MAX)
            ->pluck('libelle')
            ->join(' / ');
    }

    private function recalculer(VarianteProduit $variante): void
    {
        $variante->signature = VarianteProduit::signaturePour($variante->valeurs()->pourTousEtablissements()->pluck('valeurs_option.id'));
        $variante->nom = $this->nomAttendu($variante);
        $variante->save();
    }

    /**
     * @return Collection<int, VarianteProduit>
     */
    private function variantesDe(Produit $produit): Collection
    {
        return VarianteProduit::pourTousEtablissements()->where('produit_id', $produit->id)->orderBy('id')->get();
    }

    /**
     * @return Collection<int, VarianteProduit>
     */
    private function variantesPortant(ValeurOption $valeur): Collection
    {
        return VarianteProduit::pourTousEtablissements()
            ->whereHas('valeurs', fn ($requete) => $requete->pourTousEtablissements()->whereKey($valeur->id))
            ->get();
    }
}
