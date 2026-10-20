<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "integer" : le franc CFA n'a pas de centimes (voir AGENTS.md). Aucune
 * règle n'oblige le montant à égaler le total — un écart est enregistré,
 * jamais refusé (voir ConfirmerPaiementCommande).
 */
class ConfirmerPaiementCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reference = $this->input('reference');

        $this->merge([
            'reference' => is_string($reference) && trim($reference) !== '' ? trim($reference) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'montant_recu' => ['required', 'integer', 'min:1', 'max:100000000'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'montant_recu.required' => 'Indiquez le montant réellement reçu.',
            'montant_recu.integer' => 'Le montant doit être un nombre entier de francs CFA.',
        ];
    }
}
