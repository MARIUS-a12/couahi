<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImageTropGrandeException;
use App\Exceptions\LimiteMediasAtteinteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReordonnerMediasProduitRequest;
use App\Http\Requests\StoreMediaProduitRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\Produit;
use App\Services\Catalogue\GererVariantes;
use App\Services\Medias\GenerateurVariantesImage;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MediaProduitController extends Controller
{
    public function __construct(
        private readonly GenerateurVariantesImage $generateur,
    ) {}

    /**
     * Le comptage rapide avant traitement rejette d'emblée le cas courant
     * (maximum déjà atteint). Le second comptage, refait DANS la transaction avec
     * verrou sur la ligne produit, ne rattrape que la course rare entre deux
     * envois concurrents — voir LimiteMediasAtteinteException.
     */
    public function store(StoreMediaProduitRequest $request, Produit $produit): JsonResponse
    {
        $max = (int) config('medias.max_par_produit');

        if ($produit->medias()->count() >= $max) {
            return $this->reponseLimiteAtteinte();
        }

        $disque = (string) config('medias.disque');

        // Filet de sécurité au-delà de la règle "image"/"mimes" de la
        // requête (basée sur le contenu réel du fichier, mais qu'un fichier
        // techniquement valide-mais-corrompu peut quand même franchir) :
        // si l'image ne se décode pas, le rejet reste un 422 propre plutôt
        // qu'une erreur serveur.
        try {
            $variantes = $this->generateur->generer($request->file('photo'), $disque);
        } catch (ImageTropGrandeException $exception) {
            throw ValidationException::withMessages(['photo' => [$exception->getMessage()]]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'photo' => ["Le fichier envoyé n'est pas une image valide."],
            ]);
        }

        try {
            $media = DB::transaction(function () use ($produit, $disque, $variantes, $max) {
                Produit::whereKey($produit->id)->lockForUpdate()->first();

                $nombreActuel = $produit->medias()->count();

                if ($nombreActuel >= $max) {
                    throw new LimiteMediasAtteinteException;
                }

                $media = Media::create([
                    'disque' => $disque,
                    'chemin' => $variantes['moyenne']['webp'],
                    'url' => Storage::disk($disque)->url($variantes['moyenne']['webp']),
                    'type_mime' => 'image/webp',
                    'taille' => $variantes['moyenne']['taille_webp'],
                    'metadonnees_json' => ['variantes' => $variantes],
                ]);

                $produit->medias()->attach($media->id, [
                    'ordre' => $nombreActuel,
                    'est_principal' => $nombreActuel === 0,
                ]);

                return $media;
            });
        } catch (LimiteMediasAtteinteException) {
            $this->generateur->supprimer($disque, $variantes);

            return $this->reponseLimiteAtteinte();
        }

        CacheVitrine::invalider($produit->etablissement_id);

        return (new MediaResource($produit->medias()->orderByPivot('ordre')->findOrFail($media->id)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Détache le média de CE produit. S'il n'est attaché à aucun autre
     * produit, efface aussi ses fichiers et sa ligne — un média encore
     * utilisé ailleurs ne perd rien. Les variantes qui l'avaient pour image
     * reviennent d'abord aux photos du produit : la base refuse de détacher
     * une photo encore portée par une variante (clé composée vers
     * media_produit).
     */
    public function destroy(Produit $produit, Media $media): JsonResponse
    {
        Gate::authorize('update', $produit);

        abort_unless($produit->medias()->whereKey($media->id)->exists(), 404);

        DB::transaction(function () use ($produit, $media) {
            app(GererVariantes::class)->retirerImage($produit, $media);
            $produit->medias()->detach($media->id);

            if (! $media->produits()->exists()) {
                $media->supprimerFichiersDisque();
                $media->delete();
            }

            $this->resynchroniserOrdre($produit);
        });

        CacheVitrine::invalider($produit->etablissement_id);

        return response()->json(null, 204);
    }

    public function reordonner(ReordonnerMediasProduitRequest $request, Produit $produit): AnonymousResourceCollection
    {
        foreach ($request->validated('ordre') as $index => $mediaId) {
            $produit->medias()->updateExistingPivot($mediaId, [
                'ordre' => $index,
                'est_principal' => $index === 0,
            ]);
        }

        CacheVitrine::invalider($produit->etablissement_id);

        return MediaResource::collection($produit->medias()->orderByPivot('ordre')->get());
    }

    /**
     * Après un détachement, comble le trou laissé dans "ordre" et s'assure
     * que la première photo restante porte bien est_principal — sans ça, un
     * produit qui perdrait sa photo principale se retrouverait sans aucune
     * photo principale désignée.
     */
    private function resynchroniserOrdre(Produit $produit): void
    {
        $medias = $produit->medias()->orderByPivot('ordre')->get();

        foreach ($medias as $index => $media) {
            $produit->medias()->updateExistingPivot($media->id, [
                'ordre' => $index,
                'est_principal' => $index === 0,
            ]);
        }
    }

    private function reponseLimiteAtteinte(): JsonResponse
    {
        $max = (int) config('medias.max_par_produit');

        return response()->json([
            'message' => "Ce produit a déjà {$max} photos, le maximum autorisé.",
        ], 422);
    }
}
