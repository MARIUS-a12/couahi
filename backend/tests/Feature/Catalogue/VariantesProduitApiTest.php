<?php

namespace Tests\Feature\Catalogue;

use App\Enums\Canal;
use App\Enums\SourceCommande;
use App\Models\Etablissement;
use App\Models\EtablissementUtilisateur;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Models\VarianteProduit;
use App\Services\Commandes\CreerCommande;
use App\Services\Commandes\LigneCommandeDemandee;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B2 — l'API de l'écran des variantes, appelée comme l'écran l'appelle.
 * Le scénario principal crée à la main la sandale de la démo : deux
 * options, pointures saisies dans le désordre puis remises dans l'ordre,
 * 12 combinaisons sur 14, une photo par coloris.
 */
class VariantesProduitApiTest extends TestCase
{
    use RefreshDatabase;

    private const HOTE = 'http://wax-adjame.localhost:8000';

    private function connecte(string $email, string $hote = self::HOTE): static
    {
        $this->withHeader('Referer', $hote)
            ->postJson("{$hote}/api/connexion", ['email' => $email, 'mot_de_passe' => 'motdepasse'])
            ->assertStatus(200);

        return $this;
    }

    private function avecRole(string $slugEtablissement, string $role): User
    {
        $utilisateur = User::factory()->create(['password' => 'motdepasse', 'mot_de_passe_defini' => true]);
        EtablissementUtilisateur::create([
            'etablissement_id' => Etablissement::where('slug', $slugEtablissement)->value('id'),
            'utilisateur_id' => $utilisateur->id,
            'role_id' => Role::where('nom', $role)->value('id'),
            'statut' => 'actif',
        ]);

        return $utilisateur;
    }

    private function nouveauProduit(): int
    {
        return $this->postJson(self::HOTE.'/api/produits', ['nom' => 'Sandales tressées', 'prix' => 18000])->assertStatus(201)->json('data.id');
    }

    private function structure(int $produitId): TestResponse
    {
        return $this->getJson(self::HOTE."/api/produits/{$produitId}/variantes")->assertStatus(200);
    }

    private function option(TestResponse $structure, string $nom): array
    {
        return collect($structure->json('data.options'))->firstWhere('nom', $nom);
    }

    private function valeurId(TestResponse $structure, string $option, string $libelle): int
    {
        return collect($this->option($structure, $option)['valeurs'])->firstWhere('libelle', $libelle)['id'];
    }

