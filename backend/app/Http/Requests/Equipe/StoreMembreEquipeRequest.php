<?php

namespace App\Http\Requests\Equipe;

use App\Models\EtablissementUtilisateur;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "email unique AU SEIN DE l'établissement" — jamais globalement, voir la
 * docblock de CreerMembreEquipe : le même email peut déjà exister pour un
 * compte d'un autre établissement, ce n'est pas un conflit. Vérifié ici via
 * users.etablissement_id (message clair avant soumission) ET garanti en
 * base par UNIQUE(etablissement_id, email) (voir la migration
 * "ajouter_etablissement_id_a_users_table") — cette validation n'est donc
 * qu'un confort, jamais la seule barrière.
 */
class StoreMembreEquipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', EtablissementUtilisateur::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nom' => trim((string) $this->input('nom', '')),
            'email' => trim((string) $this->input('email', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                function (string $attribute, mixed $value, callable $fail): void {
                    $etablissementId = app(ContexteEtablissement::class)->id();

                    $dejaMembre = User::where('etablissement_id', $etablissementId)
                        ->where('email', $value)
                        ->exists();

                    if ($dejaMembre) {
                        $fail('Cet email est déjà utilisé par un membre de cet établissement.');
                    }
                },
            ],
            // Lot F : des rôles cumulables, au moins un.
            'role_ids' => ['required', 'array', 'min:1'],
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
            'role_ids.required' => 'Choisissez au moins un rôle.',
            'role_ids.min' => 'Choisissez au moins un rôle.',
        ];
    }
}
