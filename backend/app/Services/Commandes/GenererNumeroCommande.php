<?php

namespace App\Services\Commandes;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un numéro dicté au téléphone ou tapé sur WhatsApp : court, humain,
 * `CMD-000847` — et PROPRE à chaque établissement : la première commande
 * d'une nouvelle boutique est CMD-000001, jamais un numéro qui trahirait
 * l'activité des autres (voir decisions.md).
 *
 * Une seule instruction atomique sur la ligne de compteur de
 * l'établissement (créée à la première commande) : deux commandes
 * simultanées de la même boutique se sérialisent sur cette ligne, la
 * seconde lit la valeur validée par la première — jamais deux fois le même
 * numéro. Le verrou est tenu jusqu'à la fin de la transaction appelante :
 * c'est ce qui rend la numérotation sans trou (une commande annulée par un
 * rollback rend son numéro), et c'est aussi pourquoi ce numéro doit être
 * pris le plus TARD possible dans la transaction, sans rien de lent après
 * (voir CreerCommande). Même code sur PostgreSQL et SQLite (≥ 3.35).
 */
class GenererNumeroCommande
{
    public function executer(int $etablissementId): string
    {
        // Hors transaction, le verrou serait relâché aussitôt l'instruction
        // finie, et un rollback de la commande ne rendrait plus son numéro.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('GenererNumeroCommande doit être appelé dans la transaction qui crée la commande.');
        }

        $ligne = DB::selectOne(
            'insert into compteurs_numero_commande (etablissement_id, dernier) values (?, 1)
             on conflict (etablissement_id) do update set dernier = compteurs_numero_commande.dernier + 1
             returning dernier',
            [$etablissementId],
        );

        return sprintf('CMD-%06d', (int) $ligne->dernier);
    }
}
