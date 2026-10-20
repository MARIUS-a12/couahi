<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 13 — paiement par transfert Mobile Money, confirmé à la main par le
 * commerçant (aucune intégration, aucun compte marchand : CinetPay et Wave
 * Business exigent un RCCM que la plupart des commerçants visés n'ont pas).
 *
 * Établissement : les trois champs vont ensemble (voir
 * Etablissement::mobileMoneyEstConfigure) — en clair, ce ne sont pas des
 * secrets : ils sont affichés à chaque client.
 *
 * Commande : le paiement a son propre cycle, distinct du statut de la
 * commande — une commande peut être confirmée sans être payée, et
 * inversement. "paiement_montant_recu" garde le montant RÉELLEMENT reçu,
 * qui peut différer du total (un client qui arrondit à 20 000 au lieu de
 * 20 309 est un cas courant, pas une erreur).
 *
 * Historique : "evenement" distingue une entrée qui n'est pas un
 * changement de statut (la confirmation d'un paiement) ; nul pour toutes
 * les entrées de statut existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etablissements', function (Blueprint $table) {
            $table->string('mm_numero', 20)->nullable();
            $table->string('mm_operateur', 20)->nullable();
            $table->string('mm_beneficiaire', 100)->nullable();
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->string('mode_paiement', 20)->nullable();
            $table->timestamp('paiement_confirme_le')->nullable();
            $table->foreignId('paiement_confirme_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('paiement_reference', 100)->nullable();
            $table->unsignedInteger('paiement_montant_recu')->nullable();
        });

        Schema::table('historique_commande', function (Blueprint $table) {
            $table->string('evenement', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('historique_commande', function (Blueprint $table) {
            $table->dropColumn('evenement');
        });

        Schema::table('commandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paiement_confirme_par');
            $table->dropColumn(['mode_paiement', 'paiement_confirme_le', 'paiement_reference', 'paiement_montant_recu']);
        });

        Schema::table('etablissements', function (Blueprint $table) {
            $table->dropColumn(['mm_numero', 'mm_operateur', 'mm_beneficiaire']);
        });
    }
};
