<?php

use App\Models\OptionProduit;
use App\Models\Produit;
use App\Models\ValeurOption;
use App\Models\VarianteProduit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les variantes existantes (à nom libre) deviennent des combinaisons d'une
 * seule option, sans rien perdre : chaque ligne garde son identifiant, son
 * prix, son stock, son réservé, sa disponibilité et son statut — les
 * lignes de commande et les réservations qui pointent vers elle ne
 * bougent pas. Seules s'ajoutent une option par produit et une valeur par
 * variante, dans l'ordre de création (« 1 kg, Sac de 5 kg, Sac de 25 kg »).
 *
 * Passe par les modèles, jamais par DB::table() : la règle d'isolation du
 * projet (AGENTS.md) veut que etablissement_id soit posé par le modèle,
 * ici recopié du produit avec pourEtablissement().
 *
 * Les noms d'option des trois produits de démo sont ceux qu'un commerçant
 * aurait choisis ; tout autre produit (aucun à ce jour) reçoit « Variante »,
 * renommable au back-office.
 *
 * Ensuite : signature obligatoire et unique parmi les variantes actives,
 * fin de l'unicité par nom (deux variantes peuvent porter le même nom si
 * l'une est archivée), et suppression d'attributs_json, jamais lu ni écrit
 * nulle part et vide sur toutes les lignes.
 */
return new class extends Migration
{
    private const NOMS_OPTION = [
        'riz-parfume-long-grain' => 'Conditionnement',
        'wax-imprime-bouche-de-sika' => 'Longueur',
        'poulet-braise' => 'Portion',
    ];

    private const NOM_OPTION_PAR_DEFAUT = 'Variante';

    public function up(): void
    {
        $produits = Produit::pourTousEtablissements()
            ->whereHas('variantes', fn ($requete) => $requete->pourTousEtablissements())
            ->with(['variantes' => fn ($requete) => $requete->pourTousEtablissements()->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($produits as $produit) {
            $option = (new OptionProduit([
                'produit_id' => $produit->id,
                'nom' => self::NOMS_OPTION[$produit->slug] ?? self::NOM_OPTION_PAR_DEFAUT,
                'ordre' => 1,
            ]))->pourEtablissement($produit->etablissement_id);
            $option->save();

            foreach ($produit->variantes->values() as $index => $variante) {
                $valeur = (new ValeurOption([
                    'option_id' => $option->id,
                    'libelle' => $variante->nom,
                    'ordre' => $index + 1,
                ]))->pourEtablissement($produit->etablissement_id);
                $valeur->save();

                $variante->valeurs()->attach($valeur->id, ['option_id' => $option->id, 'produit_id' => $produit->id]);

                // Ni updated_at ni aucune autre colonne : seule la signature
                // s'ajoute à la ligne.
                $variante->timestamps = false;
                $variante->signature = VarianteProduit::signaturePour([$valeur->id]);
                $variante->save();
            }
        }

        Schema::table('variantes_produit', function (Blueprint $table) {
            $table->string('signature')->nullable(false)->change();
            $table->dropUnique(['produit_id', 'nom']);
            $table->dropColumn('attributs_json');
        });

        DB::statement("CREATE UNIQUE INDEX variantes_produit_signature_active_unique ON variantes_produit (produit_id, signature) WHERE statut = 'actif'");
    }

    /**
     * Rend la colonne attributs_json (vide, comme elle l'était) et l'unicité
     * par nom. Les options, valeurs et liens disparaissent avec la
     * migration précédente, dont le down() supprime leurs tables.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX variantes_produit_signature_active_unique');

        Schema::table('variantes_produit', function (Blueprint $table) {
            $table->jsonb('attributs_json')->nullable();
            $table->string('signature')->nullable()->change();
            $table->unique(['produit_id', 'nom']);
        });
    }
};
