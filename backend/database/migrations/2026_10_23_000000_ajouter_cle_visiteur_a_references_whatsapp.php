<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Déduplication de la génération de lien WhatsApp (voir
 * GenerateurLienWhatsapp) : un même visiteur qui reclique pour le même
 * article dans les 10 minutes reçoit la même référence. cle_visiteur est
 * l'EMPREINTE (sha256) de son identifiant de session, ou de son IP à
 * défaut — jamais la valeur brute : un identifiant de session lisible en
 * base permettrait de se faire passer pour ce visiteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('references_whatsapp', function (Blueprint $table) {
            $table->string('cle_visiteur', 64)->nullable();

            $table->index(['etablissement_id', 'cle_visiteur', 'produit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('references_whatsapp', function (Blueprint $table) {
            $table->dropIndex(['etablissement_id', 'cle_visiteur', 'produit_id', 'created_at']);
            $table->dropColumn('cle_visiteur');
        });
    }
};
