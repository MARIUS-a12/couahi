<?php

namespace App\Http\Requests;

use App\Models\Domaine;
use App\Models\Etablissement;
use App\Support\Couleur\CouleurAccent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEtablissementRequest extends FormRequest
{
    /**
     * Mots réservés à l'infrastructure de la plateforme elle-même : jamais
     * disponibles comme sous-domaine d'établissement, quel que soit le nom
     * choisi par le super-admin. Public : partagée avec
     * EtablissementController::verifierSousDomaine() (vérification en
     * direct), pour ne garder qu'une seule liste à tenir à jour.
     */
    public const SOUS_DOMAINES_RESERVES = ['admin', 'api', 'www', 'mail', 'ftp', 'app'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Etablissement::class);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:boutique,restaurant'],
            'sous_domaine' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(self::SOUS_DOMAINES_RESERVES),
                Rule::unique('etablissements', 'slug'),
                function (string $attribute, mixed $value, callable $fail): void {
                    $hote = $value.config('tenancy.suffixe_domaine');

                    if (Domaine::where('hote', $hote)->exists()) {
                        $fail('Ce sous-domaine est déjà utilisé.');
                    }
                },
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'couleur_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'nom_administrateur' => ['required', 'string', 'max:255'],
            'email_administrateur' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
    }

    public function messages(): array
    {
        return [
            'sous_domaine.regex' => 'Le sous-domaine doit être en kebab-case (lettres minuscules, chiffres, tirets), sans accents ni espaces.',
            'sous_domaine.not_in' => 'Ce mot est réservé et ne peut pas être utilisé comme sous-domaine.',
            'sous_domaine.unique' => 'Ce sous-domaine est déjà utilisé.',
            'email_administrateur.unique' => 'Cet email est déjà utilisé par un autre compte de la plateforme.',
            'couleur_accent.regex' => 'La couleur doit être un code hexadécimal à 6 chiffres (ex. '.strtoupper(CouleurAccent::PAR_DEFAUT).').',
        ];
    }
}
