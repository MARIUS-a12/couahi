/**
 * Écran public "/panier" — contenu du panier (localStorage, voir
 * lib/panier.ts), revérifié auprès du serveur à chaque affichage et à chaque
 * changement : prix, disponibilité. Une ligne épuisée ou retirée du
 * catalogue reste visible, signalée — jamais supprimée en silence (voir
 * Étape 6B).
 */
import { useEffect } from 'react'
import { Minus, Plus, Trash2 } from 'lucide-react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { EtatErreur } from '../components/EtatErreur'
import { EtatPanierVide } from '../components/EtatPanierVide'
import { usePanier } from '../hooks/usePanier'
import { useVerifierPanier } from '../hooks/useVerifierPanier'
import { formaterMontant } from '../lib/formatage'
import type { LigneVerifiee } from '../api/vitrine'

type EtatNavigationPanier = {
  erreurArticle?: { message: string; article: string }
}

export function PagePanier() {
  const { lignes, modifierQuantite, retirer } = usePanier()
  const requete = useVerifierPanier(lignes)
  const navigate = useNavigate()
  const location = useLocation()
  const erreurArticle = (location.state as EtatNavigationPanier | null)?.erreurArticle

  useEffect(() => {
    // Nettoie le state de navigation après lecture, pour qu'un rechargement
    // de page ne réaffiche pas indéfiniment la même erreur.
    if (erreurArticle) {
      navigate(location.pathname, { replace: true, state: null })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (lignes.length === 0) {
    return <EtatPanierVide />
  }

  const lignesParCle = new Map<string, LigneVerifiee>()
  requete.data?.lignes.forEach((ligne) => lignesParCle.set(`${ligne.produit_id}:${ligne.variante_id ?? ''}`, ligne))

  const peutCommander =
    requete.data !== undefined &&
    requete.data.lignes.some((ligne) => ligne.statut === 'disponible' || ligne.statut === 'prix_modifie')

  return (
    <div className="mx-auto max-w-2xl space-y-6 pb-4">
      <h1 className="text-titre-page font-semibold text-texte">Mon panier</h1>

      {erreurArticle && (
        <p role="alert" className="animate-entree-haut border border-danger bg-surface p-3 text-corps text-danger">
          {erreurArticle.message}
        </p>
      )}

      {requete.isPending && (
        <ul className="space-y-3">
          {Array.from({ length: lignes.length }, (_, index) => (
            <li key={index} className="h-24 animate-pulse rounded border border-bordure bg-surface-alt" />
          ))}
        </ul>
      )}

      {requete.isError && <EtatErreur erreur={requete.error} onReessayer={() => requete.refetch()} />}

      {requete.data && (
        <>
          <ul className="space-y-3">
            {lignes.map((ligne) => {
              const cleLigne = `${ligne.produitId}:${ligne.varianteId ?? ''}`
              const verifiee = lignesParCle.get(cleLigne)

              return (
                <li key={cleLigne} className="border border-bordure bg-surface p-4">
                  <LignePanierAffichee
                    verifiee={verifiee}
                    quantite={ligne.quantite}
                    prixVu={ligne.prixVu}
                    onChangerQuantite={(quantite) => modifierQuantite(ligne.produitId, ligne.varianteId, quantite)}
                    onRetirer={() => retirer(ligne.produitId, ligne.varianteId)}
                  />
                </li>
              )
            })}
          </ul>

          <div className="space-y-3 border-t border-bordure pt-4">
            <div className="flex items-center justify-between text-titre-section font-semibold text-texte">
              <span>Sous-total</span>
              <span className="tabular-nums">{formaterMontant(requete.data.sous_total)}</span>
            </div>

            <Link
              to="/commander"
              aria-disabled={!peutCommander}
              onClick={(evenement) => {
                if (!peutCommander) {
                  evenement.preventDefault()
                }
              }}
              className={`flex min-h-11 py-2 text-center leading-snug w-full items-center justify-center rounded text-corps font-medium transition-[opacity,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 ${
                peutCommander
                  ? 'cursor-pointer bg-accent text-accent-texte hover:opacity-90'
                  : 'cursor-not-allowed bg-bordure text-texte-secondaire'
              }`}
            >
              Passer la commande
            </Link>
          </div>
        </>
      )}
    </div>
  )
}

function LignePanierAffichee({
  verifiee,
  quantite,
  prixVu,
  onChangerQuantite,
  onRetirer,
}: {
  verifiee: LigneVerifiee | undefined
  quantite: number
  prixVu: number | null
  onChangerQuantite: (quantite: number) => void
  onRetirer: () => void
}) {
  const nom = verifiee?.nom ?? 'Produit'
  const estRetiree = verifiee?.statut === 'retire'
  const estEpuisee = verifiee?.statut === 'epuise'
  const prixChange = verifiee?.statut === 'prix_modifie'

  return (
    <div className={estRetiree || estEpuisee ? 'opacity-60' : ''}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="truncate text-corps font-medium text-texte">{nom}</p>

          {estRetiree && <p className="mt-0.5 text-petit text-danger">Ce produit n'est plus disponible.</p>}
          {estEpuisee && <p className="mt-0.5 text-petit text-danger">Épuisé pour le moment.</p>}

          {prixChange && verifiee && prixVu !== null && (
            <p className="mt-0.5 text-petit text-alerte">
              Le prix a changé :{' '}
              <span className="tabular-nums line-through">{formaterMontant(prixVu)}</span>
              {' → '}
              <span className="tabular-nums font-medium">{formaterMontant(verifiee.prix_actuel ?? 0)}</span>
            </p>
          )}

          {!estRetiree && verifiee?.prix_actuel !== null && verifiee?.prix_actuel !== undefined && (
            <p className="mt-1 tabular-nums text-corps font-semibold text-texte">
              {formaterMontant(verifiee.prix_actuel)}
            </p>
          )}
        </div>

        <button
          type="button"
          onClick={onRetirer}
          aria-label={`Retirer ${nom} du panier`}
          className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
        >
          <Trash2 aria-hidden="true" size={18} strokeWidth={1.5} />
        </button>
      </div>

      {!estRetiree && !estEpuisee && (
        <div className="mt-3 flex items-center gap-2">
          <button
            type="button"
            disabled={quantite <= 1}
            onClick={() => onChangerQuantite(quantite - 1)}
            aria-label="Diminuer la quantité"
            className="flex h-11 w-11 cursor-pointer items-center justify-center rounded border border-bordure text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100"
          >
            <Minus aria-hidden="true" size={16} strokeWidth={1.5} />
          </button>
          <span className="w-8 text-center tabular-nums text-corps font-medium text-texte">{quantite}</span>
          <button
            type="button"
            onClick={() => onChangerQuantite(quantite + 1)}
            aria-label="Augmenter la quantité"
            className="flex h-11 w-11 cursor-pointer items-center justify-center rounded border border-bordure text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
          >
            <Plus aria-hidden="true" size={16} strokeWidth={1.5} />
          </button>
        </div>
      )}
    </div>
  )
}
