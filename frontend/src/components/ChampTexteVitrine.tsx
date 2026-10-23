/**
 * Équivalent de ChampTexte (back-office) pour la vitrine : mêmes briques
 * (label + champ + erreur sous le champ), mais sans jamais utiliser la
 * couleur primaire du thème (--color-primaire) — la vitrine n'utilise que des
 * couleurs neutres, la couleur d'accent de l'établissement étant réservée au
 * logo, à l'onglet actif et au bouton principal (voir DispositionVitrine).
 * Ajoute "type" et "maxLength", dont ChampTexte n'a pas besoin côté
 * back-office.
 */
export function ChampTexteVitrine({
  id,
  label,
  valeur,
  onChange,
  erreur,
  requis,
  multiligne,
  type = 'text',
  maxLength,
  placeholder,
}: {
  id: string
  label: string
  valeur: string
  onChange: (valeur: string) => void
  erreur?: string
  requis?: boolean
  multiligne?: boolean
  type?: 'text' | 'tel' | 'email'
  maxLength?: number
  placeholder?: string
}) {
  const classes = `w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-texte focus:outline-offset-1 ${
    erreur ? 'border-danger' : 'border-bordure focus:border-texte'
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
        <>
          <textarea
            id={id}
            value={valeur}
            onChange={(evenement) => onChange(evenement.target.value)}
            placeholder={placeholder}
            maxLength={maxLength}
            rows={3}
            className={`${classes} py-2`}
          />
          {maxLength && (
            <span className="mt-1 block text-right text-petit tabular-nums text-texte-secondaire">
              {valeur.length}/{maxLength}
            </span>
          )}
        </>
      ) : (
        <input
          id={id}
          type={type}
          value={valeur}
          onChange={(evenement) => onChange(evenement.target.value)}
          placeholder={placeholder}
          required={requis}
          maxLength={maxLength}
          className={`h-11 ${classes}`}
        />
      )}
      {erreur && <p className="animate-entree-champ mt-1 text-petit text-danger">{erreur}</p>}
    </div>
  )
}
