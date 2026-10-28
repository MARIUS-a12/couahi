<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutDemandeWhatsapp;
use App\Http\Controllers\Controller;
use App\Http\Resources\DemandeWhatsappResource;
use App\Models\ReferenceWhatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lot D — les demandes WhatsApp de la boutique (un clic sur « Commander sur
 * WhatsApp » = une référence). Lecture et changement de statut seulement :
 * transformer une demande en commande passe par la saisie manuelle du
 * lot C (voir SaisirCommandeManuelle), jamais par ici.
 *
 * Permission gerer_demandes_whatsapp, distincte de celles des commandes :
 * répondre à des clients et toucher au stock ne sont pas le même droit. Le
 * scope d'établissement de ReferenceWhatsapp cache les demandes des autres
 * boutiques, y compris à la liaison de route (404).
 */
class DemandeWhatsappController extends Controller
{
    public const PAR_PAGE = 25;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->autoriser($request);
        $donnees = $request->validate(['statut' => ['sometimes', Rule::enum(StatutDemandeWhatsapp::class)]]);

        $demandes = ReferenceWhatsapp::query()
            ->with(['produit.medias' => fn ($requete) => $requete->orderBy('media_produit.ordre'), 'variante.media', 'commande'])
            ->where('statut', $donnees['statut'] ?? StatutDemandeWhatsapp::Nouvelle->value)
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PAR_PAGE);

        return DemandeWhatsappResource::collection($demandes);
    }

    /** Le nombre de demandes nouvelles, pour le compteur du menu. */
    public function compte(Request $request): JsonResponse
    {
        $this->autoriser($request);

        return response()->json(['data' => ['nouvelles' => ReferenceWhatsapp::where('statut', StatutDemandeWhatsapp::Nouvelle)->count()]]);
    }

    /**
     * Vers « traitee » ou « ignoree » seulement. Une demande devenue commande
     * reste « traitee » : l'ignorer contredirait la commande qui existe.
     */
    public function update(Request $request, ReferenceWhatsapp $demande): DemandeWhatsappResource
    {
        $this->autoriser($request);
        $donnees = $request->validate(
            ['statut' => ['required', Rule::in([StatutDemandeWhatsapp::Traitee->value, StatutDemandeWhatsapp::Ignoree->value])]],
            ['statut.in' => 'Une demande se marque traitée ou ignorée.'],
        );

        $commande = $demande->commande;

        if ($commande !== null && $donnees['statut'] !== StatutDemandeWhatsapp::Traitee->value) {
            throw ValidationException::withMessages(['statut' => ["Cette demande est devenue la commande {$commande->numero} : elle reste traitée."]]);
        }

        $demande->update(['statut' => $donnees['statut'], 'statut_change_le' => now()]);

        return new DemandeWhatsappResource($demande->load(['produit.medias', 'variante.media', 'commande']));
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()->peut('gerer_demandes_whatsapp'), 403);
    }
}