    public function test_la_sandale_se_cree_a_la_main_de_bout_en_bout(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $id = $this->nouveauProduit();
        foreach (['noir.jpg', 'marron.jpg'] as $photo) {
            $this->post(self::HOTE."/api/produits/{$id}/medias", ['photo' => UploadedFile::fake()->image($photo, 600, 600)])->assertStatus(201);
        }
        [$photoNoir, $photoMarron] = collect($this->getJson(self::HOTE."/api/produits/{$id}")->json('data.medias'))->pluck('id')->all();

        $this->postJson(self::HOTE."/api/produits/{$id}/options", ['nom' => 'Pointure'])->assertStatus(201);
        $pointure = $this->option($this->structure($id), 'Pointure')['id'];
        foreach (['42', '39', '45', '40', '44', '41', '43'] as $libelle) {
            $this->postJson(self::HOTE."/api/produits/{$id}/options/{$pointure}/valeurs", ['libelle' => $libelle])->assertStatus(201);
        }
        $saisies = collect($this->option($this->structure($id), 'Pointure')['valeurs']);
        $this->assertSame(['42', '39', '45', '40', '44', '41', '43'], $saisies->pluck('libelle')->all(), 'dans l\'ordre de saisie, jamais trié par libellé');

        $ordre = $saisies->sortBy(fn ($valeur) => (int) $valeur['libelle'])->pluck('id')->values()->all();
        $this->putJson(self::HOTE."/api/produits/{$id}/options/{$pointure}/valeurs/ordre", ['ordre' => $ordre])->assertStatus(200);

        $this->postJson(self::HOTE."/api/produits/{$id}/options", ['nom' => 'Coloris'])->assertStatus(201);
        $coloris = $this->option($this->structure($id), 'Coloris')['id'];
        foreach (['Noir', 'Marron'] as $libelle) {
            $this->postJson(self::HOTE."/api/produits/{$id}/options/{$coloris}/valeurs", ['libelle' => $libelle])->assertStatus(201);
        }

        $structure = $this->structure($id);
        $combinaisons = [];
        foreach (['Noir' => ['39', '40', '41', '42', '43', '44', '45'], 'Marron' => ['40', '41', '42', '43', '44']] as $couleur => $pointures) {
            foreach ($pointures as $p) {
                $combinaisons[] = [
                    'valeur_ids' => [$this->valeurId($structure, 'Pointure', $p), $this->valeurId($structure, 'Coloris', $couleur)],
                    'quantite_stock' => 3,
                    'media_id' => $couleur === 'Noir' ? $photoNoir : $photoMarron,
                ];
            }
        }
        $this->postJson(self::HOTE."/api/produits/{$id}/variantes", ['combinaisons' => $combinaisons])->assertStatus(201);

        $final = $this->structure($id);
        $this->assertSame(['39', '40', '41', '42', '43', '44', '45'], collect($this->option($final, 'Pointure')['valeurs'])->pluck('libelle')->all());
        $this->assertCount(12, $final->json('data.variantes'));
        $this->assertSame('39 / Noir', $final->json('data.variantes.0.nom'));
        $this->assertSame([$photoNoir, $photoMarron], collect($final->json('data.variantes'))->pluck('media_id')->unique()->values()->all());
        $this->artisan('catalogue:verifier-variantes', ['--etablissement' => 'wax-adjame'])->assertSuccessful();
    }

    public function test_une_combinaison_deja_creee_ou_incomplete_est_refusee_sans_rien_ecrire(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $structure = $this->structure($sandales->id);
        $noir39 = [$this->valeurId($structure, 'Pointure', '39'), $this->valeurId($structure, 'Coloris', 'Noir')];
        $marron39 = [$this->valeurId($structure, 'Pointure', '39'), $this->valeurId($structure, 'Coloris', 'Marron')];

        $this->postJson(self::HOTE."/api/produits/{$sandales->id}/variantes", ['combinaisons' => [['valeur_ids' => $marron39], ['valeur_ids' => [$noir39[0]]]]])->assertStatus(422);
        $this->assertCount(12, $this->structure($sandales->id)->json('data.variantes'), 'toutes ou aucune');

        $this->postJson(self::HOTE."/api/produits/{$sandales->id}/variantes", ['combinaisons' => [['valeur_ids' => $marron39]]])->assertStatus(201);
        $this->assertCount(13, $this->structure($sandales->id)->json('data.variantes'));
    }

    public function test_doublon_de_valeur_a_la_casse_pres_message_lisible(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $coloris = $this->option($this->structure($sandales->id), 'Coloris')['id'];

        $this->postJson(self::HOTE."/api/produits/{$sandales->id}/options/{$coloris}/valeurs", ['libelle' => ' noir '])
            ->assertStatus(422)
            ->assertJsonPath('errors.libelle.0', '« Noir » existe déjà dans cette option.');
    }

