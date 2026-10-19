/**
 * Écran /etablissements — inventaire de la plateforme, réservé au
 * super-admin (voir RouteProtegee). Recherche, filtre par type et tri en
 * local : le nombre d'établissements reste petit, pas besoin d'un
 * aller-retour serveur par frappe.
 *
 * Étape 12 : statut d'abonnement et échéance sur chaque ligne, tri par
 * échéance (la plus proche en premier), et un indicateur "À relancer"
 * (en retard ou suspendus) qui sert aussi de filtre — savoir qui relancer
 * est la raison d'être de cette colonne.
 */
import { BellRing, Building2, CreditCard, Plus, TriangleAlert } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import type { Etablissement, TypeEtablissement } from '../api/etablissements'
import { BadgeStatutAbonnement } from '../components/BadgeStatutAbonnement'
import { Bouton } from '../components/Bouton'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { GroupeSegmente } from '../components/GroupeSegmente'
import { useEtablissements } from '../hooks/useEtablissements'
import { useValeurDifferee } from '../hooks/useValeurDifferee'
import { formaterDateLongue } from '../lib/abonnement'

const OPTIONS_TYPE: { valeur: TypeEtablissement | ''; libelle: string }[] = [
  { valeur: '', libelle: 'Tous' },
  { valeur: 'boutique', libelle: 'Boutiques' },
  { valeur: 'restaurant', libelle: 'Restaurants' },
]

type Tri = 'nom' | 'echeance'

const OPTIONS_TRI: { valeur: Tri; libelle: string }[] = [
  { valeur: 'nom', libelle: 'Nom' },
  { valeur: 'echeance', libelle: 'Échéance' },
]

function estARelancer(etablissement: Etablissement): boolean {
  return etablissement.abonnement?.statut === 'en_retard' || etablissement.abonnement?.statut === 'suspendu'
}

export function PageListeEtablissements() {
  const [recherche, setRecherche] = useState('')
  const [type, setType] = useState<TypeEtablissement | ''>('')
  const [tri, setTri] = useState<Tri>('nom')
  const [seulementARelancer, setSeulementARelancer] = useState(false)
  const rechercheDifferee = useValeurDifferee(recherche)
  const { data: etablissements, isPending, isError, error, refetch } = useEtablissements()
  const navigate = useNavigate()

  const filtres = useMemo(() => {
    if (!etablissements) {
      return []
    }

    const rechercheNormalisee = rechercheDifferee.trim().toLowerCase()

    const retenus = etablissements.filter((etablissement) => {
      const correspondType = type === '' || etablissement.type === type
      const correspondRecherche =
        rechercheNormalisee === '' || etablissement.nom.toLowerCase().includes(rechercheNormalisee)

      return correspondType && correspondRecherche && (!seulementARelancer || estARelancer(etablissement))
    })

    // Les dates AAAA-MM-JJ se comparent comme des chaînes ; un
    // établissement sans abonnement passe en dernier.
    return tri === 'echeance'
      ? [...retenus].sort((a, b) =>
          (a.abonnement?.date_echeance ?? '9999').localeCompare(b.abonnement?.date_echeance ?? '9999'),
        )
      : retenus
  }, [etablissements, rechercheDifferee, type, tri, seulementARelancer])

  const aRelancer = useMemo(() => (etablissements ?? []).filter(estARelancer), [etablissements])
  const nombreEnRetard = aRelancer.filter((etablissement) => etablissement.abonnement?.statut === 'en_retard').length
  const nombreSuspendus = aRelancer.length - nombreEnRetard

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <EnteteDePage
        icone={<Building2 size={24} strokeWidth={1.75} />}
        couleur="marine"
        titre="Établissements"
        sousTitre="Toutes les boutiques et tous les restaurants de la plateforme."
        action={
          <Bouton
            variante="principal"
            icone={<Plus aria-hidden="true" size={20} strokeWidth={1.5} />}
            onClick={() => navigate('/etablissements/nouveau')}
          >
            Nouvel établissement
          </Bouton>
        }
      />

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <input
          type="search"
          placeholder="Rechercher un établissement…"
          value={recherche}
          onChange={(evenement) => setRecherche(evenement.target.value)}
          className="h-11 w-full rounded-md border border-bordure bg-surface px-3 text-corps text-texte transition-colors focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1 sm:max-w-xs"
        />
        <GroupeSegmente options={OPTIONS_TYPE} valeur={type} onChange={setType} />
        <div className="flex items-center gap-2 sm:ml-auto">
          <span className="text-petit text-texte-secondaire">Trier par</span>
          <GroupeSegmente options={OPTIONS_TRI} valeur={tri} onChange={setTri} />
        </div>
      </div>

      {aRelancer.length > 0 && (
        <button
          type="button"
          onClick={() => setSeulementARelancer((valeur) => !valeur)}
          aria-pressed={seulementARelancer}
          className={`flex w-full animate-entree-haut cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-left text-corps transition-[background-color,transform] duration-rapide ease-apparition active:scale-[0.99] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 ${
            seulementARelancer ? 'border-alerte bg-alerte/15' : 'border-alerte/60 bg-alerte/10 hover:bg-alerte/15'
          }`}
        >
          <BellRing aria-hidden="true" size={20} strokeWidth={1.75} className="shrink-0 text-alerte" />
          <span className="flex-1 text-texte">
            <span className="font-semibold">
              {aRelancer.length} établissement{aRelancer.length > 1 ? 's' : ''} à relancer
            </span>
            <span className="text-texte-secondaire">
              {' '}
              — {nombreEnRetard} en retard, {nombreSuspendus} suspendu{nombreSuspendus > 1 ? 's' : ''}
            </span>
          </span>
          <span className="text-petit font-semibold text-alerte">
            {seulementARelancer ? 'Tout afficher' : 'Afficher seulement eux'}
          </span>
        </button>
      )}

      {isPending && <EtatChargement />}

      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {!isPending && !isError && (
        <ul className="divide-y divide-bordure overflow-hidden rounded-lg border border-bordure bg-surface shadow-carte">
          {filtres.map((etablissement) => (
            <LigneEtablissement key={etablissement.id} etablissement={etablissement} />
          ))}
          {filtres.length === 0 && (
            <li className="p-4 text-center text-corps text-texte-secondaire">
              Aucun établissement ne correspond.
            </li>
          )}
        </ul>
      )}
    </div>
  )
}

