/**
 * Écran /etablissements/:id/paiement — chemin super-admin du formulaire de
 * paiement unique (voir FormulairePaiement), sur n'importe quel
 * établissement, pour pouvoir dépanner un commerçant.
 */
import { useMemo } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { serviceMobileMoneySuperAdmin } from '../api/mobileMoney'
import { servicePaiementSuperAdmin } from '../api/paiement'
import { BoutonRetour } from '../components/BoutonRetour'
import { FormulaireMobileMoney } from '../components/FormulaireMobileMoney'
import { FormulairePaiement } from '../components/FormulairePaiement'

export function PagePaiementSuperAdmin() {
  const { id } = useParams()
  const etablissementId = Number(id)
  const service = useMemo(() => servicePaiementSuperAdmin(etablissementId), [etablissementId])
  const serviceMobileMoney = useMemo(() => serviceMobileMoneySuperAdmin(etablissementId), [etablissementId])

  // Pas d'identifiant exploitable dans l'URL (accès direct malformé) : mieux
  // vaut revenir à la liste que de lancer un appel voué à échouer (sur
  // /api/etablissements/NaN/paiement).
  if (!id || Number.isNaN(etablissementId)) {
    return <Navigate to="/etablissements" replace />
  }

  return (
    <div className="mx-auto max-w-4xl space-y-10 pb-4">
      <div className="flex items-center gap-2">
        <BoutonRetour vers={`/etablissements/${etablissementId}`} />
        <h1 className="text-titre-page font-bold text-texte">Paiement de l'établissement</h1>
      </div>

      <section className="space-y-4">
        <h2 className="text-titre-section font-semibold text-texte">Paiement en ligne — CinetPay</h2>
        <FormulairePaiement service={service} />
      </section>

      <div className="border-t border-bordure pt-8">
        <FormulaireMobileMoney service={serviceMobileMoney} cle={['mobile-money', etablissementId]} />
      </div>
    </div>
  )
}
