/**
 * Révélation unique du code d'activation généré pour un membre de l'équipe
 * (création ou "Générer un nouveau code d'accès") — jamais récupérable
 * ensuite, voir api/equipe.ts. Même esprit que BandeauMotDePasseGenere
 * (établissement) : ce n'est PAS un mot de passe, l'administrateur ne le
 * connaît jamais, seulement un code à transmettre, valable 48 heures.
 *
 * Lot F : le lien entier et « Envoyer par WhatsApp », qui ouvre WhatsApp
 * avec le message prêt (voir lib/activation.ts) ; l'administrateur choisit
 * le contact.
 */
import { Check, Copy, MessageCircle, X } from 'lucide-react'
import { useState } from 'react'
import type { DestinataireCode } from '../lib/activation'
import { lienActivation, lienWhatsappActivation, messageActivation } from '../lib/activation'

export function BandeauCodeActivationGenere({
  code,
  destinataire,
  boutique,
  onFermer,
}: {
  code: string
  destinataire: DestinataireCode
  boutique: string
  onFermer: () => void
}) {
  const [copie, setCopie] = useState(false)
  const lien = lienActivation(window.location.origin)
  const whatsapp = lienWhatsappActivation(messageActivation({ boutique, destinataire, lien, code }))

  async function copier() {
    await navigator.clipboard.writeText(code)
    setCopie(true)
  }

  return (
    <div className="border border-alerte bg-alerte/10 p-4">
      <div className="flex items-start justify-between gap-3">
        <p className="text-corps font-semibold text-texte">
          Code d'activation de {destinataire.nom} — à transmettre maintenant
        </p>
        <button
          type="button"
          onClick={onFermer}
          aria-label="Fermer"
          className="-m-1.5 flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,transform] hover:bg-alerte/10 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
        >
          <X aria-hidden="true" size={20} strokeWidth={1.5} />
        </button>
      </div>

      <p className="mt-1 text-petit text-texte-secondaire">
        Ce code ne sera plus jamais affiché. Il expire dans 48 heures. {destinataire.nom} ouvrira ce lien, saisira
        son email ({destinataire.email}) et ce code, puis choisira son mot de passe :
      </p>
      <p className="mt-1 break-all text-petit font-medium text-texte">{lien}</p>

      <div className="mt-3 flex items-center gap-2">
        <code className="h-14 flex-1 rounded border border-bordure bg-surface px-3 text-titre-section tabular-nums tracking-[0.3em] text-texte flex items-center justify-center">
          {code}
        </code>
        <button
          type="button"
          onClick={copier}
          className="flex h-14 shrink-0 cursor-pointer items-center gap-1.5 rounded border border-bordure bg-surface px-3 text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
        >
          {copie ? (
            <Check aria-hidden="true" size={20} strokeWidth={1.5} />
          ) : (
            <Copy aria-hidden="true" size={20} strokeWidth={1.5} />
          )}
          {copie ? 'Copié' : 'Copier'}
        </button>
      </div>

      <a
        href={whatsapp}
        target="_blank"
        rel="noopener noreferrer"
        className="mt-3 flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded bg-primaire px-4 text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
      >
        <MessageCircle aria-hidden="true" size={20} strokeWidth={1.75} />
        Envoyer par WhatsApp
      </a>
    </div>
  )
}
