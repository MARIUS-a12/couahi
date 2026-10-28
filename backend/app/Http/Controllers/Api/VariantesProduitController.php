<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutVariante;
use App\Http\Controllers\Controller;
use App\Http\Resources\StructureVariantesResource;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use App\Services\Catalogue\GererVariantes;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * L'écran des variantes d'un produit (B2). Ce contrôleur ne compose
 * jamais une variante lui-même : il valide, vérifie que l'option, la
 * valeur ou la variante appartient bien au produit de l'URL, puis délègue
 * à GererVariantes — seul auteur de la composition (voir sa docblock).
 *
 * Toute écriture renvoie la structure complète (StructureVariantesResource)
 * et invalide la vitrine, comme une modification de produit.
 */
class VariantesProduitController extends Controller
{
    public function __construct(private readonly GererVariantes $variantes) {}

    public function show(Produit $produit): StructureVariantesResource
    {
        Gate::authorize('view', $produit);

        return new StructureVariantesResource($produit);
    }

    /**
     * Le seul garde-fou sur des libellés libres : proposer, pour une option
     * du même nom, les valeurs déjà utilisées dans CETTE boutique (le scope
     * d'établissement s'applique). Suggestion, jamais contrainte — sans
     * elle, « M », « m » et « Moyen » coexisteraient dans trois produits.
     * Les valeurs sortent dans leur ordre habituel (rang moyen), le
     * libellé retenu est l'écriture la plus fréquente ; les valeurs
     * archivées ne sont jamais proposées.
     */
    public function suggestions(): JsonResponse
    {
        Gate::authorize('viewAny', Produit::class);

        $parNom = OptionProduit::with('valeurs')->get()->groupBy(fn (OptionProduit $option) => mb_strtolower(trim($option->nom)));

        return response()->json(['data' => $parNom->map(fn ($options) => [
            // (string) : une clé de tableau « 39 » devient l'entier 39 en PHP.
            'nom' => (string) $options->countBy('nom')->sortDesc()->keys()->first(),
            'valeurs' => $options->flatMap->valeurs
                ->filter(fn (ValeurOption $valeur) => $valeur->archivee_le === null)
                ->groupBy(fn (ValeurOption $valeur) => mb_strtolower(trim($valeur->libelle)))
                ->map(fn ($valeurs) => ['libelle' => (string) $valeurs->countBy('libelle')->sortDesc()->keys()->first(), 'rang' => $valeurs->avg('ordre')])
                ->sortBy('rang')
                ->pluck('libelle')
                ->values(),
        ])->values()]);
    }

