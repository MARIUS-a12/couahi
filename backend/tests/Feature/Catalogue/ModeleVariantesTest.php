<?php

namespace Tests\Feature\Catalogue;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Enums\StatutReservation;
use App\Enums\StatutVariante;
use App\Exceptions\ArticleIndisponibleException;
use App\Models\Etablissement;
use App\Models\LigneCommande;
use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ReservationStock;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Services\Stock\ConsommerReservation;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B1 — le schéma des variantes : options, valeurs à ordre explicite,
 * combinaisons sans produit cartésien, signature sur identifiants. Les
 * garanties propres à PostgreSQL (CHECK) sont dans
 * Tests\Feature\Postgres\ContraintesCombinaisonsTest.
 */
class ModeleVariantesTest extends TestCase
{
    use RefreshDatabase;

    private Etablissement $etablissement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->etablissement = Etablissement::factory()->boutique()->create();
        app(ContexteEtablissement::class)->definir($this->etablissement);
    }

    private function option(Produit $produit, string $nom, int $ordre = 1): OptionProduit
    {
        return OptionProduit::create(['produit_id' => $produit->id, 'nom' => $nom, 'ordre' => $ordre]);
    }

    private function valeur(OptionProduit $option, string $libelle, int $ordre): ValeurOption
    {
        return ValeurOption::create(['option_id' => $option->id, 'libelle' => $libelle, 'ordre' => $ordre]);
    }

    private function produit(): Produit
    {
        return Produit::factory()->for($this->etablissement)->publie()->create(['quantite_stock' => 0]);
    }

    private function lePointureEtLaTaille(): array
    {
        $produit = $this->produit();
        $pointure = $this->option($produit, 'Pointure');

        foreach (['42' => 4, '39' => 1, '45' => 7, '40' => 2, '44' => 6, '41' => 3, '43' => 5] as $libelle => $ordre) {
            $this->valeur($pointure, $libelle, $ordre);
        }

        $taille = $this->option($this->produit(), 'Taille');
        foreach (['XL' => 4, 'S' => 1, 'L' => 3, 'M' => 2] as $libelle => $ordre) {
            $this->valeur($taille, $libelle, $ordre);
        }

        return [$pointure, $taille];
    }

    public function test_les_valeurs_creees_dans_le_desordre_ressortent_dans_lordre_du_commercant(): void
    {
        [$pointure, $taille] = $this->lePointureEtLaTaille();

        $this->assertSame(['39', '40', '41', '42', '43', '44', '45'], $pointure->valeurs->pluck('libelle')->all());
        $this->assertSame(['S', 'M', 'L', 'XL'], $taille->valeurs->pluck('libelle')->all());
    }

    public function test_les_options_ressortent_dans_lordre_du_commercant(): void
    {
        $produit = $this->produit();
        $this->option($produit, 'Coloris', 2);
        $this->option($produit, 'Pointure', 1);

        $this->assertSame(['Pointure', 'Coloris'], $produit->options->pluck('nom')->all());
    }

    /**
     * Le cas du commerçant qui renomme « M » en « Moyen » : rien d'autre
     * ne bouge. La signature recalculée depuis les valeurs actuelles reste
     * celle enregistrée — elle ne dépend que des identifiants.
     */
    public function test_renommer_une_valeur_ne_touche_ni_signature_ni_variante_ni_commande(): void
    {
        $produit = $this->produit();
        $taille = $this->option($produit, 'Taille', 1);
        $couleur = $this->option($produit, 'Couleur', 2);
        $m = $this->valeur($taille, 'M', 1);
        $l = $this->valeur($taille, 'L', 2);
        $bleu = $this->valeur($couleur, 'Bleu', 1);

        $mBleu = VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m, $bleu)->create(['nom' => 'M / Bleu', 'quantite_stock' => 12]);
        $lBleu = VarianteProduit::factory()->pourProduit($produit)->avecValeurs($l, $bleu)->create(['nom' => 'L / Bleu', 'quantite_stock' => 5]);

        $creer = app(CreerCommande::class);
        $client = $this->etablissement->clients()->create(['nom' => 'Client Test']);
        $confirmee = $creer->executer($this->etablissement, $client, [new LigneCommandeDemandee($produit->id, $mBleu->id, 2)], Canal::Web, SourceCommande::PanierWeb, 'cle-confirmee');
        app(ConsommerReservation::class)->executer($confirmee);
        $enAttente = $creer->executer($this->etablissement, $client, [new LigneCommandeDemandee($produit->id, $mBleu->id, 1)], Canal::Web, SourceCommande::PanierWeb, 'cle-attente');

        $avant = $this->etat();

        $m->update(['libelle' => 'Moyen']);

        $this->assertSame($avant, $this->etat());
        $this->assertSame('Moyen', $m->fresh()->libelle);
        foreach ([$mBleu, $lBleu] as $variante) {
            $this->assertSame(
                $variante->fresh()->signature,
                VarianteProduit::signaturePour($variante->fresh()->valeurs->pluck('id')),
                'la signature recalculée depuis les valeurs actuelles est inchangée',
            );
        }
        $this->assertSame(StatutReservation::Active, $enAttente->reservations()->first()->statut);
    }

    /**
     * @return array<string, mixed>
     */
    private function etat(): array
    {
        return [
            'variantes' => VarianteProduit::orderBy('id')->get(['id', 'signature', 'nom', 'quantite_stock', 'quantite_reservee', 'prix'])->toArray(),
            'lignes' => LigneCommande::orderBy('id')->get(['id', 'variante_id', 'nom_produit_capture', 'prix_unitaire', 'quantite'])->toArray(),
            'reservations' => ReservationStock::orderBy('id')->get(['id', 'variante_id', 'quantite', 'statut'])->toArray(),
        ];
    }

    public function test_la_signature_est_faite_des_identifiants_tries(): void
    {
        $this->assertSame('3-12-47', VarianteProduit::signaturePour([47, 3, 12]));
    }

    public function test_deux_variantes_actives_ne_peuvent_pas_etre_la_meme_combinaison(): void
    {
        $produit = $this->produit();
        $m = $this->valeur($this->option($produit, 'Taille'), 'M', 1);
        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M']);

        $this->expectException(QueryException::class);
        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M bis']);
    }

    /**
     * L'unicité ne porte que sur les variantes actives : une variante
     * archivée ne bloque pas à vie la recréation de sa combinaison.
     */
    public function test_une_variante_inactive_ne_bloque_pas_sa_combinaison(): void
    {
        $produit = $this->produit();
        $m = $this->valeur($this->option($produit, 'Taille'), 'M', 1);
        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M', 'statut' => StatutVariante::Inactif]);

        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M']);

        $this->assertSame(2, VarianteProduit::where('signature', (string) $m->id)->count());
    }

    public function test_une_variante_ne_porte_quune_valeur_par_option(): void
    {
        $produit = $this->produit();
        $taille = $this->option($produit, 'Taille');
        $m = $this->valeur($taille, 'M', 1);
        $l = $this->valeur($taille, 'L', 2);
        $variante = VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M']);

        $this->expectException(QueryException::class);
        $variante->valeurs()->attach($l->id, ['option_id' => $taille->id, 'produit_id' => $produit->id]);
    }

    public function test_une_valeur_doit_appartenir_a_loption_et_loption_au_produit_de_la_variante(): void
    {
        $produit = $this->produit();
        $autreProduit = $this->produit();
        $taille = $this->option($produit, 'Taille');
        $tailleAilleurs = $this->option($autreProduit, 'Taille');
        $m = $this->valeur($taille, 'M', 1);
        $mAilleurs = $this->valeur($tailleAilleurs, 'M', 1);
        $variante = VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M']);
        $couleur = $this->option($produit, 'Couleur', 2);

        $refusees = 0;
        foreach ([
            fn () => $variante->valeurs()->attach($mAilleurs->id, ['option_id' => $couleur->id, 'produit_id' => $produit->id]),
            fn () => $variante->valeurs()->attach($mAilleurs->id, ['option_id' => $tailleAilleurs->id, 'produit_id' => $produit->id]),
            fn () => $variante->valeurs()->attach($mAilleurs->id, ['option_id' => $tailleAilleurs->id, 'produit_id' => $autreProduit->id]),
        ] as $tentative) {
            try {
                DB::transaction($tentative);
            } catch (QueryException) {
                $refusees++;
            }
        }

        $this->assertSame(3, $refusees);
        $this->assertSame([$m->id], $variante->valeurs()->pluck('valeurs_option.id')->all());
    }

    public function test_une_valeur_portee_par_une_variante_ne_peut_pas_etre_effacee(): void
    {
        $produit = $this->produit();
        $m = $this->valeur($this->option($produit, 'Taille'), 'M', 1);
        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($m)->create(['nom' => 'M']);

        $this->expectException(QueryException::class);
        $m->delete();
    }

    public function test_noms_doption_et_libelles_sont_uniques_a_la_casse_pres(): void
    {
        $produit = $this->produit();
        $taille = $this->option($produit, 'Taille');
        $this->valeur($taille, 'M', 1);

        $refusees = 0;
        foreach ([fn () => $this->option($produit, 'taille', 2), fn () => $this->valeur($taille, 'm', 2)] as $tentative) {
            try {
                DB::transaction($tentative);
            } catch (QueryException) {
                $refusees++;
            }
        }

        $this->assertSame(2, $refusees);
        $this->valeur($this->option($this->produit(), 'Taille'), 'm', 1);
    }

    public function test_limage_dune_variante_est_une_photo_de_son_produit(): void
    {
        $produit = $this->produit();
        $autreProduit = $this->produit();
        $photo = $this->etablissement->medias()->create(['disque' => 'public', 'chemin' => 'a.webp', 'url' => '/a.webp', 'type_mime' => 'image/webp', 'taille' => 1]);
        $photoAilleurs = $this->etablissement->medias()->create(['disque' => 'public', 'chemin' => 'b.webp', 'url' => '/b.webp', 'type_mime' => 'image/webp', 'taille' => 1]);
        $produit->medias()->attach($photo->id, ['ordre' => 0, 'est_principal' => true]);
        $autreProduit->medias()->attach($photoAilleurs->id, ['ordre' => 0, 'est_principal' => true]);
        $variante = VarianteProduit::factory()->pourProduit($produit)->create(['nom' => 'Noir']);

        $variante->update(['media_id' => $photo->id]);
        $this->assertSame($photo->id, $variante->fresh()->media->id);

        $this->expectException(QueryException::class);
        $variante->update(['media_id' => $photoAilleurs->id]);
    }

    /**
     * Une variante inactive (archivée avec sa valeur) reste en base pour
     * l'historique, mais ne se vend plus : ni désignée explicitement par
     * une requête forgée, ni déduite, ni comptée dans la disponibilité.
     */
    public function test_une_variante_inactive_ne_se_vend_jamais(): void
    {
        $produit = $this->produit();
        $taille = $this->option($produit, 'Taille');
        $inactive = VarianteProduit::factory()->pourProduit($produit)->avecValeurs($this->valeur($taille, 'M', 1))
            ->create(['nom' => 'M', 'quantite_stock' => 12, 'statut' => StatutVariante::Inactif]);
        $client = $this->etablissement->clients()->create(['nom' => 'Client Test']);

        $this->assertFalse($produit->fresh()->estDisponibleEnQuantite(), '12 en stock, mais sur une variante inactive');

        foreach ([$inactive->id, null] as $varianteDemandee) {
            try {
                app(CreerCommande::class)->executer($this->etablissement, $client, [new LigneCommandeDemandee($produit->id, $varianteDemandee, 1)], Canal::Web, SourceCommande::PanierWeb, 'cle-'.($varianteDemandee ?? 'deduite'));
                $this->fail('Une variante inactive ne doit jamais être vendue.');
            } catch (ArticleIndisponibleException) {
            }
        }

        $this->assertSame(0, $inactive->fresh()->quantite_reservee);
        $this->assertSame(0, LigneCommande::count());
    }

    public function test_seule_la_variante_active_est_deduite_quand_le_choix_nest_pas_precise(): void
    {
        $produit = $this->produit();
        $taille = $this->option($produit, 'Taille');
        VarianteProduit::factory()->pourProduit($produit)->avecValeurs($this->valeur($taille, 'M', 1))->create(['nom' => 'M', 'statut' => StatutVariante::Inactif]);
        $active = VarianteProduit::factory()->pourProduit($produit)->avecValeurs($this->valeur($taille, 'L', 2))->create(['nom' => 'L', 'quantite_stock' => 3]);

        $commande = app(CreerCommande::class)->executer($this->etablissement, $this->etablissement->clients()->create(['nom' => 'Client Test']), [new LigneCommandeDemandee($produit->id, null, 1)], Canal::Web, SourceCommande::PanierWeb, 'cle-deduite');

        $this->assertSame($active->id, $commande->lignes()->first()->variante_id);
    }
}
