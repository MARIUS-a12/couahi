<?php

namespace App\Http\Controllers\Api;

use App\Enums\Canal;
use App\Enums\StatutProduit;
use App\Enums\StatutVariante;
use App\Exceptions\ArticleIndisponibleException;
use App\Exceptions\DemandeDejaTransformeeException;
use App\Exceptions\DoublonPossibleException;
use App\Exceptions\SelectionVarianteInvalideException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaisirCommandeManuelleRequest;
use App\Models\Commande;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use App\Models\VarianteProduit;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Commandes\SaisirCommandeManuelle;
use App\Support\Telephone\NormaliseurTelephone;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lot C — la saisie manuelle des commandes au back-office, réservée à
 * « creer_commandes ». Ne fait que lire la requête et déléguer à
 * SaisirCommandeManuelle : aucun prix, aucun stock, aucun statut ici.
 */
class CommandeManuelleController extends Controller
{
    private const REFERENCE_INCONNUE = 'Cette référence n’existe pas dans votre boutique.';

    public function __construct(
        private readonly SaisirCommandeManuelle $saisir,
    ) {}

    /**
     * Ce qui se vend dans la boutique : un produit sans variante, ou chaque
     * variante active d'un produit qui en a (« Sandales — 39 / Noir », dans
     * l'ordre des options du commerçant). Prix et stock disponible lus en
     * base. Les produits en brouillon y sont : un article peut se vendre au
     * comptoir sans être sur le site. Les archivés non.
     */
    public function articles(Request $request): JsonResponse
    {
        $this->autoriser($request);
        $recherche = mb_strtolower(trim((string) $request->query('recherche', '')));

        $produits = Produit::query()
            ->where('statut', '!=', StatutProduit::Archive)
            ->when($recherche !== '', fn ($requete) => $requete->whereRaw('lower(nom) like ?', ['%'.$recherche.'%']))
            // « Vendre » depuis la fiche ou la liste des produits : ce produit seul.
            ->when($request->filled('produit'), fn ($requete) => $requete->whereKey($request->integer('produit')))
            ->with(['variantes', 'medias' => fn ($requete) => $requete->orderByPivot('ordre')])
            ->orderBy('nom')
            ->limit(30)
            ->get();

        $articles = $produits->flatMap(function (Produit $produit) {
            $photo = $produit->medias->first()?->urlVariante('vignette', 'jpg');

            if ($produit->variantes->isEmpty()) {
                return [$this->article($produit, null, $photo)];
            }

            return $produit->variantes
                ->filter(fn (VarianteProduit $variante) => $variante->statut === StatutVariante::Actif)
                ->sortBy('id')
                ->map(fn (VarianteProduit $variante) => $this->article($produit, $variante, $photo))
                ->values();
        });

        return response()->json(['data' => $articles->values()]);
    }

    /**
     * Les communes proposées et leurs frais, exactement comme à la vitrine
     * (Etablissement::zonesLivraisonProposees). Ici et pas sur l'écran
     * Livraison, réservé à gerer_parametres, ni sur la route de la vitrine,
     * coupée quand l'abonnement est suspendu : un caissier saisit quand même.
     */
    public function zones(Request $request): JsonResponse
    {
        $this->autoriser($request);

        $etablissement = app(ContexteEtablissement::class)->obtenir();

        return response()->json([
            'data' => $etablissement->zonesLivraisonProposees()
                ->map(fn ($zone) => ['id' => $zone->id, 'nom' => $zone->nom, 'frais' => $zone->frais])
                ->values(),
            'autre_commune' => $etablissement->zoneAutreCommune()?->only('frais'),
        ]);
    }

    /**
     * Le client d'un numéro déjà connu, avec la commune et le quartier de sa
     * dernière commande : le formulaire les préremplit, modifiables.
     */
    public function client(Request $request): JsonResponse
    {
        $this->autoriser($request);
        $telephone = NormaliseurTelephone::normaliser((string) $request->query('telephone', ''));

        $client = $telephone === null ? null : app(ContexteEtablissement::class)->obtenir()->clients()->where('telephone', $telephone)->first();

        if ($client === null) {
            return response()->json(['data' => null]);
        }

        $derniere = $client->commandes()->whereNotNull('commune')->latest('id')->first();

        return response()->json(['data' => [
            'nom' => $client->nom,
            'commune' => $derniere?->commune,
            'quartier' => $derniere?->quartier,
        ]]);
    }

    /**
     * Une référence collée dans une saisie neuve : l'article de la demande,
     * que le formulaire ajoute comme le fait « Transformer en commande », et
     * la commande qu'elle est déjà devenue, pour refuser avant la saisie.
     * L'article est nul si le produit est archivé, la variante retirée, ou si
     * la demande ne dit pas quelle variante d'un produit qui en a.
     */
    public function reference(Request $request): JsonResponse
    {
        $this->autoriser($request);
        $reference = $this->trouverReference((string) $request->query('reference', ''));

        if ($reference === null) {
            return response()->json(['message' => self::REFERENCE_INCONNUE], 404);
        }

        $reference->load(['produit.variantes', 'produit.medias' => fn ($requete) => $requete->orderByPivot('ordre'), 'variante', 'commande']);
        $produit = $reference->produit;
        $variante = $reference->variante;
        $vendable = $produit !== null
            && $produit->statut !== StatutProduit::Archive
            && ($variante === null ? $produit->variantes->isEmpty() : $variante->statut === StatutVariante::Actif);

        return response()->json(['data' => [
            'code' => $reference->code,
            'article' => $vendable ? $this->article($produit, $variante, $produit->medias->first()?->urlVariante('vignette', 'jpg')) : null,
            'commande' => $reference->commande === null ? null : ['id' => $reference->commande->id, 'numero' => $reference->commande->numero],
        ]]);
    }

