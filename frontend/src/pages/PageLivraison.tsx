/**
 * Écran /admin/livraison : le commerçant choisit de facturer la livraison
 * selon la commune, ou de ne pas la facturer sur le site, et tient la liste
 * de ses communes avec leurs frais, plus des frais fixes facultatifs pour
 * toute autre commune (sans eux : à convenir). N'apparaît dans le menu utilisateur que
 * pour qui a gerer_parametres (voir MenuUtilisateur).
 *
 * Chaque geste s'enregistre seul (choix, ajout, modification, retrait),
 * comme les variantes : chaque réponse du serveur remplace l'écran entier.
 */
import { useState } from 'react'
import type { FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Pencil, Trash2 } from 'lucide-react'
import type { Livraison, ZoneLivraison } from '../api/livraison'
import { ajouterZone, choisirFacturation, definirAutreCommune, modifierZone, recupererLivraison, retirerZone } from '../api/livraison'
import { Bouton } from '../components/Bouton'
import { BoutonRetour } from '../components/BoutonRetour'
import { ChampPrix } from '../components/ChampPrix'
import { ChampTexte } from '../components/ChampTexte'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { confirmerRetraitZone } from '../lib/confirmations'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { libelleFrais } from '../lib/livraison'

const CLE = ['livraison']

export function PageLivraison() {
  const queryClient = useQueryClient()
  const { data, isPending, isError, error, refetch } = useQuery({ queryKey: CLE, queryFn: recupererLivraison })
  const remplacer = (livraison: Livraison) => queryClient.setQueryData(CLE, livraison)

  const facturation = useMutation({ mutationFn: (parCommune: boolean) => choisirFacturation(parCommune), onSuccess: remplacer })

  if (isPending) {
    return <EtatChargement />
  }

  if (isError || !data) {
    return <EtatErreur erreur={error} onReessayer={() => refetch()} />
  }

  return (
    <div className="mx-auto max-w-2xl space-y-8 pb-4">
      <div className="flex items-center gap-2">
        <BoutonRetour vers="/admin/produits" />
        <h1 className="text-titre-page font-bold text-texte">Livraison</h1>
      </div>

      <fieldset className="space-y-3">
        <legend className="mb-2 text-titre-section font-semibold text-texte">Frais de livraison</legend>
        <ChoixFacturation
          choisi={!data.par_commune}
          titre="Je ne facture pas la livraison sur le site"
          detail="Le client tape sa commune. La livraison n’est pas ajoutée au total : vous vous arrangez avec lui."
          onChoisir={() => facturation.mutate(false)}
          desactive={facturation.isPending}
        />
        <ChoixFacturation
          choisi={data.par_commune}
          titre="Je facture la livraison selon la commune"
          detail="Le client choisit sa commune dans votre liste, et ses frais s’ajoutent au total."
          onChoisir={() => facturation.mutate(true)}
          desactive={facturation.isPending}
        />
      </fieldset>

      {data.par_commune && <ListeZones zones={data.zones} onChangement={remplacer} />}
      {data.par_commune && <SectionAutreCommune autre={data.autre_commune} onChangement={remplacer} />}
    </div>
  )
}

function ChoixFacturation({
  choisi,
  titre,
  detail,
  onChoisir,
  desactive,
}: {
  choisi: boolean
  titre: string
  detail: string
  onChoisir: () => void
  desactive: boolean
}) {
  return (
    <label
      className={`flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors ${
        choisi ? 'border-primaire bg-primaire/5' : 'border-bordure hover:border-primaire'
      }`}
    >
      <input
        type="radio"
        name="facturation-livraison"
        checked={choisi}
        disabled={desactive}
        onChange={onChoisir}
        className="mt-1 h-5 w-5 shrink-0 accent-primaire"
      />
      <span>
        <span className="block text-corps font-medium text-texte">{titre}</span>
        <span className="mt-0.5 block text-petit text-texte-secondaire">{detail}</span>
      </span>
    </label>
  )
}

function ListeZones({ zones, onChangement }: { zones: ZoneLivraison[]; onChangement: (livraison: Livraison) => void }) {
  const [enModification, setEnModification] = useState<number | null>(null)
  const retrait = useMutation({ mutationFn: (id: number) => retirerZone(id), onSuccess: onChangement })

  function retirer(zone: ZoneLivraison) {
    if (confirmerRetraitZone(zone.nom)) {
      retrait.mutate(zone.id)
    }
  }

  return (
    <section className="space-y-4">
      <h2 className="text-titre-section font-semibold text-texte">Vos communes</h2>

      {zones.length === 0 && (
        <p className="flex gap-2 rounded-lg border border-alerte/40 bg-alerte/10 p-3 text-petit text-texte">
          <AlertTriangle aria-hidden="true" size={18} strokeWidth={1.5} className="mt-0.5 shrink-0" />
          Ajoutez au moins une commune. D’ici là, vos clients tapent leur commune et aucune livraison n’est facturée.
        </p>
      )}

      {zones.length > 0 && (
        <ul className="divide-y divide-bordure rounded-lg border border-bordure">
          {zones.map((zone) =>
            enModification === zone.id ? (
              <li key={zone.id} className="p-3">
                <FormulaireZone
                  initiale={zone}
                  libelleAction="Enregistrer"
                  envoyer={(valeurs) => modifierZone(zone.id, valeurs)}
                  onReussite={(livraison) => {
                    onChangement(livraison)
                    setEnModification(null)
                  }}
                  onAnnuler={() => setEnModification(null)}
                />
              </li>
            ) : (
              <li key={zone.id} className="flex min-h-14 items-center gap-2 py-1 pl-4 pr-1">
                <span className="flex-1 text-corps text-texte">{zone.nom}</span>
                <span className={`tabular-nums text-corps ${zone.frais === 0 ? 'font-medium text-succes' : 'text-texte'}`}>
                  {libelleFrais(zone.frais)}
                </span>
                <button
                  type="button"
                  onClick={() => setEnModification(zone.id)}
                  aria-label={`Modifier ${zone.nom}`}
                  className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primaire"
                >
                  <Pencil aria-hidden="true" size={18} strokeWidth={1.5} />
                </button>
                <button
                  type="button"
                  onClick={() => retirer(zone)}
                  disabled={retrait.isPending}
                  aria-label={`Retirer ${zone.nom}`}
                  className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-danger transition-[background-color,transform] hover:bg-danger/10 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primaire disabled:cursor-not-allowed disabled:opacity-60"
                >
                  <Trash2 aria-hidden="true" size={18} strokeWidth={1.5} />
                </button>
              </li>
            ),
          )}
        </ul>
      )}

      <div className="rounded-lg border border-bordure p-4">
        <h3 className="mb-3 text-corps font-medium text-texte">Ajouter une commune</h3>
        <FormulaireZone libelleAction="Ajouter" envoyer={ajouterZone} onReussite={onChangement} viderApres />
      </div>
    </section>
  )
}

