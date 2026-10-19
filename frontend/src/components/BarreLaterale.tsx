/**
 * Barre latérale du back-office (Étape 8, maquette de référence) : 260px
 * fixes, fond bleu marine, sur grand écran. Sous 1024px, devient un panneau
 * coulissant (translate-x, 200ms) ouvert par le bouton de BarreSuperieure,
 * avec un fond assombri derrière — fermeture au clic extérieur, à Échap, et
 * après sélection d'une entrée (voir onFermerMobile). N'affiche QUE les
 * pages qui existent réellement, jamais une entrée morte pour une page
 * absente (Clients, Rapports...) : chaque entrée se filtre par PERMISSION
 * (ENTREES[].permissions), jamais par nom de rôle — un rôle ajouté en base
 * (Étape 10, voir RolesEtPermissionsSeeder) apparaît ou disparaît des
 * entrées existantes sans toucher à ce fichier.
 *
 * La pastille de Commandes (nombre en attente de traitement) et la carte de
 * statut en bas sont toutes deux RÉELLES — respectivement
 * useStatistiquesCommandes (Étape 9) et moi.etablissement.statut (voir
 * SessionController::reponseMoi côté API) — jamais une valeur codée en dur.
 * Le libellé d'espace ("Espace Administrateur"...) vient lui aussi de la
 * base (moi.role_libelle_espace), jamais d'une liste ici.
 */
import { FolderTree, Package, ShoppingCart, Store, Users } from 'lucide-react'
import { useEffect } from 'react'
import { NavLink } from 'react-router-dom'
import type { Moi } from '../api/auth'
import { useStatistiquesCommandes } from '../hooks/useStatistiquesCommandes'

const ENTREES = [
  { vers: '/admin/produits', libelle: 'Produits', icone: Package, permissions: ['voir_catalogue', 'gerer_catalogue'] },
  { vers: '/admin/commandes', libelle: 'Commandes', icone: ShoppingCart, permissions: ['voir_commandes', 'gerer_commandes'] },
  { vers: '/admin/categories', libelle: 'Catégories', icone: FolderTree, permissions: ['voir_catalogue', 'gerer_catalogue'] },
  // Étape 10 : réservée à gerer_equipe (en pratique le seul admin_etablissement).
  { vers: '/admin/equipe', libelle: 'Équipe', icone: Users, permissions: ['gerer_equipe'] },
]

export function BarreLaterale({
  moi,
  ouvertMobile,
  onFermerMobile,
}: {
  moi: Moi
  ouvertMobile: boolean
  onFermerMobile: () => void
}) {
  const peutVoirCommandes = moi.permissions.includes('voir_commandes') || moi.permissions.includes('gerer_commandes')
  const { data: statistiquesCommandes } = useStatistiquesCommandes(peutVoirCommandes)

  useEffect(() => {
    if (!ouvertMobile) {
      return
    }

    function surEchap(evenement: KeyboardEvent) {
      if (evenement.key === 'Escape') {
        onFermerMobile()
      }
    }

    document.addEventListener('keydown', surEchap)
    return () => document.removeEventListener('keydown', surEchap)
  }, [ouvertMobile, onFermerMobile])

  const nomEtablissement = moi.etablissement?.nom ?? 'Back-office'
  // Étape 12 : un abonnement suspendu coupe la vitrine au même titre
  // qu'une suspension décidée par le super-admin — la carte doit le dire.
  const enLigne = moi.etablissement?.statut === 'actif' && moi.abonnement?.statut !== 'suspendu'

  return (
    <>
      {ouvertMobile && (
        <div
          role="presentation"
          onClick={onFermerMobile}
          className="fixed inset-0 z-30 animate-entree-fondu bg-texte/40 lg:hidden"
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col bg-marine transition-transform duration-normale ease-apparition lg:translate-x-0 ${
          ouvertMobile ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex items-center gap-3 border-b border-white/10 p-4">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-orange">
            <Store aria-hidden="true" size={22} strokeWidth={1.75} className="text-white" />
          </span>
          <div className="min-w-0">
            <p className="truncate font-titre text-corps font-semibold text-white">{nomEtablissement}</p>
            <p className="truncate text-petit text-white/60">Espace {moi.role_libelle_espace ?? 'back-office'}</p>
          </div>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          {ENTREES.filter((entree) => entree.permissions.some((permission) => moi.permissions.includes(permission))).map(
            (entree) => {
              const pastille = entree.vers === '/admin/commandes' ? statistiquesCommandes?.en_attente : undefined

              return (
                <NavLink
                  key={entree.vers}
                  to={entree.vers}
                  onClick={onFermerMobile}
                  className={({ isActive }) =>
                    `flex h-11 items-center gap-3 rounded-lg px-3 text-corps font-medium transition-colors ${
                      isActive ? 'bg-orange text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'
                    }`
                  }
                >
                  <entree.icone aria-hidden="true" size={20} strokeWidth={1.75} />
                  <span className="flex-1">{entree.libelle}</span>
                  {!!pastille && (
                    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-[11px] font-semibold tabular-nums text-white">
                      {pastille}
                    </span>
                  )}
                </NavLink>
              )
            },
          )}
        </nav>

        <div className="m-3 rounded-lg border border-white/10 bg-white/5 p-3">
          <p className="flex items-center gap-1.5 text-petit font-medium text-white">
            <span
              aria-hidden="true"
              className={`h-2 w-2 rounded-full ${enLigne ? 'bg-succes' : 'bg-danger'}`}
            />
            {enLigne ? 'Boutique en ligne' : 'Boutique suspendue'}
          </p>
          <p className="mt-0.5 text-petit text-white/60">
            {enLigne ? 'Tout fonctionne correctement.' : 'Le site public est inaccessible.'}
          </p>
        </div>
      </aside>
    </>
  )
}
