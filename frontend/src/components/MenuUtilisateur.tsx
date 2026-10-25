/**
 * Menu utilisateur déroulant de l'en-tête back-office (Étape 7) : identité,
 * accès à Mon compte / Identité de la boutique / Paiement / Livraison / Abonnement
 * (masqués sans gerer_parametres), déconnexion. Entièrement roulé à la main — aucune
 * librairie de menu n'est une dépendance du projet (voir PanneauMessage pour
 * le seul autre overlay existant, dont ce composant reprend l'esprit :
 * ARIA posés manuellement, fermeture par Échap/clic extérieur/sélection,
 * focus qui revient sur le déclencheur). Panneau flottant ancré à droite sur
 * grand écran, feuille montant du bas sur mobile — jamais une liste
 * flottante trop étroite pour le pouce.
 */
import { ChevronDown, CreditCard, LogOut, Settings, Truck, User, Wallet } from 'lucide-react'
import type { KeyboardEvent } from 'react'
import { useEffect, useId, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import type { Moi } from '../api/auth'
import { deconnecter } from '../api/auth'
import { CarreInitiale } from './CarreInitiale'
import { CLE_MOI } from '../hooks/useMoi'

type EntreeMenu = {
  id: string
  libelle: string
  icone: typeof User
  action: () => void
}

export function MenuUtilisateur({ moi }: { moi: Moi }) {
  const [ouvert, setOuvert] = useState(false)
  const declencheurRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const itemRefs = useRef<(HTMLButtonElement | null)[]>([])
  const idMenu = useId()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const deconnexion = useMutation({
    mutationFn: deconnecter,
    onSuccess: () => {
      queryClient.removeQueries({ queryKey: CLE_MOI })
      navigate('/admin/connexion', { replace: true })
    },
  })

  // Le super-admin a toutes les permissions mais aucun établissement : ces
  // pages commerçant le renverraient vers /etablissements (RouteProtegee).
  // Ses équivalents vivent dans le sous-menu de BarreLateraleSuperAdmin.
  const peutGererParametres = moi.etablissement !== null && moi.permissions.includes('gerer_parametres')

  const entrees: EntreeMenu[] = [
    { id: 'compte', libelle: 'Mon compte', icone: User, action: () => navigate('/admin/compte') },
    ...(peutGererParametres
      ? [
          {
            id: 'identite',
            libelle: 'Identité de la boutique',
            icone: Settings,
            action: () => navigate('/admin/identite'),
          },
          {
            id: 'paiement',
            libelle: 'Paiement',
            icone: CreditCard,
            action: () => navigate('/admin/paiement'),
          },
          {
            id: 'livraison',
            libelle: 'Livraison',
            icone: Truck,
            action: () => navigate('/admin/livraison'),
          },
          {
            id: 'abonnement',
            libelle: 'Abonnement',
            icone: Wallet,
            action: () => navigate('/admin/abonnement'),
          },
        ]
      : []),
  ]

  const nombreItems = entrees.length + 1 // +1 pour "Se déconnecter"

  function fermer() {
    setOuvert(false)
    declencheurRef.current?.focus()
  }

  function choisir(action: () => void) {
    fermer()
    action()
  }

  useEffect(() => {
    if (!ouvert) {
      return
    }

    function surClicExterieur(evenement: MouseEvent) {
      const cible = evenement.target as Node
      if (!menuRef.current?.contains(cible) && !declencheurRef.current?.contains(cible)) {
        fermer()
      }
    }

    function surEchap(evenement: globalThis.KeyboardEvent) {
      if (evenement.key === 'Escape') {
        evenement.preventDefault()
        fermer()
      }
    }

    document.addEventListener('mousedown', surClicExterieur)
    document.addEventListener('keydown', surEchap)
    return () => {
      document.removeEventListener('mousedown', surClicExterieur)
      document.removeEventListener('keydown', surEchap)
    }
  }, [ouvert])

  useEffect(() => {
    if (ouvert) {
      itemRefs.current[0]?.focus()
    }
  }, [ouvert])

  function deplacerFocus(indexActuel: number, direction: 1 | -1) {
    const suivant = (indexActuel + direction + nombreItems) % nombreItems
    itemRefs.current[suivant]?.focus()
  }

  function surClavierItem(evenement: KeyboardEvent<HTMLButtonElement>, index: number) {
    if (evenement.key === 'ArrowDown') {
      evenement.preventDefault()
      deplacerFocus(index, 1)
    } else if (evenement.key === 'ArrowUp') {
      evenement.preventDefault()
      deplacerFocus(index, -1)
    } else if (evenement.key === 'Tab') {
      // Sort du menu : on ferme sans reprendre le focus, l'ordre naturel de
      // tabulation du navigateur continue son chemin.
      setOuvert(false)
    }
  }

  return (
    <div className="relative">
      <button
        ref={declencheurRef}
        type="button"
        aria-haspopup="menu"
        aria-expanded={ouvert}
        aria-controls={ouvert ? idMenu : undefined}
        onClick={() => setOuvert((valeur) => !valeur)}
        className="flex h-11 cursor-pointer items-center gap-2 rounded-md px-2 transition-[background-color,transform] duration-rapide ease-apparition hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
      >
        <CarreInitiale nom={moi.utilisateur.nom} taille={40} arrondi />
        <span className="hidden max-w-40 flex-col items-start leading-tight sm:flex">
          <span className="truncate text-petit font-semibold text-texte">{moi.utilisateur.nom}</span>
          <span className="truncate text-[11px] text-texte-secondaire">{moi.role_libelle_espace ?? moi.role}</span>
        </span>
        <ChevronDown
          aria-hidden="true"
          size={16}
          strokeWidth={1.5}
          className={`shrink-0 text-texte-secondaire transition-transform duration-rapide ${ouvert ? 'rotate-180' : ''}`}
        />
      </button>

      {ouvert && (
        <>
          <div
            role="presentation"
            onClick={fermer}
            className="fixed inset-0 z-20 animate-entree-fondu bg-texte/20 sm:hidden"
          />

          <div
            ref={menuRef}
            id={idMenu}
            role="menu"
            aria-label="Menu utilisateur"
            className="fixed inset-x-0 bottom-0 z-30 animate-entree-panneau rounded-t-xl border-t border-bordure-forte bg-surface-haute p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-flottant sm:absolute sm:inset-x-auto sm:bottom-auto sm:top-full sm:right-0 sm:mt-2 sm:w-64 sm:animate-entree-haut sm:rounded-xl sm:border sm:pb-2"
          >
            <div className="flex items-center gap-3 border-b border-bordure px-3 py-3">
              <CarreInitiale nom={moi.utilisateur.nom} taille={40} arrondi />
              <div className="min-w-0">
                <p className="truncate text-corps font-semibold text-texte">{moi.utilisateur.nom}</p>
                <p className="truncate text-petit text-texte-secondaire">{moi.utilisateur.email}</p>
                <p className="text-petit text-texte-secondaire">{moi.role_libelle_espace ?? moi.role}</p>
              </div>
            </div>

            <div className="py-1">
              {entrees.map((entree, index) => (
                <button
                  key={entree.id}
                  ref={(element) => {
                    itemRefs.current[index] = element
                  }}
                  role="menuitem"
                  type="button"
                  tabIndex={-1}
                  onClick={() => choisir(entree.action)}
                  onKeyDown={(evenement) => surClavierItem(evenement, index)}
                  className="flex h-11 w-full cursor-pointer items-center gap-2.5 rounded-md px-3 text-left text-corps text-texte transition-colors hover:bg-surface-alt focus-visible:bg-surface-alt focus-visible:outline-none"
                >
                  <entree.icone aria-hidden="true" size={18} strokeWidth={1.5} />
                  {entree.libelle}
                </button>
              ))}
            </div>

            <div className="border-t border-bordure pt-1">
              <button
                ref={(element) => {
                  itemRefs.current[entrees.length] = element
                }}
                role="menuitem"
                type="button"
                tabIndex={-1}
                onClick={() => choisir(() => deconnexion.mutate())}
                onKeyDown={(evenement) => surClavierItem(evenement, entrees.length)}
                className="flex h-11 w-full cursor-pointer items-center gap-2.5 rounded-md px-3 text-left text-corps text-danger transition-colors hover:bg-danger/10 focus-visible:bg-danger/10 focus-visible:outline-none"
              >
                <LogOut aria-hidden="true" size={18} strokeWidth={1.5} />
                Se déconnecter
              </button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
