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

/** Les frais fixes de toute commune hors liste ; null : à convenir. */
export type AutreCommune = { frais: number } | null

/** L'option « Autre commune » annonce ses frais fixes, ou la livraison à convenir. */
export function libelleOptionAutreCommune(autre: AutreCommune): string {
  return `Autre commune — ${autre ? libelleFrais(autre.frais) : 'livraison à convenir'}`
}

/**
 * Les frais de la livraison en cours de saisie, calculés comme le serveur
 * (Etablissement::zoneLivraisonPour) : ceux de la commune de la liste,
 * sinon « Autre commune » à frais fixes, choisie ou valant pour toute
 * commune quand la liste est vide. null : à convenir ; undefined : commune
 * pas encore choisie.
 */
export function fraisDeLivraison(
  zones: { nom: string; frais: number }[],
  autre: AutreCommune,
  commune: string,
  autreCommune: boolean,
): number | null | undefined {
  if (zones.length === 0 || autreCommune) {
    return autre ? autre.frais : null
  }
  return zones.find((zone) => zone.nom === commune)?.frais
}
