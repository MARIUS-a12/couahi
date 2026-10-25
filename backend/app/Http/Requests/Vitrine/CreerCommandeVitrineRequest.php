<?php

namespace App\Http\Requests\Vitrine;

use App\Enums\ModePaiement;
use App\Rules\NumeroTelephoneIvoirien;
use App\Support\Tenancy\ContexteEtablissement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Le panier (lignes) n'arrive ici que par identifiants et quantités : un
 * "prix" ou un "total" envoyé par le navigateur n'est même pas dans la liste
 * des champs validés, donc $request->validated() ne les reprend jamais — le
 * serveur ne peut littéralement pas les lire, encore moins les utiliser (voir
 * VitrineCommandeController, qui recalcule tout via CreerCommande).
 *
 * Correctif livraison — remplace "zone_livraison_id" : un seul champ
 * "commune" ("on ne demande pas deux fois la même chose"), liste déroulante
 * quand l'établissement facture la livraison par commune, texte libre sinon
 * (voir Etablissement::zonesLivraisonProposees). Dans le
 * premier cas, la commune DOIT correspondre au nom d'une zone active DE CET
 * ÉTABLISSEMENT — c'est VitrineCommandeController qui la résout ensuite en
 * ZoneLivraison pour le calcul du frais, jamais un identifiant envoyé par le
 * navigateur.
 */
class CreerCommandeVitrineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'commune' => is_string($this->input('commune')) ? trim($this->input('commune')) : $this->input('commune'),
            'quartier' => is_string($this->input('quartier')) ? trim($this->input('quartier')) : $this->input('quartier'),
        ]);
    }

    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.variante_id' => ['nullable', 'integer'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],

            'client' => ['required', 'array'],
            'client.nom' => ['required', 'string', 'max:255'],
            'client.telephone' => ['required', 'string', new NumeroTelephoneIvoirien],
            'client.email' => ['nullable', 'email', 'max:255'],

            'commune' => ['required', 'string', ...$this->regleCommune()],
            'autre_commune' => ['sometimes', 'boolean'],
            'quartier' => ['required', 'string', 'max:150'],

            'cle_idempotence' => ['required', 'string', 'max:255'],

            // Étape 6C-1 : aucun appel CinetPay n'existe encore, mais le
            // refus, lui, doit déjà exister — ne jamais se fier à
            // l'interface (qui masque déjà le bouton) pour l'empêcher.
            'paiement_en_ligne' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value && ! app(ContexteEtablissement::class)->obtenir()?->cinetpayEstDisponible()) {
                        $fail("Le paiement en ligne n'est pas disponible pour cet établissement.");
                    }
                },
            ],

            // Étape 13 : le seul mode accepté est celui que l'établissement
            // propose (voir Etablissement::modePaiementPropose) — jamais un
            // Mobile Money quand CinetPay est configuré, jamais un mode que
            // l'établissement n'a pas. Les espèces ne se choisissent pas
            // depuis la vitrine.
            'mode_paiement' => [
                'sometimes',
                'nullable',
                Rule::in([ModePaiement::Cinetpay->value, ModePaiement::MobileMoney->value]),
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value !== null && app(ContexteEtablissement::class)->obtenir()?->modePaiementPropose()?->value !== $value) {
                        $fail("Ce moyen de paiement n'est pas proposé par cet établissement.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array{0: In|string}
     */
    private function regleCommune(): array
    {
        $nomsZonesActives = app(ContexteEtablissement::class)->obtenir()->zonesLivraisonProposees()->pluck('nom');

        if ($nomsZonesActives->isEmpty()) {
            return ['max:100'];
        }

        if (! $this->boolean('autre_commune')) {
            return [Rule::in($nomsZonesActives)];
        }

        // « Autre commune » : saisie libre, livraison à convenir. Une
        // commune de la liste n'y a pas sa place, sinon choisir « Autre »
        // et taper « Cocody » suffirait à ne pas payer les frais de Cocody.
        $listees = $nomsZonesActives->map(fn (string $nom) => mb_strtolower($nom));

        return ['max:100', function (string $attribut, mixed $valeur, callable $echec) use ($listees): void {
            if ($listees->contains(mb_strtolower(trim((string) $valeur)))) {
                $echec("{$valeur} est dans la liste : choisissez-la, ses frais de livraison sont connus.");
            }
        }];
    }

    public function messages(): array
    {
        return [
            'commune.in' => 'Choisissez une commune dans la liste proposée.',
        ];
    }
}
