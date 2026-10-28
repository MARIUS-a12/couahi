/**
 * Les variantes d'un produit (B2), dans le formulaire de modification :
 * options et valeurs (libellés libres, ordre par glisser-déposer), grille
 * des combinaisons, puis stock / prix / photo de chaque variante.
 *
 * Comme les photos, chaque action s'enregistre aussitôt, indépendamment
 * du bouton « Enregistrer » du produit — d'où des boutons type="button"
 * et la touche Entrée interceptée dans les champs : elle soumettrait sinon
 * le formulaire du produit.
 *
 * Un produit sans option garde son propre stock (bloc Stock du
 * formulaire) : cette section ne lui montre qu'une invitation.
 */
import { Archive, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import type { Media } from '../api/medias'
import {
  ajouterOption,
  ajouterValeur,
  ajouterValeurs,
  archiverValeur,
  renommerOption,
  renommerValeur,
  reordonnerOptions,
  reordonnerValeurs,
  restaurerValeur,
  retirerOption,
} from '../api/variantes'
import type { OptionProduit, StructureVariantes, Suggestion, ValeurOption } from '../api/variantes'
import { useVariantes } from '../hooks/useVariantes'
import { sansEntree } from '../lib/clavier'
import { optionNumerique, valeursDePlage } from '../lib/plage'
import { separerValeurs } from '../lib/valeursOption'
import { Bouton } from './Bouton'
import { EtatChargement } from './EtatChargement'
import { GrilleCombinaisons } from './GrilleCombinaisons'
import { ListeReordonnable } from './ListeReordonnable'
import { ListeVariantes } from './ListeVariantes'

/** Des noms courants, proposés d'un toucher — jamais imposés. */
const NOMS_COURANTS = ['Taille', 'Couleur', 'Pointure', 'Contenance']

type Ecrire = ReturnType<typeof useVariantes>['ecrire']

/** Un texte tapé mais pas encore ajouté, par champ : il n'est pas enregistré. */
type TexteEnAttente = (cle: string, texte: string) => void

/** « Taille et Couleur », « Taille, Couleur et Longueur ». */
function enumerer(noms: string[]): string {
  return noms.length < 2 ? (noms[0] ?? '') : `${noms.slice(0, -1).join(', ')} et ${noms[noms.length - 1]}`
}

export function SectionVariantes({
  produitId,
  photos,
  onEcrituresEnCours,
  onNonEnregistre,
}: {
  produitId: number
  photos: Media[]
  /** Pour que le formulaire empêche de quitter tant qu'un envoi est en cours. */
  onEcrituresEnCours?: (nombre: number) => void
  /**
   * Ce qui est saisi mais pas enregistré (texte non ajouté, cases cochées
   * non créées), ou null : le bouton du produit ne doit pas laisser croire
   * que tout l'est.
   */
  onNonEnregistre?: (message: string | null) => void
}) {
  const { requete, suggestions, ecrire, enCours, enAttente, erreur, effacerErreur } = useVariantes(produitId)
  const [ajoutOuvert, setAjoutOuvert] = useState(false)
  // Le bandeau n'apparaît qu'après une première écriture : on garde le
  // plus grand nombre d'envois vus (état dérivé pendant le rendu), plutôt
  // qu'un état posé dans un effet.
  const [maxVu, setMaxVu] = useState(0)
  if (enAttente > maxVu) {
    setMaxVu(enAttente)
  }
  const aEcrit = maxVu > 0

  const [brouillonGrille, setBrouillonGrille] = useState<{ nombre: number; resume: string | null }>({ nombre: 0, resume: null })
  const changerBrouillonGrille = useCallback((nombre: number, resume: string | null) => setBrouillonGrille({ nombre, resume }), [])
  const [textes, setTextes] = useState<Record<string, string>>({})
  const changerTexte = useCallback<TexteEnAttente>(
    (cle, texte) => setTextes((actuels) => (actuels[cle] === texte.trim() ? actuels : { ...actuels, [cle]: texte.trim() })),
    [],
  )
  const structureChargee = requete.data

  // Un texte tapé mais pas ajouté n'est pas enregistré : le bandeau le dit,
  // au lieu de « Tout est enregistré » (le même mensonge que celui du stock
  // corrigé en B2).
  const nonEnregistres = [
    ...Object.entries(textes)
      .filter(([, texte]) => texte !== '')
      .map(([cle, texte]) => {
        if (cle === 'nouvelle-option') {
          return `L'option « ${texte} » n'est pas encore ajoutée : touchez « Ajouter l'option ».`
        }
        const option = structureChargee?.options.find((candidate) => String(candidate.id) === cle)
        return `« ${texte} » n'est pas encore ajouté${option ? ` à ${option.nom}` : ''} : touchez « Ajouter ».`
      }),
    ...(brouillonGrille.resume ? [brouillonGrille.resume] : []),
  ]
  const messageNonEnregistre = nonEnregistres.length > 0 ? nonEnregistres.join(' ') : null
  const textesEnAttente = Object.values(textes).filter((texte) => texte !== '').length

  useEffect(() => {
    onEcrituresEnCours?.(enAttente + brouillonGrille.nombre + textesEnAttente)
  }, [enAttente, brouillonGrille, textesEnAttente, onEcrituresEnCours])

  useEffect(() => {
    onNonEnregistre?.(messageNonEnregistre)
  }, [messageNonEnregistre, onNonEnregistre])

  if (requete.isPending) {
    return <EtatChargement />
  }

  if (requete.isError) {
    return <p className="text-petit text-danger">Impossible de charger les variantes de ce produit.</p>
  }

  const structure = requete.data
  const sansOption = structure.options.length === 0
  // Options et valeurs prêtes, aucune variante : la vitrine montre alors un
  // produit simple, sans choix, et rien ne disait pourquoi.
  const aucuneVarianteCreee =
    !sansOption &&
    structure.options.every((option) => option.valeurs.some((valeur) => !valeur.archivee)) &&
    !structure.variantes.some((variante) => variante.active) &&
    brouillonGrille.nombre === 0

  return (
    <section aria-labelledby="titre-variantes" className="space-y-4">
      <div>
        <h2 id="titre-variantes" className="text-titre-section font-semibold text-texte">
          Variantes
        </h2>
        <p className="text-petit text-texte-secondaire">
          {sansOption
            ? 'Ce produit existe en plusieurs tailles, couleurs ou contenances ? Ajoutez une option.'
            : 'Chaque combinaison cochée devient une variante, avec son propre stock.'}
        </p>
      </div>

      {enAttente > 0 ? (
        <p role="status" className="rounded bg-surface-alt px-3 py-2 text-petit font-medium text-texte">
          Enregistrement…
        </p>
      ) : messageNonEnregistre ? (
        <div role="status" className="rounded border border-alerte bg-alerte/10 px-3 py-2 text-petit text-texte">
          <p className="font-semibold">Pas encore enregistré</p>
          {nonEnregistres.map((message) => (
            <p key={message}>{message}</p>
          ))}
        </div>
      ) : (
        aEcrit && (
          <p role="status" className="rounded bg-succes/10 px-3 py-2 text-petit font-medium text-succes">
            Tout est enregistré.
          </p>
        )
      )}

      {aucuneVarianteCreee && (
        <p className="rounded border border-alerte bg-alerte/10 px-3 py-2 text-petit font-medium text-texte">
          Vous avez défini {enumerer(structure.options.map((option) => option.nom))}, mais aucune variante n'est créée : cochez les combinaisons ci-dessous. En attendant, la boutique montre ce produit sans choix.
        </p>
      )}

      {erreur && (
        <div role="alert" className="flex items-start justify-between gap-2 rounded border border-danger bg-danger/5 p-3 text-petit text-danger">
          <span>{erreur}</span>
          <button type="button" onClick={effacerErreur} className="shrink-0 cursor-pointer font-medium underline">
            Fermer
          </button>
        </div>
      )}

      {structure.options.map((option) => (
        <CarteOption key={option.id} produitId={produitId} option={option} suggestions={suggestions} ecrire={ecrire} enCours={enCours} onTexteEnAttente={changerTexte} />
      ))}

      {structure.options.length >= 2 && <OrdreOptions produitId={produitId} structure={structure} ecrire={ecrire} enCours={enCours} />}

      {ajoutOuvert ? (
        <AjoutOption
          produitId={produitId}
          structure={structure}
          suggestions={suggestions}
          ecrire={ecrire}
          enCours={enCours}
          onTexteEnAttente={changerTexte}
          onFermer={() => {
            changerTexte('nouvelle-option', '')
            setAjoutOuvert(false)
          }}
        />
      ) : (
        <Bouton type="button" variante="secondaire" icone={<Plus aria-hidden="true" className="h-4 w-4" />} onClick={() => setAjoutOuvert(true)}>
          Ajouter une option
        </Bouton>
      )}

      {!sansOption && (
        <>
          <GrilleCombinaisons produitId={produitId} structure={structure} ecrire={ecrire} enCours={enCours} onBrouillon={changerBrouillonGrille} />
          {structure.variantes.length > 0 && (
            <ListeVariantes produitId={produitId} structure={structure} photos={photos} ecrire={ecrire} enCours={enCours} />
          )}
        </>
      )}
    </section>
  )
}

function AjoutOption({
  produitId,
  structure,
  suggestions,
  ecrire,
  enCours,
  onTexteEnAttente,
  onFermer,
}: {
  produitId: number
  structure: StructureVariantes
  suggestions: Suggestion[]
  ecrire: Ecrire
  enCours: boolean
  onTexteEnAttente: TexteEnAttente
  onFermer: () => void
}) {
  const [nom, setNomLocal] = useState('')
  const setNom = (valeur: string) => {
    setNomLocal(valeur)
    onTexteEnAttente('nouvelle-option', valeur)
  }
  const [valeurExistantes, setValeurExistantes] = useState('')
  const nombreVariantes = structure.variantes.length
  const dejaPrises = structure.options.map((option) => option.nom.toLowerCase())
  const propositions = [...new Set([...suggestions.map((suggestion) => suggestion.nom), ...NOMS_COURANTS])].filter(
    (proposition) => !dejaPrises.includes(proposition.toLowerCase()),
  )
  const valeursConnues = suggestions.find((suggestion) => suggestion.nom.toLowerCase() === nom.trim().toLowerCase())?.valeurs ?? []

  async function valider() {
    if (nom.trim() === '' || (nombreVariantes > 0 && valeurExistantes.trim() === '')) {
      return
    }
    const ok = await ecrire(() => ajouterOption(produitId, nom.trim(), valeurExistantes.trim()), { structurel: true })

    if (ok) {
      onFermer()
    }
  }

  return (
    <div className="space-y-3 rounded border border-bordure bg-surface p-3">
      <div>
        <label htmlFor="nouvelle-option" className="mb-1 block text-corps font-medium text-texte">
          Nom de l'option
        </label>
        <input
          id="nouvelle-option"
          value={nom}
          maxLength={60}
          autoFocus
          onChange={(evenement) => setNom(evenement.target.value)}
          onKeyDown={(evenement) => sansEntree(evenement, valider)}
          placeholder="Taille, Couleur, Pointure…"
          className="h-11 w-full rounded border border-bordure bg-surface px-3 text-corps text-texte focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
        />
        {propositions.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-2">
            {propositions.map((proposition) => (
              <button
                key={proposition}
                type="button"
                onClick={() => setNom(proposition)}
                className="h-9 cursor-pointer rounded-full border border-bordure bg-surface-alt px-3 text-petit text-texte hover:border-primaire"
              >
                {proposition}
              </button>
            ))}
          </div>
        )}
      </div>

      {nombreVariantes > 0 && (
        <div>
          <label htmlFor="valeur-existantes" className="mb-1 block text-corps font-medium text-texte">
            {nom.trim() ? `Quelle « ${nom.trim()} »` : 'Quelle valeur'} pour vos {nombreVariantes} variante(s) existante(s) ?
          </label>
          <p className="mb-1 text-petit text-texte-secondaire">
            Elles gardent leur stock et leurs commandes, et reçoivent cette valeur.
          </p>
          <input
            id="valeur-existantes"
            value={valeurExistantes}
            maxLength={60}
            list="valeurs-connues"
            onChange={(evenement) => setValeurExistantes(evenement.target.value)}
            onKeyDown={(evenement) => sansEntree(evenement, valider)}
            className="h-11 w-full rounded border border-bordure bg-surface px-3 text-corps text-texte focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
          />
          <datalist id="valeurs-connues">
            {valeursConnues.map((valeur) => (
              <option key={valeur} value={valeur} />
            ))}
          </datalist>
        </div>
      )}

      <div className="flex gap-2">
        <Bouton type="button" onClick={valider} chargement={enCours} disabled={nom.trim() === '' || (nombreVariantes > 0 && valeurExistantes.trim() === '')}>
          Ajouter l'option
        </Bouton>
        <Bouton type="button" variante="tertiaire" onClick={onFermer}>
          Annuler
        </Bouton>
      </div>
    </div>
  )
}

