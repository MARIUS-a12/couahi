<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F — un membre peut cumuler plusieurs rôles (opérateur ET caissier) :
 * ses permissions s'additionnent. role_id quitte etablissement_utilisateurs
 * pour une table à part, plutôt que de rester à côté « en plus » : deux
 * endroits où lire le rôle finiraient par se contredire.
 *
 * Chaque membre garde exactement son rôle. La copie est vérifiée avant de
 * retirer l'ancienne colonne : si un seul membre n'a pas retrouvé son rôle,
 * la migration échoue et rien n'est retiré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etablissement_utilisateur_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_utilisateur_id')->constrained('etablissement_utilisateurs')->cascadeOnDelete();
            // restrictOnDelete, comme l'ancienne colonne : un rôle en
            // cours d'utilisation ne se supprime pas.
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['etablissement_utilisateur_id', 'role_id']);
        });

        $membres = DB::table('etablissement_utilisateurs')->count();

        DB::statement('INSERT INTO etablissement_utilisateur_roles (etablissement_utilisateur_id, role_id, created_at, updated_at)
            SELECT id, role_id, created_at, updated_at FROM etablissement_utilisateurs');

        $copies = DB::table('etablissement_utilisateur_roles as eur')
            ->join('etablissement_utilisateurs as eu', function ($jointure) {
                $jointure->on('eu.id', '=', 'eur.etablissement_utilisateur_id')->on('eu.role_id', '=', 'eur.role_id');
            })
            ->count();

        if ($copies !== $membres) {
            throw new RuntimeException("Rôles cumulables : {$membres} membre(s), mais {$copies} rôle(s) retrouvé(s) après la copie. Rien n'est retiré.");
        }

        Schema::table('etablissement_utilisateurs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('etablissement_utilisateurs', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
        });

        // Un seul rôle possible à nouveau : le plus ancien (le plus petit id,
        // l'administrateur avant les autres dans le seeder).
        DB::statement('UPDATE etablissement_utilisateurs SET role_id = (
            SELECT MIN(role_id) FROM etablissement_utilisateur_roles
            WHERE etablissement_utilisateur_roles.etablissement_utilisateur_id = etablissement_utilisateurs.id)');

        Schema::dropIfExists('etablissement_utilisateur_roles');
    }
};