    public function ajouterOption(Request $request, Produit $produit): JsonResponse
    {
        Gate::authorize('update', $produit);
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:60'],
            'valeur_des_variantes_existantes' => ['nullable', 'string', 'max:60'],
        ], ['nom.required' => "Donnez un nom à l'option (Taille, Couleur, Pointure…)."]);

        $this->variantes->ajouterOption($produit, $donnees['nom'], $donnees['valeur_des_variantes_existantes'] ?? null);

        return $this->structure($produit, 201);
    }

    public function renommerOption(Request $request, Produit $produit, OptionProduit $option): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $option->produit_id);
        $donnees = $request->validate(['nom' => ['required', 'string', 'max:60']]);

        $this->variantes->renommerOption($option, $donnees['nom']);

        return $this->structure($produit);
    }

    public function reordonnerOptions(Request $request, Produit $produit): JsonResponse
    {
        Gate::authorize('update', $produit);
        $donnees = $request->validate(['ordre' => ['required', 'array', 'min:1'], 'ordre.*' => ['integer']]);

        $this->variantes->reordonnerOptions($produit, $donnees['ordre']);

        return $this->structure($produit);
    }

    public function retirerOption(Produit $produit, OptionProduit $option): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $option->produit_id);

        $this->variantes->retirerOption($option);

        return $this->structure($produit);
    }

    /**
     * « blanc, noir » tapé dans le champ, ce sont deux valeurs : une
     * virgule sépare toujours des valeurs, jamais une seule nommée
     * « blanc, noir ». Plusieurs valeurs passent par ajouterValeurs (tout
     * ou rien, doublons sautés).
     */
    public function ajouterValeur(Request $request, Produit $produit, OptionProduit $option): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $option->produit_id);
        $donnees = $request->validate(['libelle' => ['required', 'string', 'max:600']], ['libelle.required' => 'Saisissez une valeur (39, M, 100 ml…).']);

        $libelles = array_values(array_filter(array_map('trim', explode(',', $donnees['libelle'])), fn (string $libelle) => $libelle !== ''));

        if ($libelles === []) {
            throw ValidationException::withMessages(['libelle' => ['Saisissez une valeur (39, M, 100 ml…).']]);
        }

        foreach ($libelles as $libelle) {
            if (mb_strlen($libelle) > 60) {
                throw ValidationException::withMessages(['libelle' => ["« {$libelle} » est trop long : 60 caractères au plus par valeur."]]);
            }
        }

        count($libelles) === 1
            ? $this->variantes->ajouterValeur($option, $libelles[0])
            : $this->variantes->ajouterValeurs($option, $libelles);

        return $this->structure($produit, 201);
    }

    /**
     * Plusieurs valeurs en une requête (plage numérique, « Tout ajouter ») :
     * voir GererVariantes::ajouterValeurs(). 50 au plus — une plage de 1 à
     * 100 000 tapée par erreur ne doit pas créer 100 000 lignes.
     */
    public function ajouterValeurs(Request $request, Produit $produit, OptionProduit $option): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $option->produit_id);
        $donnees = $request->validate([
            'libelles' => ['required', 'array', 'min:1', 'max:50'],
            'libelles.*' => ['required', 'string', 'max:60'],
        ], [
            'libelles.max' => 'Pas plus de 50 valeurs à la fois.',
        ]);

        $this->variantes->ajouterValeurs($option, $donnees['libelles']);

        return $this->structure($produit, 201);
    }

    public function reordonnerValeurs(Request $request, Produit $produit, OptionProduit $option): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $option->produit_id);
        $donnees = $request->validate(['ordre' => ['required', 'array', 'min:1'], 'ordre.*' => ['integer']]);

        $this->variantes->reordonnerValeurs($option, $donnees['ordre']);

        return $this->structure($produit);
    }

    public function renommerValeur(Request $request, Produit $produit, ValeurOption $valeur): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $valeur->option->produit_id);
        $donnees = $request->validate(['libelle' => ['required', 'string', 'max:60']]);

        $this->variantes->renommerValeur($valeur, $donnees['libelle']);

        return $this->structure($produit);
    }

    public function archiverValeur(Produit $produit, ValeurOption $valeur): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $valeur->option->produit_id);

        $resultat = $this->variantes->archiverValeur($valeur);

        return $this->structure($produit, 200, ['resultat' => $resultat]);
    }

    public function restaurerValeur(Produit $produit, ValeurOption $valeur): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $valeur->option->produit_id);

        $this->variantes->restaurerValeur($valeur);

        return $this->structure($produit);
    }

    /**
     * La grille cochée : plusieurs combinaisons, toutes ou aucune. Chaque
     * identifiant de valeur doit appartenir à ce produit ; le service
     * vérifie ensuite qu'il y en a exactement une par option.
     */
    public function creerVariantes(Request $request, Produit $produit): JsonResponse
    {
        Gate::authorize('update', $produit);
        $donnees = $request->validate([
            'combinaisons' => ['required', 'array', 'min:1', 'max:200'],
            'combinaisons.*.valeur_ids' => ['required', 'array', 'min:1'],
            'combinaisons.*.valeur_ids.*' => ['integer'],
            'combinaisons.*.quantite_stock' => ['nullable', 'integer', 'min:0'],
            'combinaisons.*.prix' => ['nullable', 'integer', 'min:0'],
            'combinaisons.*.media_id' => ['nullable', 'integer', $this->regleImage($produit)],
        ]);

        $valeursDuProduit = ValeurOption::whereIn('option_id', OptionProduit::where('produit_id', $produit->id)->pluck('id'))->get()->keyBy('id');

        $combinaisons = collect($donnees['combinaisons'])->map(function (array $combinaison) use ($valeursDuProduit) {
            $valeurs = collect($combinaison['valeur_ids'])->map(fn (int $id) => $valeursDuProduit[$id] ?? abort(422, 'Une valeur ne fait pas partie de ce produit.'));

            return [
                'valeurs' => $valeurs->all(),
                'attributs' => array_filter([
                    'quantite_stock' => $combinaison['quantite_stock'] ?? 0,
                    'prix' => $combinaison['prix'] ?? null,
                    'media_id' => $combinaison['media_id'] ?? null,
                ], fn ($valeur) => $valeur !== null),
            ];
        })->all();

        $this->variantes->creerVariantes($produit, $combinaisons);

        return $this->structure($produit, 201);
    }

    /**
     * Le stock et la disponibilité seuls relèvent de gerer_stock (comme
     * PATCH /produits/{produit}/stock) ; le reste, de gerer_catalogue.
     */
    public function modifierVariante(Request $request, Produit $produit, VarianteProduit $variante): JsonResponse
    {
        $this->exigerAppartenance($produit, $variante->produit_id);
        $donnees = $request->validate([
            'quantite_stock' => ['sometimes', 'integer', 'min:0'],
            'disponible' => ['sometimes', 'boolean'],
            'prix' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'media_id' => ['sometimes', 'nullable', 'integer', $this->regleImage($produit)],
            'statut' => ['sometimes', Rule::enum(StatutVariante::class)],
        ], ['prix.integer' => 'Le prix doit être un nombre entier (FCFA), sans décimale.']);

        $stockSeulement = array_diff(array_keys($donnees), ['quantite_stock', 'disponible']) === [];
        Gate::authorize($stockSeulement ? 'ajusterStock' : 'update', $produit);

        $this->variantes->modifierVariante($variante, $donnees);

        return $this->structure($produit);
    }

    /**
     * La photo d'un groupe de variantes en une requête. Les identifiants
     * hors de ce produit sont refusés, pas ignorés.
     */
    public function attribuerImage(Request $request, Produit $produit): JsonResponse
    {
        Gate::authorize('update', $produit);
        $donnees = $request->validate([
            'variante_ids' => ['required', 'array', 'min:1'],
            'variante_ids.*' => ['integer'],
            'media_id' => ['present', 'nullable', 'integer', $this->regleImage($produit)],
        ]);
        $connues = VarianteProduit::where('produit_id', $produit->id)->whereIn('id', $donnees['variante_ids'])->count();
        abort_unless($connues === count(array_unique($donnees['variante_ids'])), 404);

        $this->variantes->attribuerImage($produit, $donnees['variante_ids'], $donnees['media_id']);

        return $this->structure($produit);
    }

    public function retirerVariante(Produit $produit, VarianteProduit $variante): JsonResponse
    {
        Gate::authorize('update', $produit);
        $this->exigerAppartenance($produit, $variante->produit_id);

        $resultat = $this->variantes->retirerVariante($variante);

        return $this->structure($produit, 200, ['resultat' => $resultat]);
    }

    /**
     * L'image d'une variante est une des photos de SON produit (la base le
     * garantit aussi, par clé composée vers media_produit).
     */
    private function regleImage(Produit $produit): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec) use ($produit): void {
            if ($valeur !== null && ! $produit->medias()->whereKey($valeur)->exists()) {
                $echec("Cette photo n'appartient pas à ce produit.");
            }
        };
    }

    /**
     * Le scope d'établissement garantit déjà qu'on ne touche que ses
     * propres lignes ; ceci garantit qu'on touche celles du produit de
     * l'URL, pas celles d'un autre produit de la même boutique.
     */
    private function exigerAppartenance(Produit $produit, int $produitId): void
    {
        abort_unless($produit->id === $produitId, 404);
    }

    private function structure(Produit $produit, int $statut = 200, array $enPlus = []): JsonResponse
    {
        CacheVitrine::invalider($produit->etablissement_id);

        return (new StructureVariantesResource($produit))->additional($enPlus)->response()->setStatusCode($statut);
    }
}
