/**
 * Écran /admin/commandes/:id (Étape 9) — l'outil de travail principal du
 * commerçant ivoirien : coordonnées client avec bouton WhatsApp direct,
 * lignes de commande, historique complet, et les actions de changement de
 * statut. Le serveur revalide chaque transition (voir CommandeController) :
 * les boutons impossibles n'apparaissent pas ici, mais ce n'est qu'un
 * confort d'affichage, jamais la seule barrière.
 *
 * Étape 13 : le paiement (Mobile Money) a sa propre section,
 * SectionPaiementCommande, indépendante des actions de statut.
 */
import axios from 'axios'
import { ImageOff, MapPin, MessageCircle, Package, Wallet } from 'lucide-react'
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import type { MotifAnnulation } from '../api/commandes'
import { annulerCommande, confirmerCommande, marquerCommandeLivree, marquerCommandePrete } from '../api/commandes'
import { BadgeStatutCommande } from '../components/BadgeStatutCommande'
import { Bouton } from '../components/Bouton'
import { BoutonRetour } from '../components/BoutonRetour'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { IconeCanal } from '../components/IconeCanal'
import { PanneauAnnulationCommande } from '../components/PanneauAnnulationCommande'
import { PastillePaiement } from '../components/PastillePaiement'
import { SectionPaiementCommande } from '../components/SectionPaiementCommande'
import { useCommande } from '../hooks/useCommande'
import { useMoi } from '../hooks/useMoi'
import { formaterMontant } from '../lib/formatage'

