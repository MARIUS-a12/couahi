/**
 * Champ texte générique (label + input ou textarea + message d'erreur sous
 * le champ) — la brique de base du formulaire produit, pour ne pas répéter
 * ce triplet à chaque champ.
 */
export function ChampTexte({
  id,
  label,
  valeur,
  onChange,
  erreur,
  requis,
  multiligne,
  placeholder,
  type = 'text',
  autoComplete,
  maxLength,
}: {
  id: string
  label: string
  valeur: string
  onChange: (valeur: string) => void
  erreur?: string
  requis?: boolean
  multiligne?: boolean
  placeholder?: string
  type?: 'text' | 'email' | 'password' | 'tel'
  autoComplete?: string
  maxLength?: number
}) {
  const classes = `w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1 ${
    erreur ? 'border-danger' : 'border-bordure focus:border-primaire'
  }`

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-petit font-medium text-texte">
        {label}
        {requis && (
          <span aria-hidden="true" className="text-danger">
            {' '}
            *
          </span>
        )}
      </label>
      {multiligne ? (
        <textarea
          id={id}
          value={valeur}
          onChange={(evenement) => onChange(evenement.target.value)}
          placeholder={placeholder}
          rows={4}
          className={`${classes} py-2`}
        />
      ) : (
        <input
          id={id}
          type={type}
          value={valeur}
          onChange={(evenement) => onChange(evenement.target.value)}
          placeholder={placeholder}
          required={requis}
          autoComplete={autoComplete}
          maxLength={maxLength}
          className={`h-11 ${classes}`}
        />
      )}
      {erreur && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreur}</p>}
    </div>
  )
}
