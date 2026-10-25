/**
 * Ce que l'on écrit en face de « Livraison », partout : l'écran du
 * commerçant, le récapitulatif de la vitrine, la confirmation et le détail
 * d'une commande. « 0 FCFA » laissait croire au client que la livraison
 * était gratuite alors que la boutique ne la facturait simplement pas sur
 * le site : il découvrait les frais au moment d'être livré.
 */
import { formaterMontant } from './formatage'

/** Les frais d'une zone : 0 veut dire offerte, choisi par le commerçant. */
export function libelleFrais(frais: number): string {
  return frais === 0 ? 'Offerte' : formaterMontant(frais)
}

/**
 * Une commande sans zone n'a rien payé pour la livraison parce que la
 * boutique ne la facture pas sur le site : elle se règle avec la boutique.
 */
export function libelleLivraisonCommande(zone: { nom: string } | null, frais: number): string {
  return zone === null && frais === 0 ? 'À convenir avec la boutique' : libelleFrais(frais)
}
