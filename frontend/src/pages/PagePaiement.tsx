/**
 * Écran /admin/paiement — chemin commerçant du formulaire de paiement
 * unique (voir FormulairePaiement), sur son propre établissement.
 * N'apparaît dans le menu utilisateur que pour qui a gerer_parametres (voir
 * MenuUtilisateur).
 *
 * Étape 13 : deux sections, CinetPay d'abord (le moyen privilégié), puis
 * le Mobile Money (FormulaireMobileMoney) pour qui n'a pas de compte
 * marchand. Le client n'en voit jamais qu'un seul.
 */
import { BoutonRetour } from '../components/BoutonRetour'
import { FormulairePaiement } from '../components/FormulairePaiement'
import { FormulaireMobileMoney } from '../components/FormulaireMobileMoney'
import { serviceMobileMoneyCommercant } from '../api/mobileMoney'
import { servicePaiementCommercant } from '../api/paiement'

const service = servicePaiementCommercant()
const serviceMobileMoney = serviceMobileMoneyCommercant()

export function PagePaiement() {
  return (
    <div className="mx-auto max-w-4xl space-y-10 pb-4">
      <div className="flex items-center gap-2">
        <BoutonRetour vers="/admin/produits" />
        <h1 className="text-titre-page font-bold text-texte">Paiement</h1>
      </div>

      <section className="space-y-4">
        <h2 className="text-titre-section font-semibold text-texte">Paiement en ligne — CinetPay</h2>
        <FormulairePaiement service={service} />
      </section>

      <div className="border-t border-bordure pt-8">
        <FormulaireMobileMoney service={serviceMobileMoney} cle={['mobile-money']} />
      </div>
    </div>
  )
}
