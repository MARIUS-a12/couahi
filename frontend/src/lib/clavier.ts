/**
 * Dans un champ placé à l'intérieur du formulaire produit, Entrée
 * soumettrait tout le produit : on l'intercepte, et on exécute à la place
 * l'action propre au champ (ajouter une valeur, renommer…), s'il y en a
 * une.
 */
import type { KeyboardEvent } from 'react'

export function sansEntree(evenement: KeyboardEvent, action?: () => void) {
  if (evenement.key === 'Enter') {
    evenement.preventDefault()
    action?.()
  }
}
