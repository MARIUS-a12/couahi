/**
 * Le prix qu'un produit annonce avant tout choix de variante — sur la fiche
 * ET sur les cartes de la grille, toujours par cette seule fonction : un
 * prix juste sur la fiche et faux sur la carte ne vaudrait rien.
 *
 * Variantes à prix différents : « À partir de » le moins cher. Afficher le
 * prix du produit seul (le plus bas, souvent) promettait 1 000 FCFA pour un
 * riz dont le sac de 25 kg coûte 21 000. Le minimum est pris parmi les
 * variantes encore disponibles — annoncer le prix d'une variante épuisée,
 * que personne ne peut acheter, serait le même mensonge — et parmi toutes
 * si aucune ne l'est. Prix tous identiques : le prix seul, la mention
 * n'apporterait rien.
 */
import type { VarianteVitrine } from '../api/vitrine'

export type PrixAnnonce = { montant: number; aPartirDe: boolean }

export function prixAnnonce(prixProduit: number, variantes: VarianteVitrine[]): PrixAnnonce {
  if (variantes.length === 0) {
    return { montant: prixProduit, aPartirDe: false }
  }

  const disponibles = variantes.filter((variante) => variante.disponible)
  const prix = (disponibles.length > 0 ? disponibles : variantes).map((variante) => variante.prix)
  const tousLesPrix = new Set(variantes.map((variante) => variante.prix))

  return { montant: Math.min(...prix), aPartirDe: tousLesPrix.size > 1 }
}
