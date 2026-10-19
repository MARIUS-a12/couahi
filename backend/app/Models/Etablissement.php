<?php

namespace App\Models;

use App\Enums\StatutEtablissement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Etablissement extends Model
{
    use HasFactory;

    protected $table = 'etablissements';

    /**
     * Étape 6C-1 : jamais en clair hors de ce modèle, ni en masse-assignation
     * (absents de $fillable, voir ConfigurerPaiementEtablissement qui les
     * pose par affectation directe), ni dans une sérialisation par défaut.
     * Une Resource qui doit exposer une version masquée (quatre derniers
     * caractères) lit l'attribut déchiffré directement sur le modèle — ce
     * qui fonctionne malgré $hidden, qui ne joue que sur la sérialisation.
     */
    protected $hidden = ['cinetpay_site_id', 'cinetpay_cle_api', 'cinetpay_secret'];

    /**
     * Miroir des défauts posés en base, pour qu'un établissement tout juste
     * créé (avant tout rechargement) expose déjà des valeurs exploitables —
     * même pattern que Produit/Categorie. Sans lui, CreerEtablissement
     * renvoyait un statut null dans sa réponse de création alors que la
     * ligne en base portait bien "actif".
     */
    protected $attributes = [
        'statut' => 'actif',
        'fuseau_horaire' => 'Africa/Abidjan',
        'devise' => 'XOF',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutEtablissement::class,
            'horaires' => 'array',
            'cinetpay_site_id' => 'encrypted',
            'cinetpay_cle_api' => 'encrypted',
            'cinetpay_secret' => 'encrypted',
            'paiement_configure_le' => 'datetime',
        ];
    }

    protected $fillable = [
        'nom',
        'description',
        'slug',
        'type',
        'raison_sociale',
        'email',
        'email_contact',
        'telephone',
        'telephone_whatsapp',
        'telephone_fixe',
        'adresse',
        'horaires',
        'lien_facebook',
        'lien_instagram',
        'lien_tiktok',
        'lien_site_web',
        'logo_media_id',
        'couleur_accent',
        'statut',
        'fuseau_horaire',
        'devise',
    ];

    public function domaines(): HasMany
    {
        return $this->hasMany(Domaine::class);
    }

    public function parametreSite(): HasOne
    {
        return $this->hasOne(ParametreSite::class);
    }

    public function appartenances(): HasMany
    {
        return $this->hasMany(EtablissementUtilisateur::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Categorie::class);
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function medias(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function logoMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function zonesLivraison(): HasMany
    {
        return $this->hasMany(ZoneLivraison::class);
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class);
    }

    /**
     * Étape 12 — toujours présent : ouvert en essai dès la création de
     * l'établissement (voir booted() ci-dessous), quel que soit le chemin
     * de création (super-admin, seeder, factory de test).
     */
    public function abonnement(): HasOne
    {
        return $this->hasOne(Abonnement::class);
    }

    public function paiementsAbonnement(): HasMany
    {
        return $this->hasMany(PaiementAbonnement::class);
    }

    /**
     * Sur l'événement "created" plutôt que dans CreerEtablissement : un
     * établissement sans abonnement serait un trou dans la facturation (et
     * une vitrine que rien ne coupe jamais), quel que soit le chemin qui
     * l'a créé.
     */
    protected static function booted(): void
    {
        static::created(fn (Etablissement $etablissement) => Abonnement::ouvrirEssaiPour($etablissement));
    }

    public function configurateurPaiement(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paiement_configure_par');
    }

    /**
     * Propriété CALCULÉE, jamais une colonne qu'on coche : vraie seulement
     * quand les trois identifiants CinetPay sont renseignés. Pilote à la
     * fois l'affichage du bouton "Payer maintenant" en vitrine et le refus
     * serveur d'une commande qui demanderait le paiement en ligne sans eux.
     */
    public function paiementEstConfigure(): bool
    {
        return $this->cinetpay_site_id !== null
            && $this->cinetpay_cle_api !== null
            && $this->cinetpay_secret !== null;
    }

    public function estActif(): bool
    {
        return $this->statut === StatutEtablissement::Actif;
    }

    public function estRestaurant(): bool
    {
        return $this->type === 'restaurant';
    }
}
