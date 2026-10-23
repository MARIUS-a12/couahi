<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * La garantie « une valeur pour chaque option » ne s'exprime pas en SQL :
 * elle ne tient que si GererVariantes est le seul code à composer une
 * variante. Ce test cherche, dans le code applicatif, les seeders et les
 * routes, toute écriture directe d'une option, d'une valeur, d'un lien
 * variante-valeur, d'une signature ou d'un nom de variante.
 *
 * Hors champ, assumé : les migrations (la migration des variantes de démo
 * est un instantané historique, figé) et les factories de test, qui
 * fabriquent aussi des états invalides pour prouver que la base ou la
 * commande de vérification les refusent.
 */
class EcrituresVariantesCentraliseesTest extends TestCase
{
    private const AUTEUR = 'app/Services/Catalogue/GererVariantes.php';

    private const ECRITURES = [
        // Avec ou sans espace de noms : « new \App\Models\VarianteProduit( ».
        '/new\s+[\w\\\\]*(OptionProduit|ValeurOption|VarianteProduit)\s*\(/',
        '/(OptionProduit|ValeurOption|VarianteProduit)::(create|forceCreate|insert|upsert|updateOrCreate|firstOrCreate)\s*\(/',
        '/VarianteProduit::factory\s*\(/',
        '/->valeurs\(\)\s*->\s*(attach|detach|sync|syncWithoutDetaching|updateExistingPivot)\s*\(/',
        '/DB::table\(\s*.variante_valeur/',
        '/->(signature|nom)\s*=(?!=)/',
    ];

    public function test_seul_gerer_variantes_compose_une_variante(): void
    {
        $racine = dirname(__DIR__, 2);
        $trouvees = [];

        foreach (['app', 'database/seeders', 'routes'] as $dossier) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$racine}/{$dossier}", RecursiveDirectoryIterator::SKIP_DOTS)) as $fichier) {
                $chemin = str_replace(DIRECTORY_SEPARATOR, '/', substr($fichier->getPathname(), strlen($racine) + 1));

                if (! str_ends_with($chemin, '.php') || $chemin === self::AUTEUR) {
                    continue;
                }

                foreach (file($fichier->getPathname()) as $numero => $ligne) {
                    foreach (self::ECRITURES as $motif) {
                        if (preg_match($motif, $ligne) === 1 && $this->concerneUneVariante($chemin, $ligne)) {
                            $trouvees[] = "{$chemin}:".($numero + 1).' '.trim($ligne);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $trouvees, 'Écriture directe hors de GererVariantes');
    }

    /**
     * « ->nom = » vise aussi les produits, catégories, clients… : seule
     * l'affectation sur une variante compte.
     */
    private function concerneUneVariante(string $chemin, string $ligne): bool
    {
        if (preg_match('/->(signature|nom)\s*=(?!=)/', $ligne) !== 1) {
            return true;
        }

        return str_contains($ligne, 'signature') || preg_match('/\$variante\w*->nom\s*=/', $ligne) === 1;
    }
}