function formaterDateHeure(date: string): string {
  return new Date(date).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function lienWhatsapp(telephone: string, numero: string, commune: string | null, quartier: string | null): string {
  const adresse = [commune, quartier].filter(Boolean).join(', ')
  const message = adresse
    ? `Bonjour, à propos de votre commande ${numero}, à livrer à ${adresse}.`
    : `Bonjour, à propos de votre commande ${numero}.`

  return `https://wa.me/${telephone.replace('+', '')}?text=${encodeURIComponent(message)}`
}

export function PageDetailCommande() {
  const { id } = useParams()
  const commandeId = Number(id)
  const queryClient = useQueryClient()
  const { data: moi } = useMoi()
  const peutGererCommandes = moi?.permissions.includes('gerer_commandes') ?? false

  const { data: commande, isPending, isError, error, refetch } = useCommande(commandeId)
  const [panneauAnnulationOuvert, setPanneauAnnulationOuvert] = useState(false)
  const [erreurAnnulation, setErreurAnnulation] = useState<string | null>(null)
  const [erreurTransition, setErreurTransition] = useState<string | null>(null)

  function appliquerMiseAJour(miseAJour: NonNullable<typeof commande>) {
    queryClient.setQueryData(['commande', commandeId], miseAJour)
    queryClient.invalidateQueries({ queryKey: ['commandes'] })
    queryClient.invalidateQueries({ queryKey: ['commandes-statistiques'] })
  }

  function messageErreurTransition(erreur: unknown): string {
    if (axios.isAxiosError(erreur) && erreur.response?.status === 422) {
      const message = erreur.response.data?.message
      if (typeof message === 'string') {
        return message
      }
    }
    return 'Impossible de contacter le serveur. Vérifiez votre connexion et réessayez.'
  }

  const confirmation = useMutation({
    mutationFn: () => confirmerCommande(commandeId),
    onSuccess: appliquerMiseAJour,
    onError: (erreur) => setErreurTransition(messageErreurTransition(erreur)),
  })

  const miseEnPreparation = useMutation({
    mutationFn: () => marquerCommandePrete(commandeId),
    onSuccess: appliquerMiseAJour,
    onError: (erreur) => setErreurTransition(messageErreurTransition(erreur)),
  })

  const livraison = useMutation({
    mutationFn: () => marquerCommandeLivree(commandeId),
    onSuccess: appliquerMiseAJour,
    onError: (erreur) => setErreurTransition(messageErreurTransition(erreur)),
  })

  const annulation = useMutation({
    mutationFn: (motif: MotifAnnulation) => annulerCommande(commandeId, motif),
    onSuccess: (miseAJour) => {
      appliquerMiseAJour(miseAJour)
      setPanneauAnnulationOuvert(false)
    },
    onError: (erreur) => setErreurAnnulation(messageErreurTransition(erreur)),
  })

  function ouvrirPanneauAnnulation() {
    setErreurAnnulation(null)
    setPanneauAnnulationOuvert(true)
  }

  if (isPending) {
    return (
      <div className="mx-auto max-w-3xl">
        <BoutonRetour vers="/admin/commandes" />
        <EtatChargement />
      </div>
    )
  }

  if (isError || !commande) {
    return (
      <div className="mx-auto max-w-3xl">
        <BoutonRetour vers="/admin/commandes" />
        <EtatErreur erreur={error} onReessayer={() => refetch()} />
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6 pb-4">
      <div className="flex flex-wrap items-center gap-3">
        <BoutonRetour vers="/admin/commandes" />
        <h1 className="font-titre text-titre-page font-bold text-texte">{commande.numero}</h1>
        <BadgeStatutCommande statut={commande.statut} />
        {commande.mode_paiement === 'mobile_money' && <PastillePaiement commande={commande} />}
      </div>
      <p className="-mt-4 text-petit text-texte-secondaire">{formaterDateHeure(commande.created_at)}</p>

      {commande.en_retard && (
        <p className="rounded-md bg-danger/10 px-3 py-2 text-corps font-medium text-danger">
          En attente depuis plus de 2 heures — un client qui attend est un client qui part.
        </p>
      )}

      <section className="rounded-lg border border-bordure bg-surface p-4 sm:p-5">
        <h2 className="text-titre-section font-semibold text-texte">Client</h2>
        <div className="mt-2 flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-corps font-medium text-texte">{commande.client?.nom}</p>
            <p className="tabular-nums text-corps text-texte-secondaire">{commande.client?.telephone}</p>
          </div>
          {commande.client && (
            <a
              href={lienWhatsapp(commande.client.telephone, commande.numero, commande.commune, commande.quartier)}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border-2 border-primaire px-4 text-corps font-medium text-primaire transition-colors hover:bg-primaire/10 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
            >
              <MessageCircle aria-hidden="true" size={20} strokeWidth={1.75} />
              Contacter sur WhatsApp
            </a>
          )}
        </div>

        {(commande.commune || commande.quartier) && (
          <div className="mt-3 flex items-start gap-2 rounded-md bg-surface-alt p-3">
            <MapPin aria-hidden="true" size={18} strokeWidth={1.75} className="mt-0.5 shrink-0 text-texte-secondaire" />
            <div className="text-corps text-texte">
              {commande.commune && <p className="font-semibold">{commande.commune}</p>}
              {commande.quartier && <p className="text-texte-secondaire">{commande.quartier}</p>}
            </div>
          </div>
        )}

        <div className="mt-3">
          <IconeCanal canal={commande.canal} />
        </div>
      </section>

      {commande.note && (
        <section className="rounded-lg border border-bordure-forte bg-surface-alt p-4">
          <h2 className="text-petit font-semibold text-texte">Note du client</h2>
          <p className="mt-1 whitespace-pre-line text-corps text-texte">{commande.note}</p>
        </section>
      )}

      <section className="rounded-lg border border-bordure bg-surface">
        <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">Articles</h2>
        <ul className="divide-y divide-bordure">
          {commande.lignes.map((ligne) => (
            <li key={ligne.id} className="flex items-center gap-3 p-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-surface-alt">
                {ligne.photo?.webp || ligne.photo?.jpg ? (
                  <picture>
                    {ligne.photo.webp && <source srcSet={ligne.photo.webp} type="image/webp" />}
                    <img src={ligne.photo.jpg ?? ligne.photo.webp ?? undefined} alt="" className="h-full w-full object-cover" />
                  </picture>
                ) : (
                  <ImageOff aria-hidden="true" size={20} strokeWidth={1.5} className="text-texte-secondaire" />
                )}
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-corps font-medium text-texte">{ligne.nom}</p>
                {ligne.variante && <p className="text-petit text-texte-secondaire">{ligne.variante}</p>}
                <p className="tabular-nums text-petit text-texte-secondaire">
                  {ligne.quantite} × {formaterMontant(ligne.prix_unitaire)}
                </p>
              </div>
              <span className="tabular-nums text-corps font-semibold text-texte">{formaterMontant(ligne.total)}</span>
            </li>
          ))}
        </ul>
        <dl className="space-y-1.5 border-t border-bordure p-4 text-corps">
          <div className="flex justify-between text-texte-secondaire">
            <dt>Sous-total</dt>
            <dd className="tabular-nums">{formaterMontant(commande.sous_total)}</dd>
          </div>
          <div className="flex justify-between text-texte-secondaire">
            <dt>Livraison{commande.zone_livraison ? ` — ${commande.zone_livraison.nom}` : ''}</dt>
            <dd className="tabular-nums">{formaterMontant(commande.frais_livraison)}</dd>
          </div>
          <div className="flex justify-between text-titre-section font-semibold text-texte">
            <dt>Total</dt>
            <dd className="tabular-nums">{formaterMontant(commande.total)}</dd>
          </div>
        </dl>
      </section>

      <SectionPaiementCommande commande={commande} peutGerer={peutGererCommandes} onMiseAJour={appliquerMiseAJour} />

      {peutGererCommandes && (
        <section className="rounded-lg border border-bordure bg-surface p-4 sm:p-5">
          <h2 className="text-titre-section font-semibold text-texte">Actions</h2>
          {erreurTransition && (
            <p role="alert" className="animate-entree-champ mt-2 text-petit text-danger">
              {erreurTransition}
            </p>
          )}
          <div className="mt-3 flex flex-wrap gap-3">
            {commande.statut === 'attente_paiement' && (
              <Bouton variante="principal" onClick={() => confirmation.mutate()} chargement={confirmation.isPending}>
                Confirmer la commande
              </Bouton>
            )}
            {commande.statut === 'payee' && (
              <Bouton variante="principal" onClick={() => miseEnPreparation.mutate()} chargement={miseEnPreparation.isPending}>
                Marquer comme prête
              </Bouton>
            )}
            {commande.statut === 'prete' && (
              <Bouton variante="principal" onClick={() => livraison.mutate()} chargement={livraison.isPending}>
                Marquer comme livrée
              </Bouton>
            )}
            {['attente_paiement', 'payee', 'prete'].includes(commande.statut) && (
              <Bouton variante="danger" onClick={ouvrirPanneauAnnulation}>
                Annuler la commande
              </Bouton>
            )}
          </div>
        </section>
      )}

      <section className="rounded-lg border border-bordure bg-surface">
        <h2 className="border-b border-bordure px-4 py-2 text-titre-section font-semibold text-texte">Historique</h2>
        <ul className="divide-y divide-bordure">
          {commande.historique.map((entree, index) => (
            <li key={index} className="flex items-start gap-3 p-4">
              {entree.evenement === 'paiement_confirme' ? (
                <Wallet aria-hidden="true" size={18} strokeWidth={1.75} className="mt-0.5 shrink-0 text-succes" />
              ) : (
                <Package aria-hidden="true" size={18} strokeWidth={1.75} className="mt-0.5 shrink-0 text-texte-secondaire" />
              )}
              <div className="min-w-0 flex-1">
                <p className="text-corps text-texte">
                  {/* Étape 13 : une confirmation de paiement n'est pas un
                      changement de statut — jamais "De X à X". */}
                  {entree.evenement === 'paiement_confirme'
                    ? 'Paiement confirmé'
                    : entree.ancien_statut
                      ? `De « ${entree.ancien_statut} » à « ${entree.nouveau_statut} »`
                      : entree.nouveau_statut}
                </p>
                <p className="text-petit text-texte-secondaire">
                  Par {entree.utilisateur ?? 'le système'}, le {formaterDateHeure(entree.date)}
                </p>
                {entree.motif && <p className="mt-0.5 text-petit text-texte">{entree.motif}</p>}
              </div>
            </li>
          ))}
        </ul>
      </section>

      {panneauAnnulationOuvert && (
        <PanneauAnnulationCommande
          numero={commande.numero}
          montantPaye={commande.paiement.confirme ? commande.paiement.montant_recu : null}
          enCours={annulation.isPending}
          erreur={erreurAnnulation}
          onConfirmer={(motif) => annulation.mutate(motif)}
          onFermer={() => setPanneauAnnulationOuvert(false)}
        />
      )}
    </div>
  )
}
