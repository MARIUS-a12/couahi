<?php

namespace App\Models;

use App\Enums\PageConsultee;
use App\Models\Concerns\AppartientAEtablissement;
use Illuminate\Database\Eloquent\Model;

class ConsultationPlateforme extends Model
{
    use AppartientAEtablissement;

    protected $table = 'consultations_plateforme';

    public $timestamps = false;

    protected $fillable = [
        'utilisateur_id',
        'nom_utilisateur',
        'page',
        'consulte_le',
    ];

    protected function casts(): array
    {
        return [
            'page' => PageConsultee::class,
            'consulte_le' => 'datetime',
        ];
    }
}