function FormulaireZone({
  initiale,
  libelleAction,
  envoyer,
  onReussite,
  onAnnuler,
  viderApres = false,
}: {
  initiale?: ZoneLivraison
  libelleAction: string
  envoyer: (valeurs: { nom: string; frais: number }) => Promise<Livraison>
  onReussite: (livraison: Livraison) => void
  onAnnuler?: () => void
  viderApres?: boolean
}) {
  const [nom, setNom] = useState(initiale?.nom ?? '')
  const [frais, setFrais] = useState<number | null>(initiale?.frais ?? null)
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const identifiant = initiale ? `zone-${initiale.id}` : 'nouvelle-zone'

  const mutation = useMutation({
    mutationFn: () => envoyer({ nom, frais: frais ?? -1 }),
    onSuccess: (livraison) => {
      setErreurs({})
      onReussite(livraison)
      if (viderApres) {
        setNom('')
        setFrais(null)
      }
    },
    onError: (erreur) => setErreurs(extraireErreursChamps(erreur)),
  })

  function soumettre(evenement: FormEvent) {
    evenement.preventDefault()
    if (frais === null) {
      setErreurs({ frais: 'Indiquez les frais de livraison (0 si elle est offerte).' })
      return
    }
    mutation.mutate()
  }

  return (
    <form onSubmit={soumettre} noValidate className="space-y-3">
      <ChampTexte id={`${identifiant}-nom`} label="Commune" valeur={nom} onChange={setNom} erreur={erreurs.nom} requis maxLength={100} />
      <ChampPrix id={`${identifiant}-frais`} label="Frais de livraison (0 si offerte)" valeur={frais} onChange={setFrais} erreur={erreurs.frais} requis />
      <div className="flex gap-2">
        <Bouton type="submit" variante="principal" chargement={mutation.isPending}>
          {libelleAction}
        </Bouton>
        {onAnnuler && (
          <Bouton type="button" variante="tertiaire" onClick={onAnnuler}>
            Annuler
          </Bouton>
        )}
      </div>
    </form>
  )
}

/**
 * Toute commune absente de la liste : des frais fixes, ou à convenir avec
 * le client (rien n'est ajouté au total). Le client la choisit sous
 * « Autre commune » ; sans aucune commune listée, ces frais valent pour
 * toutes.
 */
function SectionAutreCommune({ autre, onChangement }: { autre: Livraison['autre_commune']; onChangement: (livraison: Livraison) => void }) {
  const [frais, setFrais] = useState<number | null>(autre?.frais ?? null)
  const [erreur, setErreur] = useState<string | undefined>()
  const mutation = useMutation({
    mutationFn: (valeur: number | null) => definirAutreCommune(valeur),
    onSuccess: (livraison) => {
      setErreur(undefined)
      setFrais(livraison.autre_commune?.frais ?? null)
      onChangement(livraison)
    },
    onError: (e) => setErreur(extraireErreursChamps(e).frais ?? 'Impossible d’enregistrer. Réessayez.'),
  })

  function soumettre(evenement: FormEvent) {
    evenement.preventDefault()
    if (frais === null) {
      setErreur('Indiquez les frais (0 si la livraison est offerte).')
      return
    }
    mutation.mutate(frais)
  }

  return (
    <section className="space-y-3">
      <h2 className="text-titre-section font-semibold text-texte">Toute autre commune</h2>
      <p className="text-petit text-texte-secondaire">
        {autre
          ? `Un client dont la commune n’est pas dans votre liste paie ${autre.frais === 0 ? 'une livraison offerte' : libelleFrais(autre.frais)}.`
          : 'Un client dont la commune n’est pas dans votre liste choisit « Autre commune » : la livraison est à convenir avec lui, rien n’est ajouté au total.'}
      </p>
      <form onSubmit={soumettre} noValidate className="space-y-3 rounded-lg border border-bordure p-4">
        <ChampPrix id="autre-commune-frais" label="Frais fixes pour toute autre commune (0 si offerte)" valeur={frais} onChange={setFrais} erreur={erreur} />
        <div className="flex flex-wrap gap-2">
          <Bouton type="submit" variante="principal" chargement={mutation.isPending && mutation.variables !== null}>
            {autre ? 'Enregistrer' : 'Fixer ces frais'}
          </Bouton>
          {autre && (
            <Bouton type="button" variante="tertiaire" chargement={mutation.isPending && mutation.variables === null} onClick={() => mutation.mutate(null)}>
              Revenir à « à convenir »
            </Bouton>
          )}
        </div>
      </form>
    </section>
  )
}
