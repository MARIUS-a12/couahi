<?php

namespace App\Services\Etablissements;

use App\Exceptions\ImageTropGrandeException;
use App\Models\Etablissement;
use App\Services\Medias\GenerateurVariantesImage;
use App\Support\Vitrine\CacheVitrine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Passe par le système de médias existant (voir MediaProduitController pour
 * le même principe appliqué aux photos produit) plutôt qu'un champ fichier
 * improvisé : deux formats carrés (64px, 256px), WebP avec repli JPEG. Un
 * seul logo par établissement — remplacer l'ancien efface ses fichiers,
 * jamais de logos orphelins qui s'accumulent sur le disque.
 */
class MettreAJourLogoEtablissement
{
    public function __construct(
        private readonly GenerateurVariantesImage $generateur,
    ) {}

    public function remplacer(Etablissement $etablissement, UploadedFile $fichier): Etablissement
    {
        $disque = (string) config('medias.disque');

        try {
            $variantes = $this->generateur->generer($fichier, $disque, config('medias.formats_logo'), carre: true);
        } catch (ImageTropGrandeException $exception) {
            throw ValidationException::withMessages(['logo' => [$exception->getMessage()]]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'logo' => ["Le fichier envoyé n'est pas une image valide."],
            ]);
        }

        $ancienLogo = $etablissement->logoMedia;

        $media = $etablissement->medias()->create([
            'disque' => $disque,
            'chemin' => $variantes['petit']['webp'],
            'url' => Storage::disk($disque)->url($variantes['petit']['webp']),
            'type_mime' => 'image/webp',
            'taille' => $variantes['petit']['taille_webp'],
            'metadonnees_json' => ['variantes' => $variantes],
        ]);

        $etablissement->update(['logo_media_id' => $media->id]);

        if ($ancienLogo !== null) {
            $ancienLogo->supprimerFichiersDisque();
            $ancienLogo->delete();
        }

        CacheVitrine::invalider($etablissement->id);

        return $etablissement->fresh();
    }

    public function supprimer(Etablissement $etablissement): Etablissement
    {
        $logo = $etablissement->logoMedia;

        if ($logo !== null) {
            $etablissement->update(['logo_media_id' => null]);
            $logo->supprimerFichiersDisque();
            $logo->delete();
        }

        CacheVitrine::invalider($etablissement->id);

        return $etablissement->fresh();
    }
}
