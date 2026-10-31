<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'nom',
        'libelle',
        'libelle_espace',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function appartenances(): BelongsToMany
    {
        return $this->belongsToMany(EtablissementUtilisateur::class, 'etablissement_utilisateur_roles');
    }
}
