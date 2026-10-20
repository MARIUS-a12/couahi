<?php

namespace Tests\Feature\Postgres\Commande;

use App\Models\Client;
use App\Models\Etablissement;
use App\Models\Produit;
use Tests\ConcurrenceTestCase;

/**
 * GenererNumeroCommande incrémente UNE ligne de compteur par établissement :
 * les commandes simultanées d'une même boutique se sérialisent sur cette
 * ligne. Ce test le vérifie sous une VRAIE charge concurrente (N processus
 * indépendants, chacun sa connexion) : numéros distincts ET consécutifs
 * (1 à N, sans trou), et AUCUNE commande perdue par interblocage (40P01) ni
 * par délai d'attente de verrou dépassé (55P03, lock_timeout posé à 5 s par
 * processus — une attente normale dure quelques millisecondes).
 */
class NumerotationConcurrenteTest extends ConcurrenceTestCase
{
    private const NB_PROCESSUS = 10;

    private const DELAI_VERROU = '5s';

    /** @return array{0: Etablissement, 1: Client, 2: Produit} */
    private function boutique(): array
    {
        $etablissement = $this->nettoyerAvec(Etablissement::factory()->boutique()->create());
        $client = $etablissement->clients()->create(['nom' => 'Client Concurrence']);
        $produit = Produit::factory()->for($etablissement)->create([
            'quantite_stock' => 1000,
            'quantite_reservee' => 0,
        ]);

        return [$etablissement, $client, $produit];
    }

    /** @param  list<array<string, mixed>>  $resultats */
    private function aucunEchec(array $resultats): void
    {
        foreach ($resultats as $resultat) {
            $this->assertNotContains($resultat['sqlstate'] ?? null, ['40P01', '55P03'], 'Interblocage ou délai de verrou dépassé :'."\n".$this->resume($resultats));
            $this->assertTrue($resultat['ok'], "Une commande a échoué sous concurrence :\n".$this->resume($resultats));
        }
    }

    /** @return list<string> */
    private function attendus(int $nombre): array
    {
        return array_map(fn (int $n) => sprintf('CMD-%06d', $n), range(1, $nombre));
    }

    public function test_n_commandes_creees_en_parallele_recoivent_n_numeros_distincts(): void
    {
        [$etablissement, $client] = $this->boutique();

        // UN produit par processus : sur un produit commun, le verrou de
        // stock sérialiserait déjà les commandes AVANT le compteur, et ce
        // test passerait même avec un compteur non atomique (vérifié). Ici,
        // seul le compteur peut empêcher deux numéros identiques.
        $commandes = [];
        for ($i = 0; $i < self::NB_PROCESSUS; $i++) {
            $produit = Produit::factory()->for($etablissement)->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
            $commandes[] = $this->commandeCreerCommande(
                $etablissement,
                $client,
                [['produit_id' => $produit->id, 'variante_id' => null, 'quantite' => 1]],
                "cle-numerotation-{$i}",
                delaiVerrou: self::DELAI_VERROU,
            );
        }

        $resultats = array_map($this->decoderResultat(...), $this->executerEnParallele($commandes));

        fwrite(STDERR, "\n[numerotation-concurrente] "
            .self::NB_PROCESSUS." processus, numéros obtenus :\n".$this->resume($resultats)."\n");

        $this->aucunEchec($resultats);

        $numeros = array_column($resultats, 'numero');
        sort($numeros);
        $this->assertSame(
            $this->attendus(self::NB_PROCESSUS),
            $numeros,
            "Numéros en double ou manquants sous concurrence :\n".$this->resume($resultats)
        );
    }

    public function test_deux_boutiques_en_parallele_numerotent_chacune_depuis_1(): void
    {
        [$boutiqueA, $clientA] = $this->boutique();
        [$boutiqueB, $clientB] = $this->boutique();
        $parBoutique = intdiv(self::NB_PROCESSUS, 2);

        // Entrelacées A, B, A, B... : les deux compteurs travaillent en même temps.
        $commandes = [];
        for ($i = 0; $i < $parBoutique; $i++) {
            // Un produit par commande, pour la même raison que le test précédent.
            $produitA = Produit::factory()->for($boutiqueA)->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
            $produitB = Produit::factory()->for($boutiqueB)->create(['quantite_stock' => 10, 'quantite_reservee' => 0]);
            $commandes[] = $this->commandeCreerCommande($boutiqueA, $clientA, [['produit_id' => $produitA->id, 'quantite' => 1]], "cle-a-{$i}", delaiVerrou: self::DELAI_VERROU);
            $commandes[] = $this->commandeCreerCommande($boutiqueB, $clientB, [['produit_id' => $produitB->id, 'quantite' => 1]], "cle-b-{$i}", delaiVerrou: self::DELAI_VERROU);
        }

        $resultats = array_map($this->decoderResultat(...), $this->executerEnParallele($commandes));
        $this->aucunEchec($resultats);

        foreach ([0 => 'A', 1 => 'B'] as $rang => $nom) {
            $numeros = array_column(array_values(array_filter($resultats, fn ($r, $i) => $i % 2 === $rang, ARRAY_FILTER_USE_BOTH)), 'numero');
            sort($numeros);
            $this->assertSame($this->attendus($parBoutique), $numeros, "Boutique {$nom} :\n".$this->resume($resultats));
        }
    }
}
