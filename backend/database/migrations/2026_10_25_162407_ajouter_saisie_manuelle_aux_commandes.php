<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot C — la saisie manuelle des commandes au back-office.
 *
 * - client_id devient facultatif : une vente au comptoir n'a pas de client,
 *   et un faux « Client comptoir » remplirait la table de doublons.
 * - cree_par : qui a saisi la commande (null pour la vitrine).
 * - reference_whatsapp_id : la référence collée, pour reconnaître une
 *   commande déjà créée depuis le même message.
 * - canal : comptoir et téléphone rejoignent web et whatsapp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
            $table->foreignId('cree_par')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->foreignId('reference_whatsapp_id')->nullable()->after('cree_par')->constrained('references_whatsapp')->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE commandes DROP CONSTRAINT commandes_canal_valide');
            DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_canal_valide CHECK (canal IN ('web', 'whatsapp', 'comptoir', 'telephone'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE commandes DROP CONSTRAINT commandes_canal_valide');
            DB::statement("ALTER TABLE commandes ADD CONSTRAINT commandes_canal_valide CHECK (canal IN ('web', 'whatsapp'))");
        }

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_whatsapp_id');
            $table->dropConstrainedForeignId('cree_par');
            $table->foreignId('client_id')->nullable(false)->change();
        });
    }
};
