/**
 * Écran /admin/commandes (Étape 9) — le trou le plus grave comblé : jusqu'à
 * cette étape, une commande arrivait en base et personne ne le savait.
 * Quatre cartes statistiques réelles, filtres par statut dans l'ordre du
 * cycle de vie, recherche par numéro ou téléphone, tableau trié du plus
 * récent au plus ancien, pagination à 25. Cartes empilées sous 768px, comme
 * pour les produits. Rafraîchi toutes les 60 secondes (voir useCommandes).
 */
import { PackageSearch, Plus, Search, ShoppingCart } from 'lucide-react'
import { useState } from 'react'
import { BadgeStatutCommande } from '../components/BadgeStatutCommande'
import { PastillePaiement } from '../components/PastillePaiement'
import { BandeStatistiquesCommandes } from '../components/BandeStatistiquesCommandes'
import { Bouton } from '../components/Bouton'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { FiltresStatutCommande } from '../components/FiltresStatutCommande'
import { IconeCanal } from '../components/IconeCanal'
import { useCommandes } from '../hooks/useCommandes'
import { useMoi } from '../hooks/useMoi'
import { useValeurDifferee } from '../hooks/useValeurDifferee'
import { formaterMontant } from '../lib/formatage'
import { useNavigate } from 'react-router-dom'
import type { Commande, StatutCommande } from '../api/commandes'

