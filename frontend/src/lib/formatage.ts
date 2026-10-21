// Formatage des montants en FCFA pour l'affichage (jamais un nombre brut
// du type "25000" montré au commerçant).
const formateurMontant = new Intl.NumberFormat('fr-FR')

/**
 * Jamais "NaN FCFA" : une valeur absente ou non numérique (champ manquant
 * dans une réponse, donnée en cache d'une version précédente de l'API,
 * saisie invalide) s'affiche "—", et le défaut est signalé en console pour
 * être corrigé à sa source plutôt que montré au commerçant comme un prix.
 */
export function formaterMontant(montant: number | null | undefined): string {
  if (typeof montant !== 'number' || !Number.isFinite(montant)) {
    console.error('formaterMontant : montant invalide', montant)

    return '— FCFA'
  }

  return `${formateurMontant.format(montant)} FCFA`
}
