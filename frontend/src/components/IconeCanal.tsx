/**
 * D'où vient la vente (Étape 9) : vitrine web ou WhatsApp, avec une icône
 * distincte — le commerçant doit voir d'où viennent ses ventes en un coup
 * d'œil, sans lire le mot. Lot C : comptoir et téléphone, saisis au
 * back-office.
 */
import { Globe, MessageCircle, Phone, Store } from 'lucide-react'
import type { CanalCommande } from '../api/commandes'

const LIBELLES: Record<CanalCommande, string> = {
  web: 'Vitrine web',
  whatsapp: 'WhatsApp',
  comptoir: 'Comptoir',
  telephone: 'Téléphone',
}

const ICONES = { web: Globe, whatsapp: MessageCircle, comptoir: Store, telephone: Phone }

export function IconeCanal({ canal }: { canal: CanalCommande }) {
  const Icone = ICONES[canal]

  return (
    <span className="inline-flex items-center gap-1.5 text-corps text-texte-secondaire" title={LIBELLES[canal]}>
      <Icone aria-hidden="true" size={16} strokeWidth={1.75} />
      {LIBELLES[canal]}
    </span>
  )
}