function CarteOption({
  produitId,
  option,
  suggestions,
  ecrire,
  enCours,
  onTexteEnAttente,
}: {
  produitId: number
  option: OptionProduit
  suggestions: Suggestion[]
  ecrire: Ecrire
  enCours: boolean
  onTexteEnAttente: TexteEnAttente
}) {
  const [nouvelleValeur, setNouvelleValeurLocale] = useState('')
  const setNouvelleValeur = (valeur: string) => {
    setNouvelleValeurLocale(valeur)
    onTexteEnAttente(String(option.id), valeur)
  }
  const tapees = separerValeurs(nouvelleValeur)
  const [renommage, setRenommage] = useState<string | null>(null)
  const actives = option.valeurs.filter((valeur) => !valeur.archivee)
  const archivees = option.valeurs.filter((valeur) => valeur.archivee)
  const presentes = option.valeurs.map((valeur) => valeur.libelle.toLowerCase())
  const aProposer = (suggestions.find((suggestion) => suggestion.nom.toLowerCase() === option.nom.toLowerCase())?.valeurs ?? []).filter(
    (libelle) => !presentes.includes(libelle.toLowerCase()),
  )

  const [plage, setPlage] = useState<{ de: string; a: string } | null>(null)
  const calculPlage = plage === null ? null : valeursDePlage(plage.de, plage.a)
  const plageProposee = optionNumerique(actives.map((valeur) => valeur.libelle))

  async function ajouter(libelles: string[]) {
    // « blanc, noir » : deux valeurs, pas une seule nommée « blanc, noir ».
    const propres = libelles.flatMap(separerValeurs)

    if (propres.length === 0) {
      return
    }
    // Une seule requête pour plusieurs valeurs : tout ou rien, jamais la
    // moitié d'une plage créée puis un refus au milieu.
    const ok = await ecrire(
      () => (propres.length === 1 ? ajouterValeur(produitId, option.id, propres[0]) : ajouterValeurs(produitId, option.id, propres)),
      { structurel: true },
    )
    if (ok) {
      setNouvelleValeur('')
      setPlage(null)
    }
  }

  async function archiver(valeur: ValeurOption) {
    const message =
      valeur.nombre_variantes === 0
        ? `Supprimer la valeur « ${valeur.libelle} » ? Aucune variante ne l'utilise.`
        : `Archiver « ${valeur.libelle} » ?\n\n${valeur.nombre_variantes} variante(s) ne seront plus vendues` +
          (valeur.stock_total > 0 ? ` (${valeur.stock_total} article(s) en stock y restent enregistrés)` : '') +
          '.' +
          (valeur.nombre_commandes > 0 ? ` ${valeur.nombre_commandes} commande(s) passée(s) la mentionnent : elles restent intactes.` : '') +
          '\n\nVous pourrez la restaurer.'

    if (window.confirm(message)) {
      await ecrire(() => archiverValeur(produitId, valeur.id), { structurel: true })
    }
  }

  async function retirer() {
    if (window.confirm(`Retirer l'option « ${option.nom} » et ses valeurs ?`)) {
      await ecrire(() => retirerOption(produitId, option.id), { structurel: true })
    }
  }

  return (
    <div className="space-y-3 rounded border border-bordure bg-surface-haute p-3">
      <div className="flex items-center justify-between gap-2">
        {renommage === null ? (
          <h3 className="text-corps font-semibold text-texte">{option.nom}</h3>
        ) : (
          <input
            aria-label={`Nouveau nom de l'option ${option.nom}`}
            value={renommage}
            autoFocus
            maxLength={60}
            onChange={(evenement) => setRenommage(evenement.target.value)}
            onKeyDown={(evenement) =>
              sansEntree(evenement, async () => {
                if (await ecrire(() => renommerOption(produitId, option.id, renommage), { structurel: true })) {
                  setRenommage(null)
                }
              })
            }
            onBlur={() => setRenommage(null)}
            className="h-11 flex-1 rounded border border-primaire bg-surface px-3 text-corps text-texte"
          />
        )}
        <div className="flex shrink-0">
          <button
            type="button"
            aria-label={`Renommer l'option ${option.nom}`}
            onClick={() => setRenommage(option.nom)}
            className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire hover:bg-surface-alt"
          >
            <Pencil aria-hidden="true" className="h-4 w-4" />
          </button>
          <button
            type="button"
            aria-label={`Retirer l'option ${option.nom}`}
            onClick={retirer}
            className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-danger hover:bg-danger/10"
          >
            <Trash2 aria-hidden="true" className="h-4 w-4" />
          </button>
        </div>
      </div>

      {actives.length > 0 && (
        <ListeReordonnable
          elements={actives}
          libelle={(valeur) => valeur.libelle}
          desactivee={enCours}
          onReordonner={(ordre) =>
            ecrire(() => reordonnerValeurs(produitId, option.id, [...ordre, ...archivees.map((valeur) => valeur.id)]), { structurel: true })
          }
          rendre={(valeur, poignee) => <LigneValeur produitId={produitId} valeur={valeur} poignee={poignee} ecrire={ecrire} onArchiver={() => archiver(valeur)} />}
        />
      )}

      <div className="flex gap-2">
        <input
          aria-label={`Nouvelle valeur de ${option.nom}`}
          value={nouvelleValeur}
          maxLength={600}
          onChange={(evenement) => setNouvelleValeur(evenement.target.value)}
          onKeyDown={(evenement) => sansEntree(evenement, () => ajouter([nouvelleValeur]))}
          placeholder={`Ajouter : ${option.nom === 'Pointure' ? '39' : option.nom === 'Couleur' ? 'Bleu' : 'M'}…`}
          className="h-11 min-w-0 flex-1 rounded border border-bordure bg-surface px-3 text-corps text-texte focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
        />
        <Bouton type="button" variante="secondaire" onClick={() => ajouter([nouvelleValeur])} disabled={tapees.length === 0 || enCours}>
          {tapees.length > 1 ? `Ajouter les ${tapees.length}` : 'Ajouter'}
        </Bouton>
      </div>
      {tapees.length > 1 && (
        <p className="-mt-1 text-petit text-texte-secondaire">
          {tapees.length} valeurs : {tapees.join(', ')}.
        </p>
      )}

      {plageProposee &&
        (plage === null ? (
          <button
            type="button"
            onClick={() => setPlage({ de: '', a: '' })}
            className="min-h-11 cursor-pointer text-left text-petit font-medium text-primaire underline"
          >
            Ajouter une suite de nombres d'un coup (ex. de 39 à 45)
          </button>
        ) : (
          <fieldset className="space-y-2 rounded border border-bordure bg-surface p-2">
            <legend className="px-1 text-petit font-medium text-texte">Suite de nombres</legend>
            <div className="flex items-center gap-2">
              <label className="flex items-center gap-1 text-petit text-texte-secondaire">
                De
                <input
                  aria-label={`Premier nombre de ${option.nom}`}
                  inputMode="numeric"
                  autoFocus
                  value={plage.de}
                  onChange={(evenement) => setPlage({ ...plage, de: evenement.target.value })}
                  onKeyDown={(evenement) => sansEntree(evenement)}
                  className="h-11 w-20 rounded border border-bordure bg-surface px-2 text-corps text-texte focus:border-primaire"
                />
              </label>
              <label className="flex items-center gap-1 text-petit text-texte-secondaire">
                à
                <input
                  aria-label={`Dernier nombre de ${option.nom}`}
                  inputMode="numeric"
                  value={plage.a}
                  onChange={(evenement) => setPlage({ ...plage, a: evenement.target.value })}
                  onKeyDown={(evenement) =>
                    sansEntree(evenement, () => {
                      if (calculPlage !== null && 'valeurs' in calculPlage) {
                        void ajouter(calculPlage.valeurs)
                      }
                    })
                  }
                  className="h-11 w-20 rounded border border-bordure bg-surface px-2 text-corps text-texte focus:border-primaire"
                />
              </label>
            </div>
            {calculPlage !== null && 'erreur' in calculPlage && (plage.de !== '' && plage.a !== '') && (
              <p className="text-petit text-danger">{calculPlage.erreur}</p>
            )}
            <div className="flex flex-wrap gap-2">
              <Bouton
                type="button"
                variante="secondaire"
                disabled={enCours || calculPlage === null || !('valeurs' in calculPlage)}
                onClick={() => calculPlage !== null && 'valeurs' in calculPlage && ajouter(calculPlage.valeurs)}
              >
                {calculPlage !== null && 'valeurs' in calculPlage
                  ? `Ajouter ${calculPlage.valeurs.length} valeur${calculPlage.valeurs.length > 1 ? 's' : ''} (${calculPlage.valeurs[0]} à ${calculPlage.valeurs[calculPlage.valeurs.length - 1]})`
                  : 'Ajouter la suite'}
              </Bouton>
              <Bouton type="button" variante="tertiaire" onClick={() => setPlage(null)}>
                Annuler
              </Bouton>
            </div>
          </fieldset>
        ))}

      {aProposer.length > 0 && (
        <div>
          <p className="mb-1 text-petit text-texte-secondaire">Déjà utilisées dans votre boutique :</p>
          <div className="flex flex-wrap gap-2">
            {aProposer.map((libelle) => (
              <button
                key={libelle}
                type="button"
                disabled={enCours}
                onClick={() => ajouter([libelle])}
                className="h-9 cursor-pointer rounded-full border border-bordure bg-surface-alt px-3 text-petit text-texte hover:border-primaire disabled:opacity-50"
              >
                + {libelle}
              </button>
            ))}
            {aProposer.length > 1 && (
              <button
                type="button"
                disabled={enCours}
                onClick={() => ajouter(aProposer)}
                className="h-9 cursor-pointer rounded-full px-3 text-petit font-medium text-primaire underline disabled:opacity-50"
              >
                Tout ajouter ({aProposer.length})
              </button>
            )}
          </div>
        </div>
      )}

      {archivees.length > 0 && (
        <div className="text-petit text-texte-secondaire">
          Archivées :{' '}
          {archivees.map((valeur) => (
            <span key={valeur.id} className="mr-2 inline-flex items-center gap-1">
              {valeur.libelle}
              <button
                type="button"
                aria-label={`Restaurer ${valeur.libelle}`}
                onClick={() => ecrire(() => restaurerValeur(produitId, valeur.id), { structurel: true })}
                className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded text-primaire hover:bg-surface-alt"
              >
                <RotateCcw aria-hidden="true" className="h-4 w-4" />
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

function LigneValeur({
  produitId,
  valeur,
  poignee,
  ecrire,
  onArchiver,
}: {
  produitId: number
  valeur: ValeurOption
  poignee: ReactNode
  ecrire: Ecrire
  onArchiver: () => void
}) {
  const [renommage, setRenommage] = useState<string | null>(null)

  return (
    <>
      {poignee}
      {renommage === null ? (
        <button
          type="button"
          onClick={() => setRenommage(valeur.libelle)}
          aria-label={`${valeur.libelle} — renommer`}
          className="flex h-11 min-w-0 flex-1 cursor-pointer items-center gap-2 text-left text-corps text-texte"
        >
          <span className="truncate">{valeur.libelle}</span>
          {valeur.nombre_variantes > 0 && (
            <span className="shrink-0 text-petit text-texte-secondaire">
              {valeur.nombre_variantes} variante{valeur.nombre_variantes > 1 ? 's' : ''}
            </span>
          )}
        </button>
      ) : (
        <input
          aria-label={`Nouveau libellé pour ${valeur.libelle}`}
          value={renommage}
          autoFocus
          maxLength={60}
          onChange={(evenement) => setRenommage(evenement.target.value)}
          onKeyDown={(evenement) =>
            sansEntree(evenement, async () => {
              if (await ecrire(() => renommerValeur(produitId, valeur.id, renommage), { structurel: true })) {
                setRenommage(null)
              }
            })
          }
          onBlur={() => setRenommage(null)}
          className="h-10 min-w-0 flex-1 rounded border border-primaire bg-surface px-2 text-corps text-texte"
        />
      )}
      <button
        type="button"
        aria-label={`Archiver ${valeur.libelle}`}
        onClick={onArchiver}
        className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded text-texte-secondaire hover:bg-surface-alt hover:text-danger"
      >
        <Archive aria-hidden="true" className="h-4 w-4" />
      </button>
    </>
  )
}

function OrdreOptions({ produitId, structure, ecrire, enCours }: { produitId: number; structure: StructureVariantes; ecrire: Ecrire; enCours: boolean }) {
  return (
    <div>
      <p className="mb-1 text-petit text-texte-secondaire">
        Ordre des options dans le nom des variantes (« {structure.options.map((option) => option.valeurs[0]?.libelle ?? option.nom).join(' / ')} ») :
      </p>
      <ListeReordonnable
        elements={structure.options}
        libelle={(option) => option.nom}
        desactivee={enCours}
        onReordonner={(ordre) => ecrire(() => reordonnerOptions(produitId, ordre), { structurel: true })}
        rendre={(option, poignee) => (
          <>
            {poignee}
            <span className="text-corps text-texte">{option.nom}</span>
          </>
        )}
      />
    </div>
  )
}
