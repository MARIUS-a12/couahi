/**
 * « blanc, noir » tapé dans le champ d'une option, ce sont deux valeurs :
 * un commerçant écrit naturellement une liste séparée par des virgules.
 * Espaces retirés, vides ignorés, doublons (sans tenir compte de la casse)
 * gardés une fois, dans l'ordre tapé. Le serveur découpe de même (voir
 * VariantesProduitController::ajouterValeur).
 */
export function separerValeurs(texte: string): string[] {
  const vues = new Set<string>()

  return texte
    .split(',')
    .map((morceau) => morceau.trim())
    .filter((morceau) => {
      const cle = morceau.toLowerCase()
      if (morceau === '' || vues.has(cle)) {
        return false
      }
      vues.add(cle)
      return true
    })
}
