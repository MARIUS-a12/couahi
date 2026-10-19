/**
 * Petit panneau modal générique pour une fonctionnalité pas encore
 * disponible (paiement, panier — voir les boutons de PageFicheProduitVitrine).
 * Volontairement minimal : pas de bibliothèque de modale, juste un fond
 * assombri et une carte, comme le reste du projet gère ses confirmations
 * (voir lib/confirmations.ts pour l'équivalent côté back-office).
 *
 * Entrée : fond en fondu + panneau qui monte de 16px en s'opacifiant
 * (animate-entree-fondu / animate-entree-panneau, 200ms, se jouent seuls au
 * montage). Sortie : l'inverse en 150ms — mais une transition ne peut pas
 * se jouer sur un élément qu'on démonte, donc onFermer() est retardé de
 * 150ms pendant que la classe "en fermeture" bascule l'opacité/la
 * position sous transition ; c'est SEULEMENT ce démontage différé qui
 * laisse le temps à l'animation de sortie de se voir avant de disparaître.
 */
import type { ReactNode } from 'react'
import { useState } from 'react'
import { createPortal } from 'react-dom'

export function PanneauMessage({
  titre,
  message,
  onFermer,
}: {
  titre: string
  // ReactNode (Étape 12) : la page d'abonnement y met en gras le montant et
  // le numéro Mobile Money ; une simple chaîne reste le cas courant.
  message: ReactNode
  onFermer: () => void
}) {
  const [enFermeture, setEnFermeture] = useState(false)

  function demanderFermeture() {
    setEnFermeture(true)
    setTimeout(onFermer, 150)
  }

  const panneau = (
    <div
      role="presentation"
      onClick={demanderFermeture}
      className={`fixed inset-0 z-50 flex items-end justify-center bg-texte/40 p-4 transition-opacity duration-rapide ease-disparition sm:items-center ${
        enFermeture ? 'opacity-0' : 'animate-entree-fondu'
      }`}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={titre}
        onClick={(evenement) => evenement.stopPropagation()}
        className={`w-full max-w-sm rounded border border-bordure bg-surface p-4 shadow-flottant transition-[opacity,transform] duration-rapide ease-disparition ${
          enFermeture ? 'translate-y-4 opacity-0' : 'animate-entree-panneau'
        }`}
      >
        <h2 className="text-titre-section font-semibold text-texte">{titre}</h2>
        <div className="mt-2 text-corps text-texte-secondaire">{message}</div>
        <button
          type="button"
          onClick={demanderFermeture}
          className="mt-4 h-11 w-full cursor-pointer rounded bg-texte text-corps font-medium text-surface transition-transform active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
        >
          Fermer
        </button>
      </div>
    </div>
  )

  // Dans le back-office (Étape 12, page d'abonnement), la page est rendue
  // sous TransitionPage, dont l'animation crée un contexte d'empilement :
  // le fond assombri y restait SOUS la barre supérieure collante. Rendu à
  // la racine ".back-office" — jamais sur <body>, qui perdrait les jetons
  // de couleur redéfinis par cette classe. La vitrine, elle, n'est pas
  // concernée et garde le rendu en place.
  const racineBackOffice = document.querySelector('.back-office')

  return racineBackOffice ? createPortal(panneau, racineBackOffice) : panneau
}
