/**
 * Écran /etablissements/{id} — informations, domaines, utilisateurs
 * rattachés, abonnement (Étape 12), suspension/réactivation et suppression
 * définitive (Étape 7).
 * Révèle aussi, une seule fois, le mot de passe généré si on y arrive juste
 * après une création (voir PageFormulaireEtablissement) — jamais
 * récupérable après ce premier affichage.
 */
import axios from 'axios'
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { reactiverEtablissement, supprimerEtablissement, suspendreEtablissement } from '../api/etablissements'
import { BandeauMotDePasseGenere } from '../components/BandeauMotDePasseGenere'
import { BandeauSucces } from '../components/BandeauSucces'
import { Bouton } from '../components/Bouton'
import { BoutonRetour } from '../components/BoutonRetour'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { PanneauSuppressionEtablissement } from '../components/PanneauSuppressionEtablissement'
import { SectionAbonnementSuperAdmin } from '../components/SectionAbonnementSuperAdmin'
import { useEtablissement } from '../hooks/useEtablissement'

export function PageFicheEtablissement() {
  const { id } = useParams()
  const etablissementId = Number(id)
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [motDePasseGenere, setMotDePasseGenere] = useState<string | null>(
    () => (location.state as { motDePasseGenere?: string } | null)?.motDePasseGenere ?? null,
  )
  const [messageSucces, setMessageSucces] = useState<string | null>(
    () => (location.state as { messageSucces?: string } | null)?.messageSucces ?? null,
  )

  function fermerBandeaux() {
    setMotDePasseGenere(null)
    setMessageSucces(null)
    navigate(location.pathname, { replace: true, state: null })
  }

  const { data: etablissement, isPending, isError, error, refetch } = useEtablissement(etablissementId)

  function appliquerMiseAJour(miseAJour: NonNullable<typeof etablissement>) {
    queryClient.setQueryData(['etablissement', etablissementId], miseAJour)
    queryClient.invalidateQueries({ queryKey: ['etablissements'] })
  }

  const suspension = useMutation({
    mutationFn: () => suspendreEtablissement(etablissementId),
    onSuccess: appliquerMiseAJour,
  })

  const reactivation = useMutation({
    mutationFn: () => reactiverEtablissement(etablissementId),
    onSuccess: appliquerMiseAJour,
  })

  function demanderSuspension() {
    const confirme = window.confirm(
      'Suspendre cet établissement rend son site public inaccessible et empêche ses utilisateurs de ' +
        's\'y connecter. Rien ne sera perdu, et vous pourrez le réactiver à tout moment.\n\n' +
        'Suspendre cet établissement ?',
    )

    if (confirme) {
      suspension.mutate()
    }
  }

  const [panneauSuppressionOuvert, setPanneauSuppressionOuvert] = useState(false)
  const [erreurSuppression, setErreurSuppression] = useState<string | null>(null)

  const suppression = useMutation({
    mutationFn: (saisie: string) => supprimerEtablissement(etablissementId, saisie),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['etablissements'] })
      navigate('/etablissements', { replace: true, state: { messageSucces: `${etablissement?.nom} a été supprimé.` } })
    },
    onError: (erreur) => {
      if (axios.isAxiosError(erreur) && erreur.response?.status === 422) {
        const messageApi = erreur.response.data?.message
        setErreurSuppression(
          typeof messageApi === 'string' ? messageApi : 'Le nom saisi ne correspond pas.',
        )
        return
      }
      setErreurSuppression('Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.')
    },
  })

  function ouvrirPanneauSuppression() {
    setErreurSuppression(null)
    setPanneauSuppressionOuvert(true)
  }

  if (isPending) {
    return (
      <div className="mx-auto max-w-2xl">
        <BoutonRetour vers="/etablissements" />
        <EtatChargement />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="mx-auto max-w-2xl">
        <BoutonRetour vers="/etablissements" />
        <EtatErreur erreur={error} onReessayer={() => refetch()} />
      </div>
    )
  }

  const estActif = etablissement.statut === 'actif'

  return (
    <div className="mx-auto max-w-2xl space-y-6 pb-4">
      <div className="flex items-center gap-2">
        <BoutonRetour vers="/etablissements" />
        <h1 className="text-titre-page font-semibold text-texte">{etablissement.nom}</h1>
      </div>

      {motDePasseGenere && (
        <BandeauMotDePasseGenere motDePasse={motDePasseGenere} onFermer={fermerBandeaux} />
      )}
      {!motDePasseGenere && messageSucces && (
        <BandeauSucces message={messageSucces} onFermer={fermerBandeaux} />
      )}

      <section className="rounded-lg border border-bordure bg-surface">
        <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">
          Informations
        </h2>
        <dl className="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
          <Info libelle="Type" valeur={etablissement.type === 'restaurant' ? 'Restaurant' : 'Boutique'} />
          <Info libelle="Statut" valeur={estActif ? 'Actif' : 'Inactif'} />
          <Info libelle="Email" valeur={etablissement.email ?? '—'} />
          <Info libelle="Téléphone" valeur={etablissement.telephone ?? '—'} />
          <div>
            <dt className="text-petit text-texte-secondaire">Couleur de la vitrine</dt>
            <dd className="flex items-center gap-2 text-corps text-texte">
              <span
                aria-hidden="true"
                className="h-5 w-5 shrink-0 rounded border border-bordure"
                style={{ backgroundColor: etablissement.couleur_accent ?? '#146c43' }}
              />
              {(etablissement.couleur_accent ?? '#146c43').toUpperCase()}
            </dd>
          </div>
          <Info libelle="Créé le" valeur={new Date(etablissement.created_at).toLocaleDateString('fr-FR')} />
        </dl>
      </section>

      <SectionAbonnementSuperAdmin etablissementId={etablissementId} />

      <section className="rounded-lg border border-bordure bg-surface">
        <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">
          Domaines
        </h2>
        <ul className="divide-y divide-bordure">
          {etablissement.domaines.map((domaine) => (
            <li key={domaine.id} className="flex items-center justify-between p-4 text-corps text-texte">
              <span>{domaine.hote}</span>
              <span className="text-petit text-texte-secondaire">
                {domaine.est_principal ? 'Principal' : 'Secondaire'}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section className="rounded-lg border border-bordure bg-surface">
        <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">
          Utilisateurs rattachés
        </h2>
        <ul className="divide-y divide-bordure">
          {etablissement.utilisateurs.map((utilisateur) => (
            <li
              key={utilisateur.id}
              className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <span className="text-corps font-medium text-texte">{utilisateur.nom}</span>
                <span className="ml-2 text-petit text-texte-secondaire">{utilisateur.email}</span>
              </div>
              <span className="text-petit text-texte-secondaire">{utilisateur.role}</span>
            </li>
          ))}
          {etablissement.utilisateurs.length === 0 && (
            <li className="p-4 text-corps text-texte-secondaire">Aucun utilisateur rattaché.</li>
          )}
        </ul>
      </section>

      <div className="flex flex-wrap gap-3">
        <Bouton variante="secondaire" onClick={() => navigate(`/etablissements/${etablissementId}/identite`)}>
          Modifier l'identité
        </Bouton>
        <Bouton variante="secondaire" onClick={() => navigate(`/etablissements/${etablissementId}/paiement`)}>
          Paiement
        </Bouton>
        {estActif ? (
          <Bouton variante="danger" onClick={demanderSuspension} chargement={suspension.isPending}>
            Suspendre cet établissement
          </Bouton>
        ) : (
          <Bouton variante="principal" onClick={() => reactivation.mutate()} chargement={reactivation.isPending}>
            Réactiver cet établissement
          </Bouton>
        )}
      </div>

      <section className="rounded-lg border border-bordure-forte bg-surface">
        <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">
          Zone de suppression
        </h2>
        <div className="p-4">
          {etablissement.nombre_commandes > 0 ? (
            <p className="text-corps text-texte-secondaire">
              On ne détruit jamais un historique de ventes : cet établissement a{' '}
              {etablissement.nombre_commandes === 1 ? '1 commande' : `${etablissement.nombre_commandes} commandes`}{' '}
              et ne peut pas être supprimé définitivement. Suspendez-le si besoin.
            </p>
          ) : (
            <>
              <p className="text-corps text-texte-secondaire">
                Aucune commande n'existe pour cet établissement : il peut être supprimé définitivement. Cette
                action est irréversible.
              </p>
              <Bouton variante="danger" className="mt-3" onClick={ouvrirPanneauSuppression}>
                Supprimer définitivement
              </Bouton>
            </>
          )}
        </div>
      </section>

      {panneauSuppressionOuvert && (
        <PanneauSuppressionEtablissement
          nomEtablissement={etablissement.nom}
          enCours={suppression.isPending}
          erreur={erreurSuppression}
          onConfirmer={(saisie) => suppression.mutate(saisie)}
          onFermer={() => setPanneauSuppressionOuvert(false)}
        />
      )}
    </div>
  )
}

function Info({ libelle, valeur }: { libelle: string; valeur: string }) {
  return (
    <div>
      <dt className="text-petit text-texte-secondaire">{libelle}</dt>
      <dd className="text-corps text-texte">{valeur}</dd>
    </div>
  )
}