    public function test_archiver_une_valeur_utilisee_et_effacer_une_valeur_inutilisee(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $structure = $this->structure($sandales->id);
        $marron = collect($this->option($structure, 'Coloris')['valeurs'])->firstWhere('libelle', 'Marron');
        $this->assertSame([5, 12], [$marron['nombre_variantes'], $marron['stock_total']], 'ce qui est annoncé avant d\'archiver');

        $this->deleteJson(self::HOTE."/api/produits/{$sandales->id}/valeurs/{$marron['id']}")->assertStatus(200)->assertJsonPath('resultat', 'archivee');
        $apres = $this->structure($sandales->id);
        $this->assertTrue(collect($this->option($apres, 'Coloris')['valeurs'])->firstWhere('id', $marron['id'])['archivee']);
        $this->assertSame(5, collect($apres->json('data.variantes'))->where('active', false)->count());
        $this->assertSame(12, VarianteProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->count(), 'rien n\'est effacé');

        $coloris = $this->option($apres, 'Coloris')['id'];
        $fauve = $this->postJson(self::HOTE."/api/produits/{$sandales->id}/options/{$coloris}/valeurs", ['libelle' => 'Fauve'])->json('data.options.1.valeurs.2.id');
        $this->deleteJson(self::HOTE."/api/produits/{$sandales->id}/valeurs/{$fauve}")->assertStatus(200)->assertJsonPath('resultat', 'supprimee');
    }

    public function test_retirer_une_option_a_plusieurs_valeurs_nomme_les_collisions(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $coloris = $this->option($this->structure($sandales->id), 'Coloris')['id'];

        $message = $this->deleteJson(self::HOTE."/api/produits/{$sandales->id}/options/{$coloris}")->assertStatus(422)->json('errors.option.0');

        $this->assertStringContainsString('plusieurs valeurs de « Coloris » (Noir, Marron)', $message);
        $this->assertStringContainsString('40 / Noir et 40 / Marron', $message);
    }

    public function test_retirer_une_variante_qui_a_servi_l_archive_une_autre_l_efface(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $wax = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $vendue = VarianteProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->where('nom', '41 / Noir')->firstOrFail();
        $jamais = VarianteProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->where('nom', '44 / Marron')->firstOrFail();
        app(ContexteEtablissement::class)->definir($wax);
        app(CreerCommande::class)->executer($wax, $wax->clients()->create(['nom' => 'Client']), [new LigneCommandeDemandee($sandales->id, $vendue->id, 1)], Canal::Web, SourceCommande::PanierWeb, 'cle');

        $this->deleteJson(self::HOTE."/api/produits/{$sandales->id}/variantes/{$vendue->id}")->assertJsonPath('resultat', 'archivee');
        $this->deleteJson(self::HOTE."/api/produits/{$sandales->id}/variantes/{$jamais->id}")->assertJsonPath('resultat', 'supprimee');
        $this->assertNotNull(VarianteProduit::pourTousEtablissements()->find($vendue->id));
        $this->assertNull(VarianteProduit::pourTousEtablissements()->find($jamais->id));
    }

    public function test_une_option_dun_autre_produit_est_introuvable(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $wax = Produit::pourTousEtablissements()->where('slug', 'wax-imprime-bouche-de-sika')->firstOrFail();
        $longueur = $this->option($this->structure($wax->id), 'Longueur')['id'];

        $this->patchJson(self::HOTE."/api/produits/{$sandales->id}/options/{$longueur}", ['nom' => 'Piège'])->assertStatus(404);
    }

    public function test_les_droits(): void
    {
        $this->seed();
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $variante = VarianteProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->where('nom', '39 / Noir')->firstOrFail();
        $stock = $this->avecRole('wax-adjame', 'gestionnaire_stock');
        $this->connecte($stock->email);

        $this->patchJson(self::HOTE."/api/produits/{$sandales->id}/variantes/{$variante->id}", ['quantite_stock' => 9])->assertStatus(200);
        $this->patchJson(self::HOTE."/api/produits/{$sandales->id}/variantes/{$variante->id}", ['prix' => 1])->assertStatus(403);
        $this->postJson(self::HOTE."/api/produits/{$sandales->id}/options", ['nom' => 'Largeur'])->assertStatus(403);
        $this->assertSame([9, null], [$variante->fresh()->quantite_stock, $variante->fresh()->prix]);
    }