const LIBELLES_CHAMPS_MANQUANTS: Record<string, string> = {
  logo: 'le logo',
  couleur: 'la couleur',
  whatsapp: 'le numéro WhatsApp',
  horaires: 'les horaires',
}

function LigneEtablissement({ etablissement }: { etablissement: Etablissement }) {
  const navigate = useNavigate()
  const estActif = etablissement.statut === 'actif'
  const champsManquants = etablissement.identite_champs_manquants

  return (
    <li className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
      <Link
        to={`/etablissements/${etablissement.id}`}
        className="flex-1 transition-colors hover:text-primaire"
      >
        <span className="text-corps font-semibold text-texte">{etablissement.nom}</span>
        <span className="ml-2 text-petit text-texte-secondaire">
          {etablissement.type === 'restaurant' ? 'Restaurant' : 'Boutique'}
        </span>
      </Link>

      <div className="flex flex-wrap items-center gap-3 text-petit text-texte-secondaire">
        <span>{etablissement.sous_domaine ?? '—'}</span>
        <span className="tabular-nums">
          {etablissement.produits_count ?? 0} produit{(etablissement.produits_count ?? 0) === 1 ? '' : 's'}
        </span>
        <span className="tabular-nums">
          {new Date(etablissement.created_at).toLocaleDateString('fr-FR')}
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span
            aria-hidden="true"
            className={`h-1.5 w-1.5 rounded-full ${estActif ? 'bg-succes' : 'bg-bordure'}`}
          />
          {estActif ? 'Actif' : 'Inactif'}
        </span>
        {etablissement.abonnement && (
          <span
            className="inline-flex items-center gap-1.5"
            title={`Échéance de l'abonnement : ${formaterDateLongue(etablissement.abonnement.date_echeance)}`}
          >
            <BadgeStatutAbonnement statut={etablissement.abonnement.statut} />
            <span className="tabular-nums">
              {new Date(`${etablissement.abonnement.date_echeance}T00:00:00Z`).toLocaleDateString('fr-FR', {
                timeZone: 'UTC',
              })}
            </span>
          </span>
        )}
        <span
          title={etablissement.paiement_configure ? 'Paiement configuré' : 'Paiement non configuré'}
          className={`inline-flex items-center gap-1 ${etablissement.paiement_configure ? 'text-succes' : 'text-texte-secondaire'}`}
        >
          <CreditCard aria-hidden="true" size={14} strokeWidth={1.5} />
          {etablissement.paiement_configure ? 'Paiement' : 'Sans paiement'}
        </span>
        {champsManquants.length > 0 && (
          <span
            title={`Il manque : ${champsManquants.map((c) => LIBELLES_CHAMPS_MANQUANTS[c] ?? c).join(', ')}.`}
            className="inline-flex items-center gap-1 text-alerte"
          >
            <TriangleAlert aria-hidden="true" size={14} strokeWidth={1.5} />
            Vitrine incomplète
          </span>
        )}
        <Bouton variante="secondaire" taille="petite" onClick={() => navigate(`/etablissements/${etablissement.id}/identite`)}>
          Modifier l'identité
        </Bouton>
      </div>
    </li>
  )
}
