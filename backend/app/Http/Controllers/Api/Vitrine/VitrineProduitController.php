<?php

namespace App\Http\Controllers\Api\Vitrine;

use App\Enums\StatutProduit;
use App\Enums\StatutVariante;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vitrine\LienWhatsappRequest;
use App\Http\Resources\Vitrine\ProduitVitrineDetailResource;
use App\Http\Resources\Vitrine\ProduitVitrineResource;
use App\Models\Produit;
use App\Services\Vitrine\GenerateurLienWhatsapp;
use App\Support\Tenancy\ContexteEtablissement;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * API publique de la vitrine — aucune authentification (voir routes/api.php,
 * ce groupe ne passe QUE par "resoudre.etablissement"). Ne renvoie jamais un
 * produit brouillon ou archivé : la condition "statut publié" fait partie de
 * CHAQUE requête, pas d'un filtre optionnel, pour qu'un identifiant deviné
 * échoue en 404 identique à un identifiant inexistant — jamais un message
 * qui confirmerait l'existence d'un produit non publié.
 *
 * index() et show() mettent en cache le TABLEAU déjà résolu par la resource
 * (->response()->getData(true)), jamais les modèles Eloquent bruts : la
 * configuration par défaut du projet (cache.serializable_classes = false,
 * voir config/cache.php) interdit à unserialize() de reconstruire quoi que
 * ce soit d'autre qu'un tableau ou un scalaire — un modèle mis en cache
 * directement reviendrait un __PHP_Incomplete_Class au prochain accès.
 */
class VitrineProduitController extends Controller
{
    public function __construct(
        private readonly GenerateurLienWhatsapp $generateurLien,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'categorie' => ['nullable', 'integer'],
            'recherche' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $etablissementId = app(ContexteEtablissement::class)->id();
        $page = $request->integer('page', 1);

        $cle = CacheVitrine::cle($etablissementId, 'produits:'.md5((string) json_encode([
            'categorie' => $request->integer('categorie'),
            'recherche' => $request->string('recherche')->value(),
            'page' => $page,
        ])));

        $donnees = Cache::remember($cle, 60, function () use ($request, $page) {
            $produits = Produit::query()
                ->where('statut', StatutProduit::Publie)
                ->with(['medias' => fn ($requete) => $requete->orderByPivot('ordre'), 'categorie', 'variantes'])
                ->when($request->filled('categorie'), fn ($requete) => $requete->where('categorie_id', $request->integer('categorie')))
                ->when($request->filled('recherche'), fn ($requete) => $requete->where('nom', 'like', '%'.$request->string('recherche').'%'))
                ->orderBy('nom')
                ->paginate(24, page: $page);

            return ProduitVitrineResource::collection($produits)->response()->getData(true);
        });

        return response()->json($donnees);
    }

    public function show(int $id): JsonResponse
    {
        $etablissementId = app(ContexteEtablissement::class)->id();
        $cle = CacheVitrine::cle($etablissementId, "produit:{$id}");

        $donnees = Cache::remember($cle, 60, function () use ($id) {
            $produit = Produit::query()
                ->where('statut', StatutProduit::Publie)
                ->with(['medias' => fn ($requete) => $requete->orderByPivot('ordre'), 'categorie', 'variantes'])
                ->find($id);

            abort_if($produit === null, 404);

            return (new ProduitVitrineDetailResource($produit))->response()->getData(true);
        });

        return response()->json($donnees);
    }

    /**
     * Construit et renvoie l'URL wa.me — voir GenerateurLienWhatsapp pour la
     * raison d'être de cette route côté serveur plutôt qu'un lien composé en
     * React.
     */
    public function lienWhatsapp(LienWhatsappRequest $request, int $id): JsonResponse
    {
        $produit = Produit::where('statut', StatutProduit::Publie)->findOrFail($id);

        $variante = null;

        if ($request->filled('variante_id')) {
            $variante = $produit->variantes()
                ->where('statut', StatutVariante::Actif)
                ->findOrFail($request->integer('variante_id'));
        }

        $etablissementCourant = app(ContexteEtablissement::class)->obtenir();
        $numero = $etablissementCourant?->telephone_whatsapp ?? $etablissementCourant?->telephone;

        abort_if(blank($numero), 422, "Cet établissement n'a pas de numéro WhatsApp configuré.");

        return response()->json([
            'url' => $this->generateurLien->generer($produit, $variante, $numero, $this->cleVisiteur($request)),
        ]);
    }

    /**
     * La session d'abord : la vitrine en ouvre une (statefulApi, cookie par
     * sous-domaine), vérifié dans un vrai navigateur — deux clics portent le
     * même identifiant de session côté serveur. L'IP seulement à défaut :
     * derrière un opérateur mobile, elle confond des centaines d'abonnés.
     * Empreinte sha256, jamais la valeur : l'identifiant de session brut
     * permettrait de se faire passer pour ce visiteur.
     */
    private function cleVisiteur(Request $request): string
    {
        $source = $request->hasSession() && $request->session()->getId() !== ''
            ? 'session:'.$request->session()->getId()
            : 'ip:'.$request->ip();

        return hash('sha256', $source);
    }
}
