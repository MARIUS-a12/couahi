import type { ModeStock } from '../api/produits'
import { Bascule } from './Bascule'
import { ChampQuantite } from './ChampQuantite'
import { GroupeSegmente } from './GroupeSegmente'

const OPTIONS_MODE: { valeur: ModeStock; libelle: string }[] = [
  { valeur: 'compte', libelle: 'Suivi par quantité' },
  { valeur: 'interrupteur', libelle: 'Disponibilité manuelle' },
]

/**
 * Bloc stock du formulaire produit — sa forme dépend du mode de stock :
 * une quantité (compte) ou un simple booléen disponible (interrupteur).
 * À la création, le mode découle du type de l'établissement et n'est pas
 * proposé. En modification, le commerçant peut le changer ; l'API le
 * refuse tant qu'une commande en cours contient le produit, et son
 * message s'affiche sous le choix (erreurModeStock).
 */
export function BlocStock({
  modeStock,
  onModeStockChange,
  erreurModeStock,
  estModification,
  quantiteStock,
  onQuantiteStockChange,
  erreurQuantiteStock,
  disponible,
  onDisponibleChange,
  erreurDisponible,
}: {
  modeStock: ModeStock
  onModeStockChange: (valeur: ModeStock) => void
  erreurModeStock?: string
  estModification: boolean
  quantiteStock: number
  onQuantiteStockChange: (valeur: number) => void
  erreurQuantiteStock?: string
  disponible: boolean
  onDisponibleChange: (valeur: boolean) => void
  erreurDisponible?: string
}) {
  return (
    <div>
      <h2 className="mb-2 text-titre-section font-semibold text-texte">Stock</h2>

      {estModification && (
        <div className="mb-3">
          <p id="libelle-mode-stock" className="mb-1 text-petit text-texte-secondaire">
            Mode de stock
          </p>
          <div role="group" aria-labelledby="libelle-mode-stock">
            <GroupeSegmente id="mode_stock" options={OPTIONS_MODE} valeur={modeStock} onChange={onModeStockChange} />
          </div>
          {erreurModeStock && <p className="mt-1 text-petit text-danger">{erreurModeStock}</p>}
        </div>
      )}

      {modeStock === 'compte' ? (
        <ChampQuantite
          id="quantite_stock"
          label="Quantité en stock"
          valeur={quantiteStock}
          onChange={onQuantiteStockChange}
          erreur={erreurQuantiteStock}
        />
      ) : (
        <div id="disponible">
          <Bascule
            actif={disponible}
            onChange={onDisponibleChange}
            libelleActif="Disponible"
            libelleInactif="Épuisé"
          />
          <p className="mt-2 text-petit text-texte-secondaire">
            Un produit épuisé reste visible en vitrine mais ne peut plus être commandé.
          </p>
          {erreurDisponible && <p className="mt-1 text-petit text-danger">{erreurDisponible}</p>}
        </div>
      )}
    </div>
  )
}
