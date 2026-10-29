<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\Etablissement;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Un client est retrouvé par son numéro de téléphone AU SEIN d'un
 * établissement (clients.telephone n'est unique que par établissement, voir
 * sa migration) : le même numéro chez deux établissements fait deux clients
 * distincts, jamais un seul partagé entre deux boutiques.
 *
 * Même garde contre la double création concurrente que l'idempotence de
 * CreerCommande (voir sa docblock) : on tente la création, et seule une
 * violation de la contrainte unique nous apprend qu'un autre appel nous a
 * devancés entre notre lecture et notre propre INSERT — on relit alors sa
 * ligne plutôt que de dupliquer.
 */
class TrouverOuCreerClient
{
    public function executer(Etablissement $etablissement, ?string $nom, string $telephone, ?string $email): Client
    {
        $existant = $etablissement->clients()->where('telephone', $telephone)->first();

        if ($existant !== null) {
            return $existant;
        }

        try {
            return $etablissement->clients()->create([
                'nom' => $nom,
                'telephone' => $telephone,
                'email' => $email,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $etablissement->clients()->where('telephone', $telephone)->firstOrFail();
        }
    }
}
