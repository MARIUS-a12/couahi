/**
 * Copie une valeur dans le presse-papiers (Étape 13 : le numéro Mobile
 * Money et la référence de commande, à recopier dans l'application de
 * paiement). 48px de haut : visé au pouce sur un téléphone, jamais une
 * petite icône. Retour visible ("Copié") pendant 2s, annoncé aux lecteurs
 * d'écran. Si le navigateur refuse l'accès au presse-papiers (page non
 * sécurisée, permission), la valeur reste affichée à côté, sélectionnable
 * à la main : le bouton le dit au lieu d'échouer en silence.
 */
import { Check, Copy } from 'lucide-react'
import { useEffect, useState } from 'react'

async function copier(valeur: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(valeur)
    return true
  } catch {
    // Repli pour les contextes sans API Clipboard (http hors localhost).
    try {
      const zone = document.createElement('textarea')
      zone.value = valeur
      zone.setAttribute('readonly', '')
      zone.style.position = 'fixed'
      zone.style.opacity = '0'
      document.body.appendChild(zone)
      zone.select()
      const reussi = document.execCommand('copy')
      document.body.removeChild(zone)
      return reussi
    } catch {
      return false
    }
  }
}

export function BoutonCopier({ valeur, libelle }: { valeur: string; libelle: string }) {
  const [etat, setEtat] = useState<'repos' | 'copie' | 'echec'>('repos')

  useEffect(() => {
    if (etat === 'repos') {
      return
    }

    const minuteur = setTimeout(() => setEtat('repos'), 2000)
    return () => clearTimeout(minuteur)
  }, [etat])

  async function surClic() {
    setEtat((await copier(valeur)) ? 'copie' : 'echec')
  }

  return (
    <button
      type="button"
      onClick={surClic}
      aria-label={`Copier ${libelle}`}
      className="flex min-h-12 py-2 text-center leading-snug shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-md border-2 border-texte px-4 text-corps font-semibold text-texte transition-[background-color,transform] duration-rapide ease-apparition hover:bg-surface-alt active:scale-[0.97] active:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
    >
      {etat === 'copie' ? (
        <Check aria-hidden="true" size={20} strokeWidth={2} />
      ) : (
        <Copy aria-hidden="true" size={20} strokeWidth={1.5} />
      )}
      <span aria-live="polite">{etat === 'copie' ? 'Copié' : etat === 'echec' ? 'Copiez à la main' : 'Copier'}</span>
    </button>
  )
}
