<?php

namespace App\Http\Requests\Abonnements;

use Illuminate\Foundation\Http\FormRequest;

class ModifierReglagesAbonnementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant_mensuel' => ['required', 'integer', 'min:1', 'max:100000000'],
            'jours_de_grace' => ['required', 'integer', 'min:0', 'max:90'],
        ];
    }
}
