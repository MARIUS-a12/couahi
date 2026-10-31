<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\StatutMembre;
use App\Support\Tenancy\ContexteEtablissement;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'derniere_connexion_a', 'etablissement_id', 'mot_de_passe_defini'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'est_super_admin' => 'boolean',
            'derniere_connexion_a' => 'datetime',
            'mot_de_passe_defini' => 'boolean',
        ];
    }

    public function appartenances(): HasMany
    {
        return $this->hasMany(EtablissementUtilisateur::class, 'utilisateur_id');
    }

    /**
     * Colonne dénormalisée (voir la migration "ajouter_etablissement_id_a_
     * users_table") : jamais la source de vérité du rattachement — celle-ci
     * reste appartenances() — seulement le support de la contrainte
     * d'unicité UNIQUE(etablissement_id, email). Null pour le super-admin.
     */
    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class);
    }

    /**
     * Interroge les permissions des rôles que l'utilisateur possède DANS
     * l'établissement courant (ContexteEtablissement), jamais globalement.
     * Lot F : il suffit qu'UN de ses rôles l'ait, les droits s'additionnent.
     * Relu en base à chaque appel, sans cache : un rôle retiré ou un membre
     * désactivé perd ce droit dès la requête suivante.
     * Sans contexte défini, la réponse est toujours non — y compris pour le
     * super-admin, dont le passe-droit est géré séparément par le
     * Gate::before d'AppServiceProvider, pas ici. Les policies n'ont donc
     * jamais besoin de connaître son existence.
     */
    public function peut(string $permission): bool
    {
        $contexte = app(ContexteEtablissement::class);

        if (! $contexte->estDefini()) {
            return false;
        }

        return $this->appartenances()
            ->where('etablissement_id', $contexte->id())
            ->where('statut', StatutMembre::Actif)
            ->whereHas('roles.permissions', fn ($requete) => $requete->where('nom', $permission))
            ->exists();
    }

    /**
     * Un booléen sur `users`, pas une ligne dans etablissement_utilisateurs :
     * le super-admin n'appartient à aucun établissement, donc aucune ligne à
     * y chercher — un etablissement_id nullable pour ce seul cas aurait
     * affaibli la contrainte UNIQUE (etablissement_id, utilisateur_id) pour
     * tout le monde. Sert uniquement au Gate::before d'AppServiceProvider.
     */
    public function estSuperAdmin(): bool
    {
        return $this->est_super_admin === true;
    }
}
