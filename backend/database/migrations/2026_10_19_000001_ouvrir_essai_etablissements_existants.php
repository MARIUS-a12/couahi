<?php

use App\Models\Abonnement;
use App\Models\Etablissement;
use Illuminate\Database\Migrations\Migration;

/**
 * Les établissements créés avant l'Étape 12 n'ont pas d'abonnement : ils
 * reçoivent le même essai qu'un établissement neuf, compté à partir
 * d'aujourd'hui — jamais une échéance déjà dépassée qui couperait leur
 * vitrine dès la première exécution de la tâche quotidienne. Passe par le
 * modèle (et non une insertion brute) pour respecter l'invariant
 * d'isolation du projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Etablissement::query()
            ->whereDoesntHave('abonnement', fn ($requete) => $requete->pourTousEtablissements())
            ->each(fn (Etablissement $etablissement) => Abonnement::ouvrirEssaiPour($etablissement));
    }

    public function down(): void
    {
        // Rien à défaire : la table elle-même disparaît avec la migration précédente.
    }
};
