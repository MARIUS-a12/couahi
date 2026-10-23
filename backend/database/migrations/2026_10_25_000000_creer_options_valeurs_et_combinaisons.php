<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le modèle des variantes (voir decisions.md, « Une variante est une
 * combinaison de valeurs, jamais un produit cartésien ») :
 *
 * - options_produit : les dimensions d'un produit (Taille, Couleur...),
 *   nommées librement, avec un ordre d'affichage explicite ;
 * - valeurs_option : les valeurs d'une option (« 39 », « M », « 100 ml »),
 *   libellé libre, ordre explicite — jamais trié par libellé ;
 * - variante_valeur : quelle valeur de chaque option porte une variante.
 *   Une variante n'existe que si le commerçant l'a créée.
 *
 * Ce que la base garantit, sans compter sur le code :
 * - une seule valeur par option et par variante (clé primaire) ;
 * - la valeur appartient bien à l'option, l'option et la variante au même
 *   produit (clés étrangères composées) ;
 * - pas deux options de même nom dans un produit, ni deux valeurs de même
 *   libellé dans une option, à la casse près (index sur lower()) ;
 * - une valeur encore portée par une variante ne peut pas être effacée
 *   (restrict) : elle s'archive (archivee_le), l'historique de vente reste
 *   intact.
 *
 * Ce qu'elle ne garantit pas : qu'une variante porte une valeur pour
 * CHAQUE option de son produit. Ce n'est pas exprimable en contrainte
 * simple ; la migration des données le respecte, et les services du
 * back-office (B2) le feront, sous test.
 *
 * La signature (identifiants des valeurs, triés) et l'image par variante
 * s'ajoutent ici à variantes_produit, nullable le temps que la migration
 * suivante remplisse la signature des variantes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options_produit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->string('nom');
            $table->integer('ordre')->default(0);
            $table->timestamps();

            $table->index(['etablissement_id', 'produit_id']);
            // Cible de la clé composée (option_id, produit_id) de variante_valeur.
            $table->unique(['id', 'produit_id']);
        });
        DB::statement('CREATE UNIQUE INDEX options_produit_nom_unique ON options_produit (produit_id, lower(nom))');

        Schema::create('valeurs_option', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('options_produit')->cascadeOnDelete();
            $table->string('libelle');
            $table->integer('ordre')->default(0);
            $table->timestamp('archivee_le')->nullable();
            $table->timestamps();

            $table->index(['etablissement_id', 'option_id']);
            // Cible de la clé composée (valeur_id, option_id) de variante_valeur.
            $table->unique(['id', 'option_id']);
        });
        DB::statement('CREATE UNIQUE INDEX valeurs_option_libelle_unique ON valeurs_option (option_id, lower(libelle))');

        Schema::table('variantes_produit', function (Blueprint $table) {
            // Liste triée des identifiants de ses valeurs (« 12-47 ») :
            // jamais les libellés, qu'un renommage changerait. Rendue
            // obligatoire, et unique parmi les variantes actives, par la
            // migration suivante.
            $table->string('signature')->nullable();
            $table->foreignId('media_id')->nullable();

            // Cible de la clé composée (variante_id, produit_id) de variante_valeur.
            $table->unique(['id', 'produit_id']);
            // L'image d'une variante est une des photos de SON produit : la
            // paire doit exister dans media_produit. Détacher la photo du
            // produit exige donc d'abord de la retirer de ses variantes
            // (voir MediaProduitController::destroy).
            $table->foreign(['produit_id', 'media_id'])->references(['produit_id', 'media_id'])->on('media_produit');
        });

        Schema::create('variante_valeur', function (Blueprint $table) {
            $table->foreignId('variante_id');
            $table->foreignId('option_id');
            $table->foreignId('valeur_id');
            $table->foreignId('produit_id');

            $table->primary(['variante_id', 'option_id']);
            $table->index('valeur_id');
            $table->foreign(['variante_id', 'produit_id'])->references(['id', 'produit_id'])->on('variantes_produit')->cascadeOnDelete();
            $table->foreign(['option_id', 'produit_id'])->references(['id', 'produit_id'])->on('options_produit');
            $table->foreign(['valeur_id', 'option_id'])->references(['id', 'option_id'])->on('valeurs_option');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variante_valeur');

        Schema::table('variantes_produit', function (Blueprint $table) {
            $table->dropForeign(['produit_id', 'media_id']);
            $table->dropUnique(['id', 'produit_id']);
            $table->dropColumn(['signature', 'media_id']);
        });

        Schema::dropIfExists('valeurs_option');
        Schema::dropIfExists('options_produit');
    }
};
