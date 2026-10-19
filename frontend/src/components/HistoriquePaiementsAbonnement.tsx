/**
 * Historique des paiements d'abonnement (Étape 12), partagé par la page du
 * commerçant et la fiche super-admin : date du paiement, période couverte,
 * montant, moyen. Liste empilée sur mobile, colonnes alignées dès 640px —
 * jamais un tableau qui déborde horizontalement sur un téléphone.
 */
import type { PaiementAbonnement } from '../api/abonnement'
import { formaterDateLongue, LIBELLES_MOYEN_PAIEMENT } from '../lib/abonnement'
import { formaterMontant } from '../lib/formatage'

export function HistoriquePaiementsAbonnement({ paiements }: { paiements: PaiementAbonnement[] }) {
  if (paiements.length === 0) {
    return <p className="p-4 text-corps text-texte-secondaire">Aucun paiement enregistré pour l'instant.</p>
  }

  return (
    <div>
      <div className="hidden grid-cols-[1fr_1.6fr_1fr_1fr] gap-3 border-b border-bordure px-4 py-2 text-petit font-medium text-texte-secondaire sm:grid">
        <span>Payé le</span>
        <span>Période couverte</span>
        <span className="text-right">Montant</span>
        <span>Moyen</span>
      </div>
      <ul className="divide-y divide-bordure">
        {paiements.map((paiement) => (
          <li
            key={paiement.id}
            className="grid grid-cols-2 gap-x-3 gap-y-1 px-4 py-3 text-corps text-texte sm:grid-cols-[1fr_1.6fr_1fr_1fr] sm:items-center"
          >
            <span className="font-medium sm:font-normal">{formaterDateLongue(paiement.paye_le)}</span>
            <span className="order-last col-span-2 text-petit text-texte-secondaire sm:order-none sm:col-span-1 sm:text-corps sm:text-texte">
              Du {formaterDateLongue(paiement.periode_debut)} au {formaterDateLongue(paiement.periode_fin)}
            </span>
            <span className="text-right font-semibold tabular-nums">{formaterMontant(paiement.montant)}</span>
            <span className="text-petit text-texte-secondaire sm:text-corps">
              {LIBELLES_MOYEN_PAIEMENT[paiement.moyen]}
              {paiement.reference && <span className="block text-petit text-texte-secondaire">{paiement.reference}</span>}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
