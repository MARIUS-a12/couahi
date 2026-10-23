<?php

namespace Tests\Feature\Postgres\Vitrine;

use App\Models\Etablissement;
use App\Models\Produit;
use App\Models\ReferenceWhatsapp;
use Tests\ConcurrenceTestCase;

/**
 * Le verrou de GenerateurLienWhatsapp sous une VRAIE course : plusieurs
 * processus, même visiteur, même article, lancés pour appeler le service au
 * même instant (voir --depart de concurrence:generer-lien-whatsapp) —
 * l'équivalent d'un double appui sur mobile, dont les deux requêtes tombent
 * entre le SELECT et l'INSERT l'une de l'autre. Une seule référence doit
 * naître, et tous doivent recevoir la même.
 *
 * CACHE_STORE=database dans les enfants : c'est le store réel du projet, et
 * le seul des deux (avec "array" des tests) qui partage un verrou entre
 * processus.
 */
class GenerationLienWhatsappConcurrenteTest extends ConcurrenceTestCase
{
    private const PROCESSUS = 6;

    public function test_des_appels_simultanes_du_meme_visiteur_ne_creent_quune_reference(): void
    {
        $etablissement = $this->nettoyerAvec(Etablissement::factory()->boutique()->create());
        $produit = Produit::factory()->for($etablissement)->publie()->create();
        $depart = sprintf('%.6F', microtime(true) + 4);

        $bruts = $this->executerEnParallele(
            array_fill(0, self::PROCESSUS, [
                'concurrence:generer-lien-whatsapp',
                '--produit='.$produit->id,
                '--visiteur='.hash('sha256', 'session:double-appui'),
                '--depart='.$depart,
            ]),
            environnement: ['CACHE_STORE' => 'database'],
        );
        $resultats = array_map($this->decoderResultat(...), $bruts);

        fwrite(STDERR, "\n[lien-whatsapp-concurrent] ".self::PROCESSUS." appels simultanés, sortie de chacun :\n"
            .$this->resume($resultats)."\n");

        $this->assertCount(1, array_unique(array_column($resultats, 'reference')), 'tous reçoivent la même référence');
        $this->assertSame(1, ReferenceWhatsapp::where('produit_id', $produit->id)->count(), 'une seule demande pour le commerçant');
    }
}
