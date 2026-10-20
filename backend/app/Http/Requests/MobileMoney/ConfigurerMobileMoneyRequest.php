<?php

namespace App\Http\Requests\MobileMoney;

use App\Enums\OperateurMobileMoney;
use App\Rules\NumeroTelephoneIvoirien;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Les trois champs "required" ensemble : aucune configuration partielle
 * possible (un numéro sans nom de titulaire priverait le client de sa seule
 * vérification). Retirer la configuration passe par DELETE, jamais par des
 * champs vidés ici.
 */
class ConfigurerMobileMoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $beneficiaire = $this->input('mm_beneficiaire');

        $this->merge([
            'mm_beneficiaire' => is_string($beneficiaire) ? preg_replace('/\s+/', ' ', trim($beneficiaire)) : $beneficiaire,
        ]);
    }

    public function rules(): array
    {
        return [
            'mm_numero' => ['required', 'string', new NumeroTelephoneIvoirien],
            'mm_operateur' => ['required', Rule::enum(OperateurMobileMoney::class)],
            'mm_beneficiaire' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'mm_numero.required' => 'Le numéro qui reçoit les paiements est obligatoire.',
            'mm_operateur.required' => "Choisissez l'opérateur de ce numéro.",
            'mm_beneficiaire.required' => 'Le nom du titulaire du compte est obligatoire : vos clients le vérifient avant de payer.',
        ];
    }
}
