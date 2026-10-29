<?php

namespace App\Rules;

use App\Models\Etablissement;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * La commune d'une commande, mêmes règles pour la vitrine et pour la
 * saisie manuelle (lot C) :
 * - la boutique ne facture pas la livraison : saisie libre ;
 * - elle facture par commune : une commune de sa liste, ou « Autre
 *   commune » saisie librement (frais fixes si le commerçant en a fixé,
 *   sinon à convenir, voir Etablissement::zoneLivraisonPour). Une commune de la
 *   liste tapée en « Autre » est refusée, sinon « Autre » + « Cocody »
 *   suffirait à ne pas payer les frais de Cocody.
 */
class CommuneDeLivraison implements ValidationRule
{
    public function __construct(
        private readonly Etablissement $etablissement,
        private readonly bool $autreCommune,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $listees = $this->etablissement->zonesLivraisonProposees()->pluck('nom');

        if ($listees->isEmpty()) {
            return;
        }

        $saisie = mb_strtolower(trim((string) $value));
        $estListee = $listees->contains(fn (string $nom) => mb_strtolower($nom) === $saisie);

        if (! $this->autreCommune && ! $listees->contains((string) $value)) {
            $fail('Choisissez une commune dans la liste proposée.');
        }

        if ($this->autreCommune && $estListee) {
            $fail("{$value} est dans la liste : choisissez-la, ses frais de livraison sont connus.");
        }
    }
}
