<?php

namespace Tests\Unit;

use App\Support\Couleur\CouleurAccent;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * La couleur d'accent par défaut n'existe qu'à un seul endroit du code
 * serveur (CouleurAccent::PAR_DEFAUT). Elle était recopiée dans une
 * Resource, deux messages de validation et le seeder : changer la couleur
 * en aurait laissé des copies périmées. Les tests, eux, peuvent l'écrire
 * en clair (c'est la valeur attendue).
 */
class CouleurAccentDefinieUneFoisTest extends TestCase
{
    public function test_la_valeur_par_defaut_napparait_quune_fois_dans_le_code(): void
    {
        $racine = dirname(__DIR__, 2);
        $valeur = ltrim(CouleurAccent::PAR_DEFAUT, '#');
        $trouvees = [];

        foreach (['app', 'config', 'database', 'resources', 'routes'] as $dossier) {
            $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$racine}/{$dossier}", RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($fichiers as $fichier) {
                if ($fichier->isFile() && stripos((string) file_get_contents($fichier->getPathname()), $valeur) !== false) {
                    $trouvees[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
                }
            }
        }

        $this->assertSame(['app/Support/Couleur/CouleurAccent.php'], $trouvees);
    }
}
