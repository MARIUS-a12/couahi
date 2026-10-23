<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catégories à deux niveaux (« Chaussures > Sandales »), jamais plus.
 *
 * Deux niveaux au plus, garantis par la base et non par le seul code. Une
 * contrainte CHECK ne peut pas lire la ligne parente : on recopie donc
 * dans l'enfant ce qu'il exige de son parent, et une clé étrangère
 * composée le vérifie.
 * - est_racine vaut vrai exactement quand parent_id est null.
 * - parent_est_racine vaut vrai dès qu'il y a un parent (null sinon).
 * - La clé (parent_id, parent_est_racine, etablissement_id) pointe sur
 *   (id, est_racine, etablissement_id) : le parent doit donc être une
 *   racine, du même établissement.
 * - Une catégorie qui a des enfants ne peut plus devenir enfant : passer
 *   son est_racine à faux casserait la clé de ses enfants, et la base le
 *   refuse.
 *
 * Un CHECK dont l'expression vaut NULL passe : d'où IS TRUE plutôt que
 * « = true » sur parent_est_racine, qui laissait un parent sans
 * parent_est_racine — donc sans vérification par la clé composée.
 *
 * Les CHECK qui lient ces colonnes sont propres à PostgreSQL, comme les
 * autres du projet (voir la migration de garde-fous des produits) ; sur
 * SQLite, le modèle Categorie les renseigne seul (voir Categorie::booted)
 * et la clé composée suffit à refuser un parent qui n'est pas une racine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable();
            $table->boolean('est_racine')->default(true);
            $table->boolean('parent_est_racine')->nullable();

            $table->unique(['id', 'est_racine', 'etablissement_id']);
            $table->index(['etablissement_id', 'parent_id']);
            $table->foreign(['parent_id', 'parent_est_racine', 'etablissement_id'])
                ->references(['id', 'est_racine', 'etablissement_id'])
                ->on('categories');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_pas_son_propre_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_est_racine_coherent CHECK (est_racine = (parent_id IS NULL))');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_parent_est_racine_coherent CHECK ((parent_id IS NULL AND parent_est_racine IS NULL) OR (parent_id IS NOT NULL AND parent_est_racine IS TRUE))');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_parent_est_racine_coherent');
            DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_est_racine_coherent');
            DB::statement('ALTER TABLE categories DROP CONSTRAINT categories_pas_son_propre_parent');
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id', 'parent_est_racine', 'etablissement_id']);
            $table->dropIndex(['etablissement_id', 'parent_id']);
            $table->dropUnique(['id', 'est_racine', 'etablissement_id']);
            $table->dropColumn(['parent_id', 'est_racine', 'parent_est_racine']);
        });
    }
};
