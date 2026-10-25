<?php

namespace App\Http\Requests\Livraison;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création et modification d'une zone : la commune telle que le client la
 * choisira dans la liste, et des frais en francs CFA entiers (0 : livraison
 * offerte dans cette commune).
 */
class ZoneLivraisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $nom = $this->input('nom');

        $this->merge(['nom' => is_string($nom) ? preg_replace('/\s+/', ' ', trim($nom)) : $nom]);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:100'],
            'frais' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Indiquez la commune.',
            'frais.required' => 'Indiquez les frais de livraison (0 si elle est offerte).',
            'frais.integer' => 'Les frais sont un nombre entier de francs CFA.',
            'frais.min' => 'Les frais ne peuvent pas être négatifs.',
            'frais.max' => 'Les frais ne peuvent pas dépasser 100 000 FCFA.',
        ];
    }
}
