/**
 * L'icône du Mobile Money, partout la même. Pas "Smartphone" de lucide :
 * aux petites tailles (14-16 px), ce n'est qu'un contour de rectangle avec
 * un point presque invisible — il se lisait comme une case vide à côté de
 * "Mobile Money". SmartphoneNfc ajoute les ondes d'un paiement sans contact :
 * un téléphone qui paie, lisible même en 14 px.
 */
import { SmartphoneNfc } from 'lucide-react'
import type { LucideProps } from 'lucide-react'

export function IconeMobileMoney(props: LucideProps) {
  return <SmartphoneNfc aria-hidden="true" {...props} />
}
