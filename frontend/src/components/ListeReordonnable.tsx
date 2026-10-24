/**
 * Liste verticale réordonnable par glisser-déposer, AU DOIGT comme à la
 * souris : événements pointer (pointerdown / pointermove / pointerup avec
 * capture), pas le glisser-déposer HTML5, qui ne fonctionne pas sur la
 * plupart des téléphones — et la plupart des commerçants n'ont que ça.
 *
 * On ne saisit que la poignée (touch-action: none sur elle seule), pour
 * que le reste de la ligne laisse la page défiler normalement. Au clavier,
 * la poignée est un bouton : flèches haut et bas déplacent l'élément, et
 * une zone aria-live annonce sa nouvelle position.
 *
 * L'ordre n'est envoyé (onReordonner) qu'au lâcher, une seule fois, et
 * seulement s'il a changé.
 */
import { GripVertical } from 'lucide-react'
import { useRef, useState } from 'react'
import type { KeyboardEvent, PointerEvent, ReactNode } from 'react'

export function ListeReordonnable<T extends { id: number }>({
  elements,
  libelle,
  rendre,
  onReordonner,
  desactivee = false,
}: {
  elements: T[]
  libelle: (element: T) => string
  rendre: (element: T, poignee: ReactNode) => ReactNode
  onReordonner: (identifiants: number[]) => void
  desactivee?: boolean
}) {
  const [ordreLocal, setOrdreLocal] = useState<number[] | null>(null)
  const [glisse, setGlisse] = useState<number | null>(null)
  const [annonce, setAnnonce] = useState('')
  const lignes = useRef(new Map<number, HTMLLIElement>())

  const parId = new Map(elements.map((element) => [element.id, element]))
  // Pendant un glissement, l'ordre local fait foi ; sinon, celui du serveur.
  const ordre = (ordreLocal ?? elements.map((element) => element.id)).filter((id) => parId.has(id))

  function deplacer(liste: number[], id: number, versIndex: number): number[] {
    const sans = liste.filter((autre) => autre !== id)
    sans.splice(Math.max(0, Math.min(versIndex, sans.length)), 0, id)

    return sans
  }

  function commencer(evenement: PointerEvent<HTMLButtonElement>, id: number) {
    if (desactivee) {
      return
    }
    evenement.currentTarget.setPointerCapture(evenement.pointerId)
    setGlisse(id)
    setOrdreLocal(elements.map((element) => element.id))
  }

  function bouger(evenement: PointerEvent<HTMLButtonElement>) {
    if (glisse === null || ordreLocal === null) {
      return
    }
    const autres = ordreLocal.filter((id) => id !== glisse)
    const index = autres.findIndex((id) => {
      const rect = lignes.current.get(id)?.getBoundingClientRect()

      return rect !== undefined && evenement.clientY < rect.top + rect.height / 2
    })
    const nouvelOrdre = deplacer(ordreLocal, glisse, index === -1 ? autres.length : index)

    if (nouvelOrdre.join() !== ordreLocal.join()) {
      setOrdreLocal(nouvelOrdre)
    }
  }

  function lacher() {
    if (glisse !== null && ordreLocal !== null) {
      const avant = elements.map((element) => element.id)

      if (ordreLocal.join() !== avant.join()) {
        onReordonner(ordreLocal)
        annoncer(glisse, ordreLocal)
      }
    }
    setGlisse(null)
    setOrdreLocal(null)
  }

  function annoncer(id: number, liste: number[]) {
    const element = parId.get(id)

    if (element) {
      setAnnonce(`${libelle(element)} : position ${liste.indexOf(id) + 1} sur ${liste.length}.`)
    }
  }

  function auClavier(evenement: KeyboardEvent<HTMLButtonElement>, id: number) {
    if (desactivee || (evenement.key !== 'ArrowUp' && evenement.key !== 'ArrowDown')) {
      return
    }
    evenement.preventDefault()
    const liste = elements.map((element) => element.id)
    const index = liste.indexOf(id)
    const cible = evenement.key === 'ArrowUp' ? index - 1 : index + 1

    if (cible < 0 || cible >= liste.length) {
      return
    }
    const nouvelOrdre = deplacer(liste, id, cible)
    onReordonner(nouvelOrdre)
    annoncer(id, nouvelOrdre)
  }

  return (
    <>
      <ul className="divide-y divide-bordure rounded border border-bordure bg-surface">
        {ordre.map((id) => {
          const element = parId.get(id) as T
          const poignee = (
            <button
              type="button"
              aria-label={`Déplacer ${libelle(element)} (flèches haut et bas)`}
              disabled={desactivee}
              onPointerDown={(evenement) => commencer(evenement, id)}
              onPointerMove={bouger}
              onPointerUp={lacher}
              onPointerCancel={lacher}
              onKeyDown={(evenement) => auClavier(evenement, id)}
              className="flex h-11 w-11 shrink-0 cursor-grab touch-none items-center justify-center rounded text-texte-secondaire active:cursor-grabbing focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire disabled:cursor-not-allowed disabled:opacity-40"
            >
              <GripVertical aria-hidden="true" className="h-5 w-5" />
            </button>
          )

          return (
            <li
              key={id}
              ref={(noeud) => {
                if (noeud) {
                  lignes.current.set(id, noeud)
                } else {
                  lignes.current.delete(id)
                }
              }}
              className={`flex items-center gap-1 pr-1 transition-shadow ${
                glisse === id ? 'relative z-10 bg-surface-haute shadow-lg ring-2 ring-primaire' : ''
              }`}
            >
              {rendre(element, poignee)}
            </li>
          )
        })}
      </ul>
      <p aria-live="polite" className="sr-only">
        {annonce}
      </p>
    </>
  )
}
