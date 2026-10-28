<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D — une référence WhatsApp devient une demande que le commerçant
 * traite : statut (nouvelle, traitee, ignoree) et date du dernier
 * changement. Le lien vers la commande existe déjà depuis le lot C
 * (commandes.reference_whatsapp_id) : il n'est pas dupliqué ici.
 *
 * - Index (etablissement_id, statut, created_at) : exactement la requête
 *   de la liste (« les nouvelles de ma boutique, plus récentes d'abord »).
 * - Index unique partiel sur commandes.reference_whatsapp_id : une demande
 *   ne devient qu'une seule commande, même par deux requêtes simultanées.
 * - Une référence déjà reliée à une commande est « traitee ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('references_whatsapp', function (Blueprint $table) {
            $table->string('statut', 20)->default('nouvelle');
            $table->timestamp('statut_change_le')->nullable();

            $table->index(['etablissement_id', 'statut', 'created_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE references_whatsapp ADD CONSTRAINT references_whatsapp_statut_valide CHECK (statut IN ('nouvelle', 'traitee', 'ignoree'))");
        }

        DB::statement('CREATE UNIQUE INDEX commandes_reference_whatsapp_unique ON commandes (reference_whatsapp_id) WHERE reference_whatsapp_id IS NOT NULL');

        DB::statement("UPDATE references_whatsapp SET statut = 'traitee', statut_change_le = updated_at WHERE id IN (SELECT reference_whatsapp_id FROM commandes WHERE reference_whatsapp_id IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX commandes_reference_whatsapp_unique');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE references_whatsapp DROP CONSTRAINT references_whatsapp_statut_valide');
        }

        Schema::table('references_whatsapp', function (Blueprint $table) {
            $table->dropIndex(['etablissement_id', 'statut', 'created_at']);
            $table->dropColumn(['statut', 'statut_change_le']);
        });
    }
};
