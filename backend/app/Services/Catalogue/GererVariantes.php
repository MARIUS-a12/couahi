<?php

namespace App\Services\Catalogue;

use App\Enums\StatutVariante;
use App\Models\LigneCommande;
use App\Models\Media;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ReservationStock;
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
 *
 * pourTousEtablissements() partout ici : chaque requête est bornée par le
 * produit_id, l'option_id ou l'id d'une instance que la route a déjà
 * résolue dans la portée de l'établissement (exception nommée dans
 * decisions.md, 31/10 ; ExceptionsPorteeEtablissementTest).
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

            $this->exigerNomOptionLibre($produit->id, $nom);

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
        $this->exigerLibelleLibre($option->id, $libelle);

        $valeur = (new ValeurOption([
            'option_id' => $option->id,
            'libelle' => trim($libelle),
            'ordre' => (int) ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->max('ordre') + 1,
        ]))->pourEtablissement($option->etablissement_id);
        $valeur->save();

        return $valeur;
    }

    /**
     * Plusieurs valeurs d'un coup, dans l'ordre donné : la plage 39 à 45
     * de l'écran, ou « Tout ajouter » parmi les suggestions de la boutique.
     * Tout ou rien — jamais 39, 40, 41 créées puis un refus sur 42.
     *
     * Une valeur déjà ACTIVE dans l'option est simplement sautée (taper
     * 39 à 45 quand 42 existe ajoute les six autres) ; une valeur ARCHIVÉE
     * refuse l'ensemble, avec le message qui invite à la restaurer :
     * la recréer en silence en ferait une deuxième « 42 ».
     *
     * @param  array<int, string>  $libelles
     * @return array<int, ValeurOption> les valeurs créées
     */
    public function ajouterValeurs(OptionProduit $option, array $libelles): array
    {
        return DB::transaction(function () use ($option, $libelles) {
            $actives = ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->whereNull('archivee_le')
                ->pluck('libelle')->map(fn (string $libelle) => mb_strtolower($libelle))->all();
            $vues = [];
            $creees = [];

            foreach ($libelles as $libelle) {
                $cle = mb_strtolower(trim($libelle));

                if ($cle === '' || in_array($cle, $actives, true) || in_array($cle, $vues, true)) {
                    continue;
                }
                $vues[] = $cle;
                $creees[] = $this->ajouterValeur($option, $libelle);
            }

            return $creees;
        });
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
            $this->exigerLibelleLibre($valeur->option_id, $libelle, $valeur->id);
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
     * Plusieurs combinaisons d'un coup (la grille cochée) : toutes ou
     * aucune.
     *
     * @param  array<int, array{valeurs: array<int, ValeurOption>, attributs?: array<string, mixed>}>  $combinaisons
     * @return Collection<int, VarianteProduit>
     */
    public function creerVariantes(Produit $produit, array $combinaisons): Collection
    {
        return DB::transaction(fn () => collect($combinaisons)->map(
            fn (array $combinaison) => $this->creerVariante($produit, $combinaison['valeurs'], $combinaison['attributs'] ?? [])
        ));
    }

    /**
     * Stock, prix, image, disponibilité, statut : ce qu'on règle sur une
     * variante sans toucher à ce qu'elle est. Réactiver une variante dont
     * une valeur est archivée est refusé (restaurer la valeur d'abord), et
     * réactiver une combinaison qu'une autre variante active occupe déjà
     * aussi : la base le refuserait, le message dit pourquoi.
     *
     * @param  array{quantite_stock?: int, prix?: int|null, media_id?: int|null, disponible?: bool, statut?: string}  $attributs
     */
    public function modifierVariante(VarianteProduit $variante, array $attributs): VarianteProduit
    {
        if (array_key_exists('quantite_stock', $attributs) && $attributs['quantite_stock'] < $variante->quantite_reservee) {
            throw ValidationException::withMessages([
                'quantite_stock' => "{$variante->quantite_reservee} article(s) de « {$variante->nom} » sont réservés par des commandes en cours : le stock ne peut pas descendre en dessous.",
            ]);
        }

        if (($attributs['statut'] ?? null) === StatutVariante::Actif->value && ! $variante->estActive()) {
            if ($variante->valeurs()->pourTousEtablissements()->whereNotNull('archivee_le')->exists()) {
                throw ValidationException::withMessages(['statut' => "« {$variante->nom} » porte une valeur archivée : restaurez-la avant de réactiver la variante."]);
            }

            $occupee = VarianteProduit::pourTousEtablissements()
                ->where('produit_id', $variante->produit_id)
                ->where('signature', $variante->signature)
                ->where('statut', StatutVariante::Actif)
                ->exists();

            if ($occupee) {
                throw ValidationException::withMessages(['statut' => "Une variante active « {$variante->nom} » existe déjà."]);
            }
        }

        $variante->fill($attributs)->save();

        return $variante;
    }

    /**
     * Décocher une combinaison : une variante qui n'a jamais servi (aucune
     * ligne de commande, aucune réservation) est effacée ; une variante qui
     * a servi devient inactive — l'historique de vente pointe vers elle.
     *
     * @return 'supprimee'|'archivee'
     */
    public function retirerVariante(VarianteProduit $variante): string
    {
        if ($this->aServi($variante)) {
            $variante->update(['statut' => StatutVariante::Inactif]);

            return 'archivee';
        }

        $variante->delete();

        return 'supprimee';
    }

    /**
     * Une valeur portée par une variante s'archive : elle sort des choix
     * et des suggestions, ses variantes deviennent inactives et gardent
     * leur stock. Une valeur portée par aucune variante (une faute de
     * frappe) s'efface pour de bon.
     *
     * @return 'supprimee'|'archivee'
     */
    public function archiverValeur(ValeurOption $valeur): string
    {
        return DB::transaction(function () use ($valeur) {
            $variantes = $this->variantesPortant($valeur);

            if ($variantes->isEmpty()) {
                $valeur->delete();

                return 'supprimee';
            }

            $valeur->update(['archivee_le' => now()]);

            foreach ($variantes as $variante) {
                $variante->update(['statut' => StatutVariante::Inactif]);
            }

            return 'archivee';
        });
    }

    /**
     * Ses variantes restent inactives : le commerçant les réactive une par
     * une, en sachant ce qu'il remet en vente.
     */
    public function restaurerValeur(ValeurOption $valeur): ValeurOption
    {
        $valeur->update(['archivee_le' => null]);

        return $valeur;
    }

    public function renommerOption(OptionProduit $option, string $nom): OptionProduit
    {
        $this->exigerNomOptionLibre($option->produit_id, $nom, $option->id);
        $option->update(['nom' => trim($nom)]);

        return $option;
    }

    /**
     * L'ordre des options est celui du nom d'affichage (« 39 / Noir » ou
     * « Noir / 39 ») : toutes les variantes du produit sont renommées.
     *
     * @param  array<int, int>  $identifiants
     */
    public function reordonnerOptions(Produit $produit, array $identifiants): void
    {
        DB::transaction(function () use ($produit, $identifiants) {
            $options = OptionProduit::pourTousEtablissements()->where('produit_id', $produit->id)->get()->keyBy('id');
            $this->exigerLaMemeListe($options->keys()->all(), $identifiants);

            foreach (array_values($identifiants) as $index => $identifiant) {
                $options[$identifiant]->update(['ordre' => $index + 1]);
            }

            foreach ($this->variantesDe($produit) as $variante) {
                $this->recalculer($variante);
            }
        });
    }

    /**
     * L'ordre des valeurs ne change aucun nom : seulement l'ordre
     * d'affichage partout (vitrine, back-office, recherche).
     *
     * @param  array<int, int>  $identifiants
     */
    public function reordonnerValeurs(OptionProduit $option, array $identifiants): void
    {
        DB::transaction(function () use ($option, $identifiants) {
            $valeurs = ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->get()->keyBy('id');
            $this->exigerLaMemeListe($valeurs->keys()->all(), $identifiants);

            foreach (array_values($identifiants) as $index => $identifiant) {
                $valeurs[$identifiant]->update(['ordre' => $index + 1]);
            }
        });
    }

    /**
     * Retirer une dimension : accepté si les variantes actives n'en
     * utilisent qu'une valeur (« Couleur ajoutée pour rien ») — elles la
     * perdent, rien d'autre ne bouge. Refusé sinon, en nommant ce qui
     * deviendrait identique : le commerçant archive d'abord les variantes
     * qu'il ne vend plus. Jamais la dernière option d'un produit qui a des
     * variantes : elles n'auraient plus rien qui les distingue.
     */
    public function retirerOption(OptionProduit $option): void
    {
        DB::transaction(function () use ($option) {
            $produit = Produit::pourTousEtablissements()->findOrFail($option->produit_id);
            $variantes = VarianteProduit::pourTousEtablissements()
                ->where('produit_id', $produit->id)
                ->with(['valeurs' => fn ($requete) => $requete->pourTousEtablissements()])
                ->get();

            if ($variantes->isNotEmpty()) {
                $this->exigerQueLOptionPuisseEtreRetiree($option, $variantes);

                foreach ($variantes as $variante) {
                    $variante->valeurs()->wherePivot('option_id', $option->id)->detach();
                    $this->recalculer($variante);
                }
            }

            ValeurOption::pourTousEtablissements()->where('option_id', $option->id)->get()->each->delete();
            $option->delete();
        });
    }

    /**
     * @param  Collection<int, VarianteProduit>  $variantes
     */
    private function exigerQueLOptionPuisseEtreRetiree(OptionProduit $option, Collection $variantes): void
    {
        if (OptionProduit::pourTousEtablissements()->where('produit_id', $option->produit_id)->count() === 1) {
            throw ValidationException::withMessages([
                'option' => "« {$option->nom} » est la seule option de ce produit : ses variantes n'auraient plus rien qui les distingue.",
            ]);
        }

        $actives = $variantes->filter(fn (VarianteProduit $variante) => $variante->estActive());
        $valeursUtilisees = $actives
            ->map(fn (VarianteProduit $variante) => $variante->valeurs->firstWhere('pivot.option_id', $option->id)?->libelle)
            ->filter(fn (?string $libelle) => $libelle !== null)->unique()->values();

        if ($valeursUtilisees->count() <= 1) {
            return;
        }

        $collisions = $actives
            ->groupBy(fn (VarianteProduit $variante) => $variante->valeurs->where('pivot.option_id', '!=', $option->id)->pluck('id')->sort()->join('-'))
            ->filter(fn (Collection $groupe) => $groupe->count() > 1)
            ->map(fn (Collection $groupe) => $groupe->pluck('nom')->join(' et '))
            ->values();

        $message = "Vos variantes actives utilisent plusieurs valeurs de « {$option->nom} » ({$valeursUtilisees->join(', ')}).";
        if ($collisions->isNotEmpty()) {
            $message .= ' Sans elle, '.$collisions->join(' ; ').' deviendraient identiques.';
        }

        throw ValidationException::withMessages([
            'option' => $message.' Archivez d\'abord les variantes que vous ne vendez plus.',
        ]);
    }

    /**
     * @param  array<int, int>  $attendus
     * @param  array<int, int>  $recus
     */
    private function exigerLaMemeListe(array $attendus, array $recus): void
    {
        $recus = array_map('intval', $recus);
        sort($attendus);
        $tries = $recus;
        sort($tries);

        if ($attendus !== $tries || count(array_unique($recus)) !== count($recus)) {
            throw ValidationException::withMessages(['ordre' => 'La liste envoyée ne correspond pas aux éléments existants : rechargez la page.']);
        }
    }

    /**
     * La base refuse aussi ces doublons (index sur lower()) ; ici, avec un
     * message que le commerçant comprend.
     */
    private function exigerNomOptionLibre(int $produitId, string $nom, ?int $saufId = null): void
    {
        $existe = OptionProduit::pourTousEtablissements()->where('produit_id', $produitId)->get()
            ->contains(fn (OptionProduit $option) => $option->id !== $saufId && mb_strtolower($option->nom) === mb_strtolower(trim($nom)));

        if ($existe) {
            throw ValidationException::withMessages(['nom' => 'Ce produit a déjà une option « '.trim($nom).' ».']);
        }
    }

    private function exigerLibelleLibre(int $optionId, string $libelle, ?int $saufId = null): void
    {
        $existante = ValeurOption::pourTousEtablissements()->where('option_id', $optionId)->get()
            ->first(fn (ValeurOption $valeur) => $valeur->id !== $saufId && mb_strtolower($valeur->libelle) === mb_strtolower(trim($libelle)));

        if ($existante !== null) {
            throw ValidationException::withMessages([
                'libelle' => $existante->archivee_le === null
                    ? "« {$existante->libelle} » existe déjà dans cette option."
                    : "« {$existante->libelle} » existe déjà, archivée : restaurez-la plutôt que de la recréer.",
            ]);
        }
    }

    private function aServi(VarianteProduit $variante): bool
    {
        return LigneCommande::pourTousEtablissements()->where('variante_id', $variante->id)->exists()
            || ReservationStock::pourTousEtablissements()->where('variante_id', $variante->id)->exists();
    }

    /**
     * La même photo pour tout un groupe (les 7 sandales noires) en une
     * écriture : une requête par variante coûtait 14 secondes sur le
     * serveur de développement, bien plus sur un réseau mobile.
     *
     * @param  array<int, int>  $identifiantsVariantes
     */
    public function attribuerImage(Produit $produit, array $identifiantsVariantes, ?int $mediaId): void
    {
        VarianteProduit::pourTousEtablissements()
            ->where('produit_id', $produit->id)
            ->whereIn('id', $identifiantsVariantes)
            ->update(['media_id' => $mediaId]);
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
