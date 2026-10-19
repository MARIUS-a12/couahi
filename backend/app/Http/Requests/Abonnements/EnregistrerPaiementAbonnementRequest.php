<?php

namespace App\Http\Requests\Abonnements;

use App\Enums\MoyenPaiementAbonnement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "en_ligne" est refusé ici : ce moyen est réservé au paiement confirmé
 * par CinetPay lui-même, jamais à une saisie manuelle qui prétendrait
 * qu'un paiement en ligne a eu lieu. "integer" refuse tout montant à
 * virgule (15000.5) : le franc CFA n'a pas de centimes.
 */
class EnregistrerPaiementAbonnementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant' => ['required', 'integer', 'min:1', 'max:100000000'],
            'periode_debut' => ['required', 'date_format:Y-m-d'],
            'periode_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:periode_debut'],
            'moyen' => ['required', Rule::enum(MoyenPaiementAbonnement::class)->except([MoyenPaiementAbonnement::EnLigne])],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
