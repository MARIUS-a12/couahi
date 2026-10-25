/**
 * Le choix d'une variante sur la fiche, dimension par dimension (une ligne
 * « Pointure », une ligne « Coloris »), au lieu d'une liste à plat de
 * « 39 / Noir », « 40 / Noir »… Toute la logique est ici, sans React, pour
 * être testée seule.
 *
 * Une sélection associe une option à une valeur choisie ; elle peut être
 * partielle. Une valeur est jugée en remplaçant le choix de SA ligne par
 * elle, les autres lignes gardées : le 39 se juge contre le marron choisi,
 * jamais contre le 40 qu'il remplacerait.
 *
 * Seules les variantes actives arrivent ici (l'API ne renvoie qu'elles) :
 * « inexistante » veut donc dire qu'aucune variante en vente ne porte cette
 * combinaison. Le serveur reste le seul juge à l'achat (voir
 * RequetesForgeesTest) ; ce module ne fait que ne rien proposer d'invendable.
 */
import type { VarianteVitrineDetail } from '../api/vitrine'

/** Identifiant d'option → identifiant de la valeur choisie. */
export type SelectionVariante = Record<number, number>

export type EtatValeur = 'disponible' | 'epuisee' | 'inexistante'

export function variantesCompatibles(
  variantes: VarianteVitrineDetail[],
  selection: SelectionVariante,
): VarianteVitrineDetail[] {
  const choisies = Object.values(selection)

  return variantes.filter((variante) => choisies.every((valeur) => variante.valeurs.includes(valeur)))
}

export function etatValeur(
  variantes: VarianteVitrineDetail[],
  selection: SelectionVariante,
  optionId: number,
  valeurId: number,
): EtatValeur {
  const compatibles = variantesCompatibles(variantes, { ...selection, [optionId]: valeurId })

  if (compatibles.length === 0) {
    return 'inexistante'
  }

  return compatibles.some((variante) => variante.disponible) ? 'disponible' : 'epuisee'
}

/** Cliquer la valeur déjà choisie la retire : on peut toujours revenir en arrière. */
export function basculerValeur(selection: SelectionVariante, optionId: number, valeurId: number): SelectionVariante {
  const suivante = { ...selection }

  if (suivante[optionId] === valeurId) {
    delete suivante[optionId]
  } else {
    suivante[optionId] = valeurId
  }

  return suivante
}

/** La variante désignée, seulement quand chaque option a sa valeur. */
export function varianteDeSelection(
  variantes: VarianteVitrineDetail[],
  nombreOptions: number,
  selection: SelectionVariante,
): VarianteVitrineDetail | null {
  if (nombreOptions === 0 || Object.keys(selection).length < nombreOptions) {
    return null
  }

  return variantesCompatibles(variantes, selection)[0] ?? null
}

/**
 * La photo à montrer pour une sélection, même partielle : celle que portent
 * TOUTES les variantes encore possibles. Le noir seul choisi suffit (les
 * sept noires ont la même photo) ; une pointure seule, portée en noir et en
 * marron, ne tranche rien : null, la photo affichée ne bouge pas.
 */
export function photoDeSelection(variantes: VarianteVitrineDetail[], selection: SelectionVariante): number | null {
  const photos = new Set(variantesCompatibles(variantes, selection).map((variante) => variante.photo_id))

  if (photos.size !== 1) {
    return null
  }

  return [...photos][0]
}

/**
 * La photo à montrer à côté d'une ligne du sélecteur, une fois sa valeur
 * choisie : seulement pour une ligne qui PILOTE la photo, c'est-à-dire dont
 * chaque valeur désigne à elle seule une photo, et au moins deux photos
 * différentes en tout (le coloris des sandales). Pas pour la pointure, même
 * quand le 45, qui n'existe qu'en noir, en désigne une par hasard. À 390 px
 * la grande photo est un écran plus haut : sans cette vignette, le client ne
 * voit pas la photo changer au moment où il touche le coloris.
 */
export function photoDeLaLigne(
  variantes: VarianteVitrineDetail[],
  option: { id: number; valeurs: { id: number }[] },
  selection: SelectionVariante,
): number | null {
  const choisie = selection[option.id]
  if (choisie === undefined) {
    return null
  }

  const photos = option.valeurs.map((valeur) => photoDeSelection(variantes, { [option.id]: valeur.id }))
  const pilote = photos.every((photo) => photo !== null) && new Set(photos).size > 1

  return pilote ? photoDeSelection(variantes, { [option.id]: choisie }) : null
}
