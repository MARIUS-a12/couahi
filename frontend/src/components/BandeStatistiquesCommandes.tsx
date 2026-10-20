/**
 * Quatre cartes statistiques de la liste des commandes (Étape 9), toutes
 * réelles (voir useStatistiquesCommandes / CommandeController::statistiques
 * côté API) — même motif visuel que BandeStatistiques (produits).
 */
import { AlertTriangle, CalendarDays, ShoppingBag, Wallet } from 'lucide-react'
import type { ComponentType } from 'react'
import { useStatistiquesCommandes } from '../hooks/useStatistiquesCommandes'
import { formaterMontant } from '../lib/formatage'

type CouleurCarte = 'vert' | 'rouge' | 'bleu' | 'violet'

const CLASSES_CARRE: Record<CouleurCarte, string> = {
  vert: 'bg-primaire',
  rouge: 'bg-danger',
  bleu: 'bg-bleu-info',
  violet: 'bg-violet',
}

export function BandeStatistiquesCommandes() {
  const { data, isPending } = useStatistiquesCommandes(true)

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <CarteStatistique
        icone={CalendarDays}
        couleur="bleu"
        libelle="Commandes du jour"
        valeur={data?.commandes_du_jour}
        contexte="Toutes commandes confondues"
        chargement={isPending}
      />
      <CarteStatistique
        icone={AlertTriangle}
        couleur="rouge"
        libelle="En attente de traitement"
        valeur={data?.en_attente}
        contexte={data && data.en_attente > 0 ? 'À traiter' : 'Rien à traiter'}
        // Étape 13 : les commandes déjà PAYÉES se lisent sans ouvrir la
        // liste — le commerçant doit de la marchandise à ces clients-là.
        alerte={
          data && data.en_attente_payees > 0
            ? `dont ${data.en_attente_payees} déjà payée${data.en_attente_payees > 1 ? 's' : ''} — à traiter en priorité`
            : undefined
        }
        chargement={isPending}
      />
      <CarteStatistique
        icone={Wallet}
        couleur="vert"
        libelle="Chiffre d'affaires du jour"
        valeur={data ? formaterMontant(data.chiffre_affaires_jour) : undefined}
        contexte="Commandes confirmées uniquement"
        chargement={isPending}
      />
      <CarteStatistique
        icone={ShoppingBag}
        couleur="violet"
        libelle="Panier moyen du mois"
        valeur={data ? formaterMontant(data.panier_moyen_mois) : undefined}
        contexte="Commandes confirmées uniquement"
        chargement={isPending}
      />
    </div>
  )
}

function CarteStatistique({
  icone: Icone,
  couleur,
  libelle,
  valeur,
  contexte,
  alerte,
  chargement,
}: {
  icone: ComponentType<{ 'aria-hidden'?: boolean; size?: number; strokeWidth?: number }>
  couleur: CouleurCarte
  libelle: string
  valeur: number | string | undefined
  contexte: string
  alerte?: string
  chargement: boolean
}) {
  return (
    <div className="rounded-lg border border-bordure bg-surface p-4 shadow-carte">
      <span
        aria-hidden="true"
        className={`flex h-10 w-10 items-center justify-center rounded-lg text-white ${CLASSES_CARRE[couleur]}`}
      >
        <Icone size={20} strokeWidth={1.75} />
      </span>
      <div className="mt-3 text-titre-page font-bold tabular-nums text-texte">{chargement ? '—' : valeur}</div>
      <div className="text-corps font-medium text-texte">{libelle}</div>
      <div className="text-petit text-texte-secondaire">{contexte}</div>
      {alerte && (
        <div className="mt-2 animate-entree-champ rounded-md bg-danger/10 px-2 py-1 text-petit font-semibold text-danger">
          {alerte}
        </div>
      )}
    </div>
  )
}
