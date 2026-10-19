/**
 * Écran /etablissements/:id/abonnement — la section d'abonnement de la
 * fiche (SectionAbonnementSuperAdmin), seule sur sa page, atteinte depuis
 * le sous-menu de la barre latérale : enregistrer un paiement reçu ne doit
 * pas exiger de faire défiler toute la fiche.
 */
import { Wallet } from 'lucide-react'
import { Navigate, useParams } from 'react-router-dom'
import { BoutonRetour } from '../components/BoutonRetour'
import { EnteteDePage } from '../components/EnteteDePage'
import { SectionAbonnementSuperAdmin } from '../components/SectionAbonnementSuperAdmin'
import { useEtablissement } from '../hooks/useEtablissement'

export function PageAbonnementSuperAdmin() {
  const { id } = useParams()
  const etablissementId = Number(id)
  const { data: etablissement } = useEtablissement(etablissementId)

  if (!id || Number.isNaN(etablissementId)) {
    return <Navigate to="/etablissements" replace />
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6 pb-4">
      <BoutonRetour vers={`/etablissements/${etablissementId}`} />
      <EnteteDePage
        icone={<Wallet size={24} strokeWidth={1.75} />}
        couleur="vert"
        titre="Abonnement"
        sousTitre={etablissement ? `Échéance, réglages et paiements de ${etablissement.nom}.` : 'Échéance, réglages et paiements.'}
      />
      <SectionAbonnementSuperAdmin etablissementId={etablissementId} />
    </div>
  )
}
