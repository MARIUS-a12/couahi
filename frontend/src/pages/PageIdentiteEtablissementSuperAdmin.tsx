/**
 * Écran /etablissements/:id/identite — chemin super-admin du formulaire
 * d'identité unique (voir FormulaireIdentiteEtablissement), sur n'importe
 * quel établissement. Atteint aussi juste après la création (Temps 2, voir
 * PageFormulaireEtablissement) : révèle alors le mot de passe généré une
 * seule fois, et propose "Remplir plus tard" vers la liste.
 */
import { useMemo, useState } from 'react'
import { Link, Navigate, useLocation, useParams } from 'react-router-dom'
import { serviceIdentiteSuperAdmin } from '../api/identite'
import { BandeauMotDePasseGenere } from '../components/BandeauMotDePasseGenere'
import { BoutonRetour } from '../components/BoutonRetour'
import { FormulaireIdentiteEtablissement } from '../components/FormulaireIdentiteEtablissement'

type EtatNavigation = {
  motDePasseGenere?: string
  estPremierRemplissage?: boolean
}

export function PageIdentiteEtablissementSuperAdmin() {
  const { id } = useParams()
  const etablissementId = Number(id)
  const location = useLocation()
  const etat = location.state as EtatNavigation | null

  const [motDePasseGenere, setMotDePasseGenere] = useState(etat?.motDePasseGenere ?? null)
  const service = useMemo(() => serviceIdentiteSuperAdmin(etablissementId), [etablissementId])

  // Pas d'identifiant exploitable dans l'URL (accès direct malformé) : mieux
  // vaut revenir à la liste que de lancer un appel voué à échouer (sur
  // /api/etablissements/NaN/identite).
  if (!id || Number.isNaN(etablissementId)) {
    return <Navigate to="/etablissements" replace />
  }

  return (
    <div className="mx-auto max-w-2xl pb-4">
      <div className="mb-4 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <BoutonRetour vers={`/etablissements/${etablissementId}`} />
          <h1 className="text-titre-page font-semibold text-texte">Identité de l'établissement</h1>
        </div>
        {etat?.estPremierRemplissage && (
          <Link
            to="/etablissements"
            className="flex h-11 shrink-0 cursor-pointer items-center rounded border border-bordure px-3 text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
          >
            Remplir plus tard
          </Link>
        )}
      </div>

      {motDePasseGenere && (
        <div className="mb-4">
          <BandeauMotDePasseGenere motDePasse={motDePasseGenere} onFermer={() => setMotDePasseGenere(null)} />
        </div>
      )}

      <FormulaireIdentiteEtablissement service={service} />
    </div>
  )
}
