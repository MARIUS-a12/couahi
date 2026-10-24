/**
 * Retient, dans un conteneur qui défile horizontalement, le balayage au
 * doigt qui bute sur un bord : sans ça, le navigateur le reprend à son
 * compte et le lit comme « page précédente ». Vu sur la grille des
 * combinaisons à 390 px (5 tailles × 6 coloris) : un balayage vers la
 * droite, grille déjà calée à gauche, ramenait à la liste des produits —
 * en plein geste, avec les cases cochées en brouillon.
 *
 * overscroll-behavior-x ne suffit pas : Chrome l'ignore pour ce geste
 * quand il part d'un conteneur intérieur (constaté, voir decisions.md). On
 * annule donc le touchmove, et SEULEMENT celui qui est horizontal ET pousse
 * contre un bord atteint : un balayage vertical fait toujours défiler la
 * page, un balayage horizontal fait toujours défiler la grille.
 *
 * Renvoie une ref à poser sur le conteneur (ref de rappel : la grille peut
 * apparaître après le premier rendu).
 */
import { useCallback, useRef } from 'react'

export function useBalayageRetenu<T extends HTMLElement>() {
  const nettoyer = useRef<(() => void) | null>(null)

  return useCallback((element: T | null) => {
    nettoyer.current?.()
    nettoyer.current = null

    if (element === null) {
      return
    }

    let dernierX = 0
    let departX = 0
    let departY = 0
    let sens: 'horizontal' | 'vertical' | null = null

    function debut(evenement: TouchEvent) {
      departX = dernierX = evenement.touches[0].clientX
      departY = evenement.touches[0].clientY
      sens = null
    }

    function mouvement(evenement: TouchEvent) {
      const { clientX, clientY } = evenement.touches[0]

      if (sens === null && Math.max(Math.abs(clientX - departX), Math.abs(clientY - departY)) > 6) {
        sens = Math.abs(clientX - departX) > Math.abs(clientY - departY) ? 'horizontal' : 'vertical'
      }
      const versLaDroite = clientX > dernierX
      dernierX = clientX

      if (sens !== 'horizontal' || element === null) {
        return
      }
      const auBordGauche = element.scrollLeft <= 0
      const auBordDroit = element.scrollLeft + element.clientWidth >= element.scrollWidth - 1

      if (evenement.cancelable && ((versLaDroite && auBordGauche) || (!versLaDroite && auBordDroit))) {
        evenement.preventDefault()
      }
    }

    element.addEventListener('touchstart', debut, { passive: true })
    element.addEventListener('touchmove', mouvement, { passive: false })
    nettoyer.current = () => {
      element.removeEventListener('touchstart', debut)
      element.removeEventListener('touchmove', mouvement)
    }
  }, [])
}
