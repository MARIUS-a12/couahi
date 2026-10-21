/**
 * État d'erreur générique pour une liste ou une fiche. La forme change
 * selon le type d'erreur : un refus ou une ressource introuvable ne se
 * résoudront jamais en réessayant, le bouton Réessayer n'a donc de sens
 * que pour une panne réseau ou une erreur serveur.
 */
import { AlertTriangle, Lock, SearchX } from 'lucide-react'
import { typeErreurAffichage } from '../lib/erreurAffichage'

export function EtatErreur({ erreur, onReessayer }: { erreur?: unknown; onReessayer: () => void }) {
  const type = typeErreurAffichage(erreur)

  if (type === 'permission') {
    return (
      <div className="flex flex-col items-center gap-3 border border-bordure bg-surface px-6 py-16 text-center">
        <Lock aria-hidden="true" size={40} strokeWidth={1.5} className="text-danger" />
        <p className="max-w-sm text-corps text-texte">
          Vous n'avez pas les droits nécessaires pour cette action.
        </p>
      </div>
    )
  }

  if (type === 'introuvable') {
    return (
      <div className="flex flex-col items-center gap-3 border border-bordure bg-surface px-6 py-16 text-center">
        <SearchX aria-hidden="true" size={40} strokeWidth={1.5} className="text-danger" />
        <p className="max-w-sm text-corps text-texte">Élément introuvable.</p>
      </div>
    )
  }

  return (
    <div className="flex flex-col items-center gap-3 border border-bordure bg-surface px-6 py-16 text-center">
      <AlertTriangle aria-hidden="true" size={40} strokeWidth={1.5} className="text-danger" />
      <p className="max-w-sm text-corps text-texte">
        Impossible de récupérer les produits. Vérifiez votre connexion et réessayez.
      </p>
      <button
        type="button"
        onClick={onReessayer}
        className="mt-2 inline-flex min-h-11 py-2 text-center leading-snug cursor-pointer items-center rounded border border-bordure bg-surface px-4 text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] active:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
      >
        Réessayer
      </button>
    </div>
  )
}
