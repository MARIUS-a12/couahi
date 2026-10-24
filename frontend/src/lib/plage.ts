/**
 * Plage numérique d'une option : « de 39 à 45 » donne 39, 40, … 45, dans
 * l'ordre de saisie (« de 45 à 39 » donne l'ordre inverse). Des nombres
 * ENTIERS seulement : une plage de 38,5 à 42,5 n'a pas de pas évident, la
 * valeur se tape seule. 50 valeurs au plus, comme le serveur.
 *
 * Renvoie la liste, ou le message à afficher au commerçant.
 */
export const PLAGE_MAXIMUM = 50

const ENTIER = /^\d{1,6}$/

export function valeursDePlage(debut: string, fin: string): { valeurs: string[] } | { erreur: string } {
  const de = debut.trim()
  const a = fin.trim()

  if (de === '' || a === '') {
    return { erreur: 'Indiquez le premier et le dernier nombre.' }
  }
  if (!ENTIER.test(de) || !ENTIER.test(a)) {
    return { erreur: 'Une plage ne prend que des nombres entiers (39, 100…). Une valeur comme 38,5 s’ajoute seule.' }
  }

  const premier = Number(de)
  const dernier = Number(a)
  const nombre = Math.abs(dernier - premier) + 1

  if (nombre > PLAGE_MAXIMUM) {
    return { erreur: `Cela ferait ${nombre} valeurs : ${PLAGE_MAXIMUM} au plus à la fois.` }
  }

  const pas = dernier >= premier ? 1 : -1

  return { valeurs: Array.from({ length: nombre }, (_, index) => String(premier + index * pas)) }
}

/** Une option dont toutes les valeurs sont des nombres (ou qui n'en a encore aucune). */
export function optionNumerique(libelles: string[]): boolean {
  return libelles.every((libelle) => /^\d+([.,]\d+)?$/.test(libelle.trim()))
}