    public function test_le_stock_ne_descend_pas_sous_le_reserve(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $wax = Etablissement::where('slug', 'wax-adjame')->firstOrFail();
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $variante = VarianteProduit::pourTousEtablissements()->where('produit_id', $sandales->id)->where('nom', '43 / Noir')->firstOrFail();
        app(ContexteEtablissement::class)->definir($wax);
        app(CreerCommande::class)->executer($wax, $wax->clients()->create(['nom' => 'Client']), [new LigneCommandeDemandee($sandales->id, $variante->id, 2)], Canal::Web, SourceCommande::PanierWeb, 'cle');

        $this->patchJson(self::HOTE."/api/produits/{$sandales->id}/variantes/{$variante->id}", ['quantite_stock' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.quantite_stock.0', '2 article(s) de « 43 / Noir » sont réservés par des commandes en cours : le stock ne peut pas descendre en dessous.');
    }

    public function test_les_suggestions_proposent_les_valeurs_de_la_boutique_dans_leur_ordre(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');

        $suggestions = collect($this->getJson(self::HOTE.'/api/catalogue/suggestions-variantes')->assertStatus(200)->json('data'))->keyBy('nom');

        $this->assertSame(['39', '40', '41', '42', '43', '44', '45'], $suggestions['Pointure']['valeurs']);
        $this->assertSame(['2 yards', '6 yards', '12 yards'], $suggestions['Longueur']['valeurs']);
        $this->assertArrayNotHasKey('Conditionnement', $suggestions->all(), 'jamais les valeurs d\'une autre boutique');
    }

    public function test_categories_a_deux_niveaux_par_l_api(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $creer = fn (string $nom, ?int $parent) => $this->postJson(self::HOTE.'/api/categories', ['nom' => $nom, 'slug' => str($nom)->slug()->value(), 'parent_id' => $parent]);

        $mode = $creer('Mode', null)->assertStatus(201)->json('data.id');
        $robes = $creer('Robes', $mode)->assertStatus(201)->assertJsonPath('data.parent_id', $mode)->json('data.id');

        $creer('Robes longues', $robes)->assertStatus(422)->assertJsonPath('errors.parent_id.0', '« Robes » est déjà une sous-catégorie : deux niveaux au plus.');
        $autre = $creer('Accessoires', null)->json('data.id');
        $this->putJson(self::HOTE."/api/categories/{$mode}", ['parent_id' => $autre])
            ->assertStatus(422)
            ->assertJsonPath('errors.parent_id.0', 'Cette catégorie a des sous-catégories : elle ne peut pas devenir elle-même une sous-catégorie.');
    }

    public function test_la_photo_dun_groupe_en_une_requete_et_seulement_pour_ce_groupe(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $sandales = Produit::pourTousEtablissements()->where('slug', 'sandales-en-cuir-tresse')->firstOrFail();
        $structure = $this->structure($sandales->id);
        $marron = collect($structure->json('data.variantes'))->filter(fn ($v) => str_ends_with($v['nom'], '/ Marron'));
        $photoNoir = collect($structure->json('data.variantes'))->firstWhere('nom', '39 / Noir')['media_id'];

        // « Aucune » pour les marron : si l'écriture débordait sur tout le
        // produit, les noires perdraient la leur — c'est ce qu'on vérifie.
        $this->putJson(self::HOTE."/api/produits/{$sandales->id}/variantes/photo", ['variante_ids' => $marron->pluck('id')->all(), 'media_id' => null])->assertStatus(200);

        $apres = collect($this->structure($sandales->id)->json('data.variantes'));
        $this->assertSame([null], $apres->whereIn('id', $marron->pluck('id'))->pluck('media_id')->unique()->values()->all());
        $this->assertSame([$photoNoir], $apres->whereNotIn('id', $marron->pluck('id'))->pluck('media_id')->unique()->values()->all(), 'les 7 noires gardent la leur');

        $ailleurs = VarianteProduit::pourTousEtablissements()->where('produit_id', '!=', $sandales->id)->value('id');
        $this->putJson(self::HOTE."/api/produits/{$sandales->id}/variantes/photo", ['variante_ids' => [$ailleurs], 'media_id' => null])->assertStatus(404);
        $this->assertNotNull(VarianteProduit::pourTousEtablissements()->find($ailleurs), 'intacte');
    }

    /**
     * La plage « de 39 à 45 » de l'écran arrive ici en une seule requête :
     * dans l'ordre, sans doublon, une valeur déjà là sautée — et une valeur
     * archivée refuse TOUT, sans rien écrire.
     */
    public function test_une_virgule_separe_des_valeurs(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $id = $this->nouveauProduit();
        $this->postJson(self::HOTE."/api/produits/{$id}/options", ['nom' => 'Couleur'])->assertStatus(201);
        $couleur = $this->option($this->structure($id), 'Couleur')['id'];
        $valeurs = fn () => collect($this->option($this->structure($id), 'Couleur')['valeurs'])->pluck('libelle')->all();

        $this->postJson(self::HOTE."/api/produits/{$id}/options/{$couleur}/valeurs", ['libelle' => 'blanc, noir'])->assertStatus(201);
        $this->assertSame(['blanc', 'noir'], $valeurs(), 'deux valeurs, jamais une seule « blanc, noir »');

        $this->postJson(self::HOTE."/api/produits/{$id}/options/{$couleur}/valeurs", ['libelle' => ' , '])->assertStatus(422)->assertJsonValidationErrors('libelle');
        $this->postJson(self::HOTE."/api/produits/{$id}/options/{$couleur}/valeurs", ['libelle' => 'rouge, '.str_repeat('x', 61)])->assertStatus(422);
        $this->assertSame(['blanc', 'noir'], $valeurs(), 'rien n\'est écrit');

        $this->postJson(self::HOTE."/api/produits/{$id}/options/{$couleur}/valeurs", ['libelle' => 'Gris chiné'])->assertStatus(201);
        $this->assertSame(['blanc', 'noir', 'Gris chiné'], $valeurs());
    }

    public function test_plusieurs_valeurs_en_une_requete_tout_ou_rien(): void
    {
        $this->seed();
        $this->connecte('fatou@wax-adjame.test');
        $id = $this->nouveauProduit();
        $this->postJson(self::HOTE."/api/produits/{$id}/options", ['nom' => 'Pointure'])->assertStatus(201);
        $pointure = $this->option($this->structure($id), 'Pointure')['id'];
        $this->postJson(self::HOTE."/api/produits/{$id}/options/{$pointure}/valeurs", ['libelle' => '42'])->assertStatus(201);

        $lot = fn (array $libelles) => $this->postJson(self::HOTE."/api/produits/{$id}/options/{$pointure}/valeurs/lot", ['libelles' => $libelles]);

        $lot(['39', '40', '41', '42', '43', '44', '45', '43'])->assertStatus(201);
        $this->assertSame(['42', '39', '40', '41', '43', '44', '45'], collect($this->option($this->structure($id), 'Pointure')['valeurs'])->pluck('libelle')->all());

        $quarante = $this->valeurId($this->structure($id), 'Pointure', '40');
        $this->postJson(self::HOTE."/api/produits/{$id}/variantes", ['combinaisons' => [['valeur_ids' => [$quarante]]]])->assertStatus(201);
        $this->deleteJson(self::HOTE."/api/produits/{$id}/valeurs/{$quarante}")->assertJsonPath('resultat', 'archivee');

        $lot(['46', '40', '47'])->assertStatus(422)->assertJsonPath('errors.libelle.0', '« 40 » existe déjà, archivée : restaurez-la plutôt que de la recréer.');
        $this->assertNotContains('46', collect($this->option($this->structure($id), 'Pointure')['valeurs'])->pluck('libelle')->all(), 'rien n\'est écrit');

        $lot(array_map('strval', range(100, 150)))->assertStatus(422)->assertJsonPath('errors.libelles.0', 'Pas plus de 50 valeurs à la fois.');
    }
}
