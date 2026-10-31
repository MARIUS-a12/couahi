<?php

namespace App\Http\Requests\Equipe;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change le nom et/ou les rôles — jamais l'email (voir ModifierMembreEquipe).
 * Les garde-fous (pas ses propres rôles, pas le dernier administrateur actif)
 * sont vérifiés par le service, pas ici : ils dépendent de la cible en
 * base, pas seulement de la forme du payload.
 */
class UpdateMembreEquipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('membre'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('nom')) {
            $this->merge(['nom' => trim((string) $this->input('nom', ''))]);
        }
    }

    public function rules(): array
    {
        return [
            'nom' => ['sometimes', 'string', 'max:255'],
            // Lot F : des rôles cumulables, au moins un.
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => [
                'integer',
                'distinct',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! Role::whereKey($value)->where('nom', '!=', 'super_admin')->exists()) {
                        $fail('Le rôle sélectionné est invalide.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role_ids.min' => 'Choisissez au moins un rôle.',
        ];
    }
}