function formaterDateHeure(date: string): string {
  return new Date(date).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function PageListeCommandes() {
  const [recherche, setRecherche] = useState('')
  const [statut, setStatut] = useState<StatutCommande | ''>('')
  const [page, setPage] = useState(1)
  const navigate = useNavigate()
  const { data: moi } = useMoi()
  const peutSaisir = moi?.permissions.includes('creer_commandes') ?? false

  const rechercheDifferee = useValeurDifferee(recherche)

  const { data, isPending, isError, error, refetch } = useCommandes({
    recherche: rechercheDifferee || undefined,
    statut: statut || undefined,
    page,
  })

  function changerStatut(valeur: StatutCommande | '') {
    setStatut(valeur)
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <EnteteDePage
        icone={<ShoppingCart aria-hidden="true" size={22} strokeWidth={1.75} />}
        couleur="bleu"
        titre="Commandes"
        sousTitre="Toutes les commandes : vitrine, WhatsApp, téléphone et comptoir."
        action={
          peutSaisir ? (
            <Bouton variante="principal" icone={<Plus aria-hidden="true" size={20} strokeWidth={1.5} />} onClick={() => navigate('/admin/commandes/nouvelle')}>
              Nouvelle commande
            </Bouton>
          ) : undefined
        }
      />

      <BandeStatistiquesCommandes />

      <div className="space-y-3">
        <div className="relative max-w-sm">
          <Search
            aria-hidden="true"
            size={20}
            strokeWidth={1.5}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-texte-secondaire"
          />
          <input
            type="search"
            placeholder="Numéro de commande ou téléphone…"
            value={recherche}
            onChange={(evenement) => {
              setRecherche(evenement.target.value)
              setPage(1)
            }}
            className="h-11 w-full rounded-md border border-bordure bg-surface pl-10 pr-3 text-corps text-texte transition-colors focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
          />
        </div>

        <FiltresStatutCommande valeur={statut} onChange={changerStatut} />
      </div>

      {isPending && <EtatChargement />}

      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {!isPending && !isError && data && data.data.length === 0 && (
        <div className="flex flex-col items-center gap-3 rounded-lg border border-bordure bg-surface px-6 py-16 text-center">
          <PackageSearch aria-hidden="true" size={48} strokeWidth={1.5} className="text-texte-secondaire" />
          <h2 className="text-titre-section font-semibold text-texte">Aucune commande ne correspond</h2>
          <p className="max-w-sm text-corps text-texte-secondaire">
            {statut || recherche
              ? 'Essayez un autre filtre ou une autre recherche.'
              : 'Les commandes passées par vos clients apparaîtront ici.'}
          </p>
        </div>
      )}

      {!isPending && !isError && data && data.data.length > 0 && (
        <>
          {/* Mobile : une carte par commande. */}
          <ul className="space-y-3 md:hidden">
            {data.data.map((commande) => (
              <li key={commande.id}>
                <CarteCommande commande={commande} onClick={() => navigate(`/admin/commandes/${commande.id}`)} />
              </li>
            ))}
          </ul>

          {/* Écran large : tableau. */}
          <div className="hidden overflow-hidden rounded-lg border border-bordure bg-surface md:block">
            <table className="w-full border-separate border-spacing-0">
              <thead>
                <tr className="bg-surface-alt text-left text-petit text-texte-secondaire">
                  <th className="border-b border-bordure px-4 py-2 font-medium">Numéro</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Client</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Articles</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Total</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Paiement</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Canal</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Statut</th>
                  <th className="border-b border-bordure px-4 py-2 font-medium">Date</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((commande) => (
                  <tr
                    key={commande.id}
                    onClick={() => navigate(`/admin/commandes/${commande.id}`)}
                    className={`cursor-pointer transition-colors hover:bg-surface-alt ${classeSignalLigne(commande)}`}
                  >
                    <td className="border-b border-bordure px-4 py-3">
                      <span className="text-corps font-bold tabular-nums text-texte">{commande.numero}</span>
                      <SignalLigne commande={commande} />
                    </td>
                    <td className="border-b border-bordure px-4 py-3">
                      <div className="text-corps text-texte">{commande.client === null ? 'Client de passage' : (commande.client?.nom ?? '—')}</div>
                      <div className="text-petit tabular-nums text-texte-secondaire">{commande.client?.telephone}</div>
                    </td>
                    <td className="border-b border-bordure px-4 py-3 tabular-nums text-corps text-texte">
                      {commande.nombre_articles}
                    </td>
                    <td className="border-b border-bordure px-4 py-3 tabular-nums text-corps font-medium text-texte">
                      {formaterMontant(commande.total)}
                    </td>
                    <td className="border-b border-bordure px-4 py-3">
                      <PastillePaiement commande={commande} avecStock />
                    </td>
                    <td className="border-b border-bordure px-4 py-3">
                      <IconeCanal canal={commande.canal} />
                    </td>
                    <td className="border-b border-bordure px-4 py-3">
                      <BadgeStatutCommande statut={commande.statut} />
                    </td>
                    <td className="border-b border-bordure px-4 py-3 tabular-nums text-petit text-texte-secondaire">
                      {formaterDateHeure(commande.created_at)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="flex flex-col items-center justify-between gap-3 pt-1 text-petit text-texte-secondaire sm:flex-row">
            <span className="tabular-nums">
              Affichage de {(data.meta.current_page - 1) * data.meta.per_page + 1} à{' '}
              {Math.min(data.meta.current_page * data.meta.per_page, data.meta.total)} sur {data.meta.total} commandes
            </span>
            <div className="flex items-center gap-2">
              <Bouton variante="secondaire" taille="petite" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Précédent
              </Bouton>
              <span className="flex h-11 min-w-11 items-center justify-center rounded-md bg-marine px-3 text-corps font-medium tabular-nums text-white">
                {data.meta.current_page}
              </span>
              <Bouton
                variante="secondaire"
                taille="petite"
                disabled={page >= data.meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Suivant
              </Bouton>
            </div>
          </div>
        </>
      )}
    </div>
  )
}

function CarteCommande({ commande, onClick }: { commande: Commande; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`w-full rounded-lg border border-bordure bg-surface p-4 text-left transition-colors active:scale-[0.99] ${classeSignalLigne(commande)}`}
    >
      <div className="flex items-start justify-between gap-3">
        <div>
          <span className="block text-corps font-bold tabular-nums text-texte">{commande.numero}</span>
          <span className="text-petit text-texte-secondaire">{commande.client === null ? 'Client de passage' : (commande.client?.nom ?? '—')}</span>
        </div>
        <BadgeStatutCommande statut={commande.statut} />
      </div>

      <div className="mt-2 flex items-center justify-between gap-3">
        <span className="flex items-center gap-2">
          <IconeCanal canal={commande.canal} />
          <PastillePaiement commande={commande} avecStock />
        </span>
        <span className="tabular-nums text-corps font-semibold text-texte">{formaterMontant(commande.total)}</span>
      </div>

      <div className="mt-2 flex items-center justify-between gap-3 text-petit text-texte-secondaire">
        <span>{commande.nombre_articles} article{commande.nombre_articles === 1 ? '' : 's'}</span>
        <span className="tabular-nums">{formaterDateHeure(commande.created_at)}</span>
      </div>

      <SignalLigne commande={commande} enBloc />
    </button>
  )
}

/**
 * Étape 13 — UN seul signal par ligne, le plus urgent : une commande payée
 * mais pas acceptée depuis plus de 2 h passe avant tout le reste (le
 * commerçant doit de la marchandise ou un remboursement à quelqu'un). Une
 * commande payée PARTIELLEMENT et pas encore acceptée a le même rang : le
 * client a versé de l'argent, il faut décider (relancer, accepter, ou
 * annuler et rembourser).
 */
function signalPrioritaire(commande: Commande): boolean {
  return commande.paiement.a_traiter_en_urgence || commande.paiement.partielle_a_decider
}

function classeSignalLigne(commande: Commande): string {
  if (signalPrioritaire(commande)) {
    return 'bg-danger/10 shadow-[inset_4px_0_0_var(--color-danger)]'
  }

  return commande.en_retard || commande.paiement.en_retard ? 'bg-danger/5' : ''
}

function SignalLigne({ commande, enBloc = false }: { commande: Commande; enBloc?: boolean }) {
  const texte = commande.paiement.a_traiter_en_urgence
    ? 'Payée, pas encore traitée depuis +2h'
    : commande.paiement.partielle_a_decider
      ? `Payée partiellement — reste ${formaterMontant(commande.paiement.reste_du)} dû, à décider`
      : commande.en_retard
      ? enBloc
        ? 'En attente depuis plus de 2 heures'
        : 'En attente depuis +2h'
      : null

  if (!texte) {
    return null
  }

  return (
    <span
      className={`${enBloc ? 'mt-2 block' : 'ml-2'} text-petit ${
        signalPrioritaire(commande) ? 'font-bold' : 'font-medium'
      } text-danger`}
    >
      {texte}
    </span>
  )
}
