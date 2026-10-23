/**
 * Interrupteur (mode "interrupteur") : disponible ou épuisé, jusqu'à ce que
 * le commerçant le rebascule. Pas de quantité en jeu, juste un booléen.
 */
export function Bascule({
  id,
  actif,
  onChange,
  libelleActif,
  libelleInactif,
  disabled = false,
}: {
  id?: string
  actif: boolean
  onChange: (actif: boolean) => void
  libelleActif: string
  libelleInactif: string
  disabled?: boolean
}) {
  return (
    <button
      id={id}
      type="button"
      role="switch"
      aria-checked={actif}
      onClick={() => onChange(!actif)}
      disabled={disabled}
      className="flex h-11 cursor-pointer items-center gap-3 rounded border border-bordure px-3 transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
    >
      <span
        aria-hidden="true"
        className={`relative h-6 w-11 shrink-0 rounded-full transition-colors ${actif ? 'bg-succes' : 'bg-bordure'}`}
      >
        <span
          className={`absolute left-1 top-1 h-4 w-4 rounded-full bg-surface transition-transform ${actif ? 'translate-x-5' : 'translate-x-0'}`}
        />
      </span>
      <span className="text-corps font-medium text-texte">{actif ? libelleActif : libelleInactif}</span>
    </button>
  )
}
