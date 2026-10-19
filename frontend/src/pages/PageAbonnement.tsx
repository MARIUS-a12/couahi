/**
 * Écran /admin/abonnement (Étape 12) — chemin commerçant, son propre
 * établissement, gerer_parametres exigé côté API. Toujours accessible même
 * boutique suspendue : c'est ici qu'on régularise. Le paiement en ligne
 * n'existe pas encore : "Payer mon abonnement" explique comment payer
 * autrement, avec des coordonnées qui viennent de la configuration du
 * serveur, jamais du code.
 */
import { useQuery } from '@tanstack/react-query'
import { Wallet } from 'lucide-react'
import { useState } from 'react'
import type { DetailAbonnement } from '../api/abonnement'
import { recupererMonAbonnement } from '../api/abonnement'
import { BadgeStatutAbonnement } from '../components/BadgeStatutAbonnement'
import { Bouton } from '../components/Bouton'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { HistoriquePaiementsAbonnement } from '../components/HistoriquePaiementsAbonnement'
import { PanneauMessage } from '../components/PanneauMessage'
import { phraseEtatAbonnement } from '../lib/abonnement'
import { formaterMontant } from '../lib/formatage'

export function PageAbonnement() {
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: ['mon-abonnement'],
    queryFn: recupererMonAbonnement,
  })
  const [panneauOuvert, setPanneauOuvert] = useState(false)

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <EnteteDePage
        icone={<Wallet size={24} strokeWidth={1.75} />}
        couleur="vert"
        titre="Abonnement"
        sousTitre="L'abonnement mensuel qui garde votre boutique en ligne."
      />

      {isPending && <EtatChargement />}

      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {data && (
        <>
          <section className="animate-entree-carte rounded-lg border border-bordure bg-surface p-4 shadow-carte sm:p-6">
            <BadgeStatutAbonnement statut={data.abonnement.statut} />
            <p className="mt-3 font-titre text-titre-section font-semibold text-texte">
              {phraseEtatAbonnement(data.abonnement)}
            </p>
            <div className="mt-4 flex flex-col gap-4 border-t border-bordure pt-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="text-petit text-texte-secondaire">Montant mensuel</p>
                <p className="text-titre-section font-bold text-texte tabular-nums">
                  {formaterMontant(data.abonnement.montant_mensuel)}
                </p>
              </div>
              <Bouton variante="principal" onClick={() => setPanneauOuvert(true)}>
                Payer mon abonnement
              </Bouton>
            </div>
          </section>

          <section className="rounded-lg border border-bordure bg-surface">
            <h2 className="border-b border-bordure px-4 py-3 text-titre-section font-semibold text-texte">
              Historique des paiements
            </h2>
            <HistoriquePaiementsAbonnement paiements={data.paiements} />
          </section>

          {panneauOuvert && (
            <PanneauMessage
              titre="Payer mon abonnement"
              message={<MessageReglement detail={data} />}
              onFermer={() => setPanneauOuvert(false)}
            />
          )}
        </>
      )}
    </div>
  )
}

function MessageReglement({ detail }: { detail: DetailAbonnement }) {
  const { numero_mobile_money: numero, beneficiaire } = detail.reglement
  const montant = formaterMontant(detail.abonnement.montant_mensuel)

  if (!numero) {
    return (
      <>
        Le paiement en ligne de l'abonnement arrive bientôt. Le numéro de règlement n'est pas encore renseigné :
        contactez l'administrateur de la plateforme pour régler vos {montant}.
      </>
    )
  }

  return (
    <>
      Le paiement en ligne de l'abonnement arrive bientôt. En attendant, envoyez{' '}
      <strong className="text-texte">{montant}</strong> par Mobile Money au{' '}
      <strong className="text-texte tabular-nums">{numero}</strong>
      {beneficiaire ? ` (${beneficiaire})` : ''}, en indiquant le nom de votre boutique. Votre paiement est
      enregistré dès sa réception : si votre boutique était hors ligne, elle revient immédiatement, sans rien
      avoir perdu.
    </>
  )
}