    public function store(SaisirCommandeManuelleRequest $request): JsonResponse
    {
        $etablissement = app(ContexteEtablissement::class)->obtenir();
        $donneesClient = $request->validated('client');

        try {
            $commande = $this->saisir->executer(
                etablissement: $etablissement,
                canal: Canal::from($request->validated('canal')),
                lignes: array_map(
                    fn (array $ligne) => new LigneCommandeDemandee($ligne['produit_id'], $ligne['variante_id'] ?? null, $ligne['quantite']),
                    $request->validated('lignes'),
                ),
                client: empty($donneesClient['telephone']) ? null : [
                    'telephone' => NormaliseurTelephone::normaliser($donneesClient['telephone']),
                    'nom' => $donneesClient['nom'] ?? null,
                ],
                commune: $request->validated('commune'),
                autreCommune: $request->boolean('autre_commune'),
                quartier: $request->validated('quartier'),
                referenceWhatsapp: $this->referenceCollee($request->validated('reference')),
                cleIdempotence: $request->validated('cle_idempotence'),
                confirmerDoublon: $request->boolean('confirmer_doublon'),
                auteur: $request->user(),
                adresseIp: $request->ip(),
            );
        } catch (DoublonPossibleException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'doublons' => $exception->commandes->map(fn (Commande $commande) => [
                    'id' => $commande->id,
                    'numero' => $commande->numero,
                    'statut_libelle' => $commande->statut->libelle(),
                    'total' => $commande->total,
                    'created_at' => $commande->created_at,
                ])->all(),
            ], 409);
        } catch (DemandeDejaTransformeeException $exception) {
            // Pas un avertissement qu'on confirme : un refus, avec la commande.
            return response()->json([
                'message' => $exception->getMessage(),
                'deja_transformee' => ['id' => $exception->commande->id, 'numero' => $exception->commande->numero],
            ], 409);
        } catch (ArticleIndisponibleException $exception) {
            return response()->json(['message' => $this->messageStock($exception)], 422);
        } catch (SelectionVarianteInvalideException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $commande->id,
            'numero' => $commande->numero,
            'statut' => $commande->statut->value,
            'total' => $commande->total,
        ]], 201);
    }

    /**
     * @return array{produit_id: int, variante_id: int|null, nom: string, variante: string|null, prix: int, stock: array{mode: string, disponible: int|bool}, photo: string|null}
     */
    private function article(Produit $produit, ?VarianteProduit $variante, ?string $photo): array
    {
        $porteur = $variante ?? $produit;

        return [
            'produit_id' => $produit->id,
            'variante_id' => $variante?->id,
            'nom' => $produit->nom,
            'variante' => $variante?->nom,
            'prix' => $variante !== null ? ($variante->prix ?? $produit->prix) : $produit->prix,
            'stock' => $produit->mode_stock->value === 'compte'
                ? ['mode' => 'compte', 'disponible' => max(0, $porteur->quantite_stock - $porteur->quantite_reservee)]
                : ['mode' => 'interrupteur', 'disponible' => (bool) $porteur->disponible],
            'photo' => $photo,
        ];
    }

    /**
     * La référence collée : le message WhatsApp entier ou le seul code. Elle
     * doit exister dans CETTE boutique (le scope d'établissement écarte les
     * autres) ; expirée, elle reste reconnaissable, pour ne pas créer deux
     * fois la commande d'un même message.
     */
    private function referenceCollee(?string $collee): ?ReferenceWhatsapp
    {
        if ($collee === null || $collee === '') {
            return null;
        }

        return $this->trouverReference($collee)
            ?? throw ValidationException::withMessages(['reference' => [self::REFERENCE_INCONNUE]]);
    }

    private function trouverReference(string $collee): ?ReferenceWhatsapp
    {
        $code = preg_match('/\[REF:([A-Za-z0-9]{8})\]/', $collee, $correspondance) ? $correspondance[1] : trim($collee);

        return ReferenceWhatsapp::where('code', $code)->first();
    }

    /**
     * Le back-office dit tout ce qu'il faut pour corriger : quel article,
     * combien demandés, combien restent. La vitrine garde son message, qui
     * ne révèle pas le stock exact.
     */
    private function messageStock(ArticleIndisponibleException $exception): string
    {
        if ($exception->quantiteDisponible === null) {
            return $exception->getMessage();
        }

        return sprintf(
            'Stock insuffisant pour %s : %d demandé(s), %d disponible(s).',
            $exception->nomArticle(),
            $exception->quantiteDemandee,
            $exception->quantiteDisponible,
        );
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->peut('creer_commandes'), 403);
    }
}
