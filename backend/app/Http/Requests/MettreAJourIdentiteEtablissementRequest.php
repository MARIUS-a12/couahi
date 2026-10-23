<?php

namespace App\Http\Requests;

use App\Rules\NumeroTelephoneIvoirien;
use App\Rules\UrlReseauSocial;
use App\Support\Couleur\CouleurAccent;
use App\Support\Url\ForceHttps;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Règles PARTAGÉES entre le chemin commerçant (PATCH /api/parametres/etablissement)
 * et le chemin super-admin (PATCH /api/etablissements/{etablissement}/identite)
 * — voir IdentiteEtablissementController, seul endroit où les deux routes
 * divergent (autorisation uniquement). authorize() vaut toujours true ici :
 * la décision d'accès est prise par le contrôleur, qui sait lequel des deux
 * établissements est visé — une FormRequest n'a pas accès à cette distinction
 * avant que la route ait résolu son paramètre.
 *
 * couleur_accent : seul le FORMAT est vérifié ici. Le contraste avec le texte
 * blanc du bouton principal est un contrôle métier qui doit pouvoir répondre
 * avec une suggestion (voir ContrasteCouleur) — pas une simple règle
 * passe/échoue : il vit dans MettreAJourIdentiteEtablissement, pas ici.
 */
class MettreAJourIdentiteEtablissementRequest extends FormRequest
{
    private const JOURS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Les liens sociaux arrivent en https quoi qu'il arrive (voir ForceHttps)
     * — jamais un refus pour "http://", une correction silencieuse avant que
     * UrlReseauSocial ne juge la forme finale.
     */
    protected function prepareForValidation(): void
    {
        $normalises = [];

        foreach (['lien_facebook', 'lien_instagram', 'lien_tiktok', 'lien_site_web'] as $champ) {
            if ($this->filled($champ)) {
                $normalises[$champ] = ForceHttps::appliquer((string) $this->input($champ));
            }
        }

        if ($normalises !== []) {
            $this->merge($normalises);
        }
    }

    public function rules(): array
    {
        $regleHoraireJour = ['nullable', 'array'];

        $regles = [
            'nom' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'couleur_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],

            'telephone_whatsapp' => ['nullable', 'string', new NumeroTelephoneIvoirien],
            'telephone_fixe' => ['nullable', 'string', 'max:30'],
            'email_contact' => ['nullable', 'email', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:500'],

            'horaires' => ['nullable', 'array'],

            'lien_facebook' => ['nullable', 'url', new UrlReseauSocial('facebook.com', 'Facebook')],
            'lien_instagram' => ['nullable', 'url', new UrlReseauSocial('instagram.com', 'Instagram')],
            'lien_tiktok' => ['nullable', 'url', new UrlReseauSocial('tiktok.com', 'TikTok')],
            'lien_site_web' => ['nullable', 'url'],
        ];

        foreach (self::JOURS as $jour) {
            $regles["horaires.{$jour}"] = $regleHoraireJour;
            $regles["horaires.{$jour}.ouverture"] = ['nullable', 'date_format:H:i'];
            $regles["horaires.{$jour}.fermeture"] = ['nullable', 'date_format:H:i'];
            $regles["horaires.{$jour}.ferme"] = ['sometimes', 'boolean'];
        }

        return $regles;
    }

    public function messages(): array
    {
        return [
            'couleur_accent.regex' => 'La couleur doit être un code hexadécimal à 6 chiffres (ex. '.strtoupper(CouleurAccent::PAR_DEFAUT).').',
            'horaires.*.ouverture.date_format' => "L'heure d'ouverture doit être au format HH:MM.",
            'horaires.*.fermeture.date_format' => "L'heure de fermeture doit être au format HH:MM.",
        ];
    }
}
