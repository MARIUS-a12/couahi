<?php

namespace Tests\Feature;

use App\Services\Medias\GenerateurVariantesImage;
use Database\Seeders\GenerateurVariantesImageDemo;
use Database\Seeders\ImagesDemo;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Le générateur des données de démo rejoue ce que le vrai a produit (voir
 * sa docblock). Ce test vérifie que le rejeu ne ment pas : mêmes octets
 * que le vrai générateur, mêmes dimensions, mais des fichiers distincts —
 * jamais deux médias sur un même fichier. Il ne remplace pas le test de
 * génération lui-même (MediasProduitApiTest), qui passe toujours par
 * GenerateurVariantesImage seul.
 */
class GenerateurVariantesImageDemoTest extends TestCase
{
    public function test_le_rejeu_redonne_a_loctet_pres_ce_que_le_vrai_generateur_produit(): void
    {
        $source = (new ImagesDemo(40))->photoProduit('Riz parfumé long grain', 0, 'riz.jpg');
        $demo = new GenerateurVariantesImageDemo;

        $premier = $demo->generer($source, 'public');
        $rejeu = $demo->generer($source, 'public');
        $reel = app(GenerateurVariantesImage::class)->generer($source, 'public');

        foreach ($reel as $format => $attendu) {
            foreach (['webp', 'jpg'] as $encodage) {
                $this->assertNotSame($premier[$format][$encodage], $rejeu[$format][$encodage], 'jamais le même fichier');
                Storage::disk('public')->assertExists($rejeu[$format][$encodage]);
                $this->assertSame(
                    Storage::disk('public')->get($attendu[$encodage]),
                    Storage::disk('public')->get($rejeu[$format][$encodage]),
                    "{$format} {$encodage} identique au vrai générateur",
                );
            }
            $this->assertSame([$attendu['largeur'], $attendu['hauteur']], [$rejeu[$format]['largeur'], $rejeu[$format]['hauteur']]);
            $this->assertSame($attendu['taille_webp'], $rejeu[$format]['taille_webp']);
        }
    }
}
