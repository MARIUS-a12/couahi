/**
 * Écran /admin/commandes/nouvelle — lot C, la saisie manuelle. Le premier
 * choix est le canal, et il décide du reste :
 * - Comptoir : la vente est terminée à la saisie. Des articles, rien
 *   d'autre ; un lien discret « identifier le client » pour un habitué. Le
 *   bouton dit « Encaisser » et le montant. Après l'encaissement, le
 *   formulaire se vide pour la vente suivante, canal gardé : un comptoir
 *   enchaîne les ventes.
 * - WhatsApp et Téléphone : le numéro d'abord (sans lui on ne livre pas),
 *   qui préremplit nom, commune et quartier d'un client connu ; puis la
 *   commune avec les règles de la vitrine, « Autre commune » compris. Nom
 *   et quartier sont facultatifs : seuls le numéro et la commune comptent.
 *
 * Aucun champ prix, aucune remise : les prix affichés sont ceux de la base,
 * et le serveur les relit de toute façon. « Vendre » depuis un produit
 * ouvre cet écran avec ?produit= (et ?variante=), l'article déjà ajouté.
 */
import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import axios from 'axios'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { MessageCircle, Minus, Phone, Plus, Search, Store, Trash2 } from 'lucide-react'
import type { ArticleVendable, CanalManuel, CommandeManuelleCreee, DoublonPossible } from '../api/commandesManuelles'
import { codeReference, reconnaitreReference, rechercherArticles, recupererZonesProposees, saisirCommande, trouverClient } from '../api/commandesManuelles'
import { BandeauSucces } from '../components/BandeauSucces'
import { Bouton } from '../components/Bouton'
import { BoutonRetour } from '../components/BoutonRetour'
import { ChampTexte } from '../components/ChampTexte'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { formaterMontant } from '../lib/formatage'
import { fraisDeLivraison, libelleFrais, libelleOptionAutreCommune } from '../lib/livraison'

type Ligne = { article: ArticleVendable; quantite: number }

const CANAUX: { valeur: CanalManuel; libelle: string; Icone: typeof Store }[] = [
  { valeur: 'comptoir', libelle: 'Comptoir', Icone: Store },
  { valeur: 'whatsapp', libelle: 'WhatsApp', Icone: MessageCircle },
  { valeur: 'telephone', libelle: 'Téléphone', Icone: Phone },
]

/** Valeur de l'option « Autre commune » : jamais un nom de commune réel. */
const AUTRE_COMMUNE = '__autre__'

const cleArticle = (article: ArticleVendable) => `${article.produit_id}-${article.variante_id ?? 0}`

function stockAffiche(article: ArticleVendable): { texte: string; epuise: boolean; maximum: number } {
  if (article.stock.mode === 'compte') {
    return { texte: `Stock : ${article.stock.disponible}`, epuise: article.stock.disponible <= 0, maximum: article.stock.disponible }
  }
  return article.stock.disponible
    ? { texte: 'Disponible', epuise: false, maximum: 999 }
    : { texte: 'Épuisé', epuise: true, maximum: 0 }
}

export function PageNouvelleCommande() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [parametres] = useSearchParams()
  const produitDemande = parametres.get('produit') ? Number(parametres.get('produit')) : null
  const varianteDemandee = parametres.get('variante') ? Number(parametres.get('variante')) : null
  // Lot D : « Transformer en commande » arrive avec le canal WhatsApp et la
  // référence de la demande déjà posés.
  const canalDemande = parametres.get('canal')
  const referenceDemandee = parametres.get('reference') ?? ''

  const [canal, setCanal] = useState<CanalManuel | null>(
    canalDemande === 'comptoir' || canalDemande === 'whatsapp' || canalDemande === 'telephone' ? canalDemande : null,
  )
  const [recherche, setRecherche] = useState('')
  const [rechercheDiffusee, setRechercheDiffusee] = useState('')
  const [lignes, setLignes] = useState<Ligne[]>([])
  const [telephone, setTelephone] = useState('')
  const [nom, setNom] = useState('')
  const [commune, setCommune] = useState('')
  const [autreCommune, setAutreCommune] = useState(false)
  const [quartier, setQuartier] = useState('')
  const [identifierClient, setIdentifierClient] = useState(false)
  const [afficherReference, setAfficherReference] = useState(referenceDemandee !== '')
  const [reference, setReference] = useState(referenceDemandee)
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerale, setErreurGenerale] = useState<string | null>(null)
  const [doublons, setDoublons] = useState<DoublonPossible[] | null>(null)
  const [dejaTransformee, setDejaTransformee] = useState<{ id: number; numero: string } | null>(null)
  const [succes, setSucces] = useState<CommandeManuelleCreee | null>(null)
  const [cleIdempotence, setCleIdempotence] = useState(() => crypto.randomUUID())
  const [prerempli, setPrerempli] = useState(false)
  // La référence venue de « Transformer en commande » est déjà appliquée.
  const [referenceAppliquee, setReferenceAppliquee] = useState<string | null>(() => codeReference(referenceDemandee))
  const [aideReference, setAideReference] = useState<{ erreur: boolean; texte: string } | null>(null)
  const [clientConnu, setClientConnu] = useState(false)

  const comptoir = canal === 'comptoir'
  const avecLivraison = canal === 'whatsapp' || canal === 'telephone'

  useEffect(() => {
    const minuteur = setTimeout(() => setRechercheDiffusee(recherche.trim()), 250)
    return () => clearTimeout(minuteur)
  }, [recherche])

  // Tant que rien n'est tapé, « Vendre » montre le produit demandé seul.
  const filtreProduit = rechercheDiffusee === '' ? produitDemande : null
  const articles = useQuery({
    queryKey: ['articles-vendables', rechercheDiffusee, filtreProduit],
    queryFn: () => rechercherArticles(rechercheDiffusee, filtreProduit),
  })
  const zones = useQuery({ queryKey: ['zones-proposees'], queryFn: recupererZonesProposees, enabled: avecLivraison })

  // « Vendre » : l'article demandé est ajouté d'office, une seule fois, dès
  // que la liste arrive (ajustement pendant le rendu, pas dans un effet).
  if (!prerempli && produitDemande !== null && articles.data && filtreProduit !== null) {
    const candidats = articles.data.filter((article) => article.produit_id === produitDemande)
    const choisi = varianteDemandee !== null
      ? candidats.find((article) => article.variante_id === varianteDemandee)
      : candidats.length === 1 ? candidats[0] : undefined
    setPrerempli(true)
    if (choisi && !stockAffiche(choisi).epuise) {
      setLignes([{ article: choisi, quantite: 1 }])
    }
  }

  // Une référence collée dans une saisie neuve fait comme « Transformer en
  // commande » : l'article de la demande est ajouté, et une demande déjà
  // devenue commande est refusée avant même la saisie du client.
  const codeColle = codeReference(reference)
  const referenceReconnue = useQuery({
    queryKey: ['reference-whatsapp', codeColle],
    queryFn: () => reconnaitreReference(codeColle as string),
    enabled: codeColle !== null && codeColle !== referenceAppliquee,
    retry: false,
  })

  if (codeColle === null && referenceAppliquee !== null) {
    setReferenceAppliquee(null)
    setDejaTransformee(null)
    setAideReference(null)
  }

  if (codeColle !== null && codeColle !== referenceAppliquee && referenceReconnue.isSuccess) {
    const reconnue = referenceReconnue.data
    setReferenceAppliquee(codeColle)
    setDejaTransformee(reconnue?.commande ?? null)
    if (reconnue === null) {
      setAideReference({ erreur: true, texte: 'Cette référence n’existe pas dans votre boutique.' })
    } else if (reconnue.commande) {
      setAideReference(null)
    } else if (reconnue.article === null) {
      setAideReference({ erreur: false, texte: 'Choisissez l’article dans la liste : la demande ne dit pas lequel, ou il n’est plus en vente.' })
    } else if (stockAffiche(reconnue.article).epuise) {
      setAideReference({ erreur: true, texte: `L’article de la demande est épuisé : ${reconnue.article.nom}.` })
    } else {
      const article = reconnue.article
      const nomArticle = article.variante ? `${article.nom} — ${article.variante}` : article.nom
      setAideReference({ erreur: false, texte: `Article de la demande ajouté : ${nomArticle}.` })
      setLignes((actuelles) => (actuelles.some((ligne) => cleArticle(ligne.article) === cleArticle(article)) ? actuelles : [...actuelles, { article, quantite: 1 }]))
    }
  }

  /**
   * Un numéro connu préremplit nom, commune et quartier, sans écraser ce
   * que le commerçant a déjà tapé. Cherché quand le numéro est complet.
   */
  function changerTelephone(valeur: string) {
    setTelephone(valeur)
    setClientConnu(false)
    const chiffres = valeur.replace(/\D/g, '')
    if (chiffres.length < 10) {
      return
    }
    trouverClient(chiffres)
      .then((connu) => {
        if (!connu) {
          return
        }
        setClientConnu(true)
        setNom((actuel) => actuel || connu.nom || '')
        if (avecLivraison) {
          setCommune((actuelle) => actuelle || connu.commune || '')
          setQuartier((actuel) => actuel || connu.quartier || '')
        }
      })
      .catch(() => undefined)
  }

  const listeZones = zones.data?.zones ?? []
  const autreCommuneFixe = zones.data?.autreCommune ?? null
  const zonesExistent = listeZones.length > 0
  const communeHorsListe = zonesExistent && commune !== '' && !autreCommune && !listeZones.some((zone) => zone.nom === commune)
  const fraisCommune = fraisDeLivraison(listeZones, autreCommuneFixe, commune, autreCommune)
  const sousTotal = lignes.reduce((total, ligne) => total + ligne.article.prix * ligne.quantite, 0)
  const frais = avecLivraison ? (fraisCommune ?? 0) : 0
  const livraisonAConvenir = avecLivraison && fraisCommune === null
  const total = sousTotal + frais

  const dejaAjoutes = useMemo(() => new Set(lignes.map((ligne) => cleArticle(ligne.article))), [lignes])

  function ajouter(article: ArticleVendable) {
    setSucces(null)
    setLignes((actuelles) => {
      const existante = actuelles.find((ligne) => cleArticle(ligne.article) === cleArticle(article))
      if (existante) {
        return actuelles.map((ligne) =>
          ligne === existante ? { ...ligne, quantite: Math.min(ligne.quantite + 1, stockAffiche(article).maximum) } : ligne,
        )
      }
      return [...actuelles, { article, quantite: 1 }]
    })
  }

  function changerQuantite(cle: string, quantite: number) {
    setLignes((actuelles) =>
      actuelles
        .map((ligne) => (cleArticle(ligne.article) === cle ? { ...ligne, quantite } : ligne))
        .filter((ligne) => ligne.quantite > 0),
    )
  }

  const creation = useMutation({
    mutationFn: (confirmerDoublon: boolean) =>
      saisirCommande({
        canal: canal as CanalManuel,
        lignes: lignes.map((ligne) => ({ produit_id: ligne.article.produit_id, variante_id: ligne.article.variante_id, quantite: ligne.quantite })),
        client: avecLivraison || (comptoir && identifierClient && telephone.trim() !== '') ? { telephone, nom } : undefined,
        ...(avecLivraison ? { commune, autre_commune: autreCommune, quartier } : {}),
        reference: reference.trim() || undefined,
        cle_idempotence: cleIdempotence,
        confirmer_doublon: confirmerDoublon,
      }),
    onSuccess: (commande) => {
      queryClient.invalidateQueries({ queryKey: ['articles-vendables'] })
      queryClient.invalidateQueries({ queryKey: ['commandes'] })
      queryClient.invalidateQueries({ queryKey: ['demandes-whatsapp'] })
      queryClient.invalidateQueries({ queryKey: ['demandes-whatsapp-compte'] })
      if (comptoir) {
        setSucces(commande)
        setLignes([])
        setRecherche('')
        setTelephone('')
        setNom('')
        setClientConnu(false)
        setIdentifierClient(false)
        setReference('')
        setAfficherReference(false)
        setReferenceAppliquee(null)
        setAideReference(null)
        setCleIdempotence(crypto.randomUUID())
        return
      }
      navigate(`/admin/commandes/${commande.id}`)
    },
    onError: (erreur) => {
      if (axios.isAxiosError(erreur) && erreur.response?.status === 409) {
        // Une demande WhatsApp déjà transformée : un refus, rien à confirmer.
        if (erreur.response.data.deja_transformee) {
          setDejaTransformee(erreur.response.data.deja_transformee as { id: number; numero: string })
          return
        }
        setDoublons(erreur.response.data.doublons as DoublonPossible[])
        return
      }
      const champs = extraireErreursChamps(erreur)
      setErreurs(champs)
      const message = axios.isAxiosError(erreur) ? (erreur.response?.data?.message as string | undefined) : undefined
      setErreurGenerale(Object.keys(champs).length > 0 ? null : (message ?? "Impossible d'enregistrer la commande. Réessayez."))
    },
  })

  function envoyer(confirmerDoublon: boolean) {
    setErreurs({})
    setErreurGenerale(null)
    setDoublons(null)
    setDejaTransformee(null)
    creation.mutate(confirmerDoublon)
  }

  function soumettre(evenement: FormEvent) {
    evenement.preventDefault()
    if (canal === null) {
      setErreurGenerale('Choisissez le canal de la vente.')
      return
    }
    envoyer(false)
  }

  return (
    <form onSubmit={soumettre} noValidate className="mx-auto max-w-2xl space-y-6 pb-28">
      <div className="flex items-center gap-2">
        <BoutonRetour vers="/admin/commandes" />
        <h1 className="text-titre-page font-bold text-texte">Nouvelle commande</h1>
      </div>

      {succes && (
        <BandeauSucces
          message={`Vente ${succes.numero} encaissée : ${formaterMontant(succes.total)}.`}
          onFermer={() => setSucces(null)}
        />
      )}

      <fieldset>
        <legend className="mb-2 text-petit font-medium text-texte">
          Canal <span aria-hidden="true" className="text-danger">*</span>
        </legend>
        <div className="grid grid-cols-3 gap-2">
          {CANAUX.map(({ valeur, libelle, Icone }) => (
            <button
              key={valeur}
              type="button"
              aria-pressed={canal === valeur}
              onClick={() => {
                setCanal(valeur)
                setErreurGenerale(null)
              }}
              className={`flex h-14 cursor-pointer flex-col items-center justify-center gap-0.5 rounded-lg border text-petit font-medium transition-[background-color,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primaire ${
                canal === valeur ? 'border-primaire bg-primaire text-surface' : 'border-bordure bg-surface text-texte hover:border-primaire'
              }`}
            >
              <Icone aria-hidden="true" size={20} strokeWidth={1.75} />
              {libelle}
            </button>
          ))}
        </div>
      </fieldset>

      {avecLivraison && (
        <section className="space-y-3">
          <ChampTexte id="client.telephone" label="Téléphone" type="tel" requis valeur={telephone} onChange={changerTelephone} erreur={erreurs['client.telephone']} placeholder="07 01 02 03 04" />
          {clientConnu && <p className="-mt-2 text-petit text-succes">Client connu : nom et adresse repris de sa dernière commande.</p>}
          <ChampTexte id="client.nom" label="Nom (facultatif)" valeur={nom} onChange={setNom} erreur={erreurs['client.nom']} maxLength={255} />
          {zonesExistent ? (
            <div>
              <label htmlFor="commune" className="mb-1 block text-petit font-medium text-texte">
                Commune <span aria-hidden="true" className="text-danger">*</span>
              </label>
              <select
                id="commune"
                value={autreCommune ? AUTRE_COMMUNE : communeHorsListe ? '' : commune}
                onChange={(evenement) => {
                  const choix = evenement.target.value
                  setAutreCommune(choix === AUTRE_COMMUNE)
                  setCommune(choix === AUTRE_COMMUNE ? '' : choix)
                }}
                className="h-11 w-full rounded border border-bordure bg-surface px-3 text-corps text-texte focus:border-primaire focus:outline focus:outline-2 focus:outline-offset-1 focus:outline-primaire"
              >
                <option value="" disabled>
                  {communeHorsListe ? `${commune} (dernière commande) : choisissez` : 'Choisissez la commune'}
                </option>
                {listeZones.map((zone) => (
                  <option key={zone.id} value={zone.nom}>
                    {zone.nom} — {libelleFrais(zone.frais)}
                  </option>
                ))}
                <option value={AUTRE_COMMUNE}>{libelleOptionAutreCommune(autreCommuneFixe)}</option>
              </select>
              {!autreCommune && erreurs.commune && <p className="mt-1 text-petit text-danger">{erreurs.commune}</p>}
              {autreCommune && (
                <div className="mt-3">
                  <ChampTexte id="commune-autre" label="Sa commune" requis valeur={commune} onChange={setCommune} erreur={erreurs.commune} maxLength={100} placeholder="Bingerville" />
                </div>
              )}
            </div>
          ) : (
            <ChampTexte id="commune" label="Commune" requis valeur={commune} onChange={setCommune} erreur={erreurs.commune} maxLength={100} />
          )}
          <ChampTexte id="quartier" label="Quartier (facultatif)" valeur={quartier} onChange={setQuartier} erreur={erreurs.quartier} maxLength={150} />
        </section>
      )}

      {comptoir && (
        <div>
          {identifierClient ? (
            <div className="space-y-3">
              <ChampTexte id="client.telephone" label="Téléphone du client" type="tel" requis valeur={telephone} onChange={changerTelephone} erreur={erreurs['client.telephone']} />
              <ChampTexte id="client.nom" label="Nom du client" requis valeur={nom} onChange={setNom} erreur={erreurs['client.nom']} maxLength={255} />
            </div>
          ) : (
            <button type="button" onClick={() => setIdentifierClient(true)} className="cursor-pointer text-petit text-texte-secondaire underline underline-offset-2">
              Identifier le client (un habitué)
            </button>
          )}
        </div>
      )}

      <section className="space-y-3">
        <h2 className="text-titre-section font-semibold text-texte">Articles</h2>
        <div className="relative">
          <Search aria-hidden="true" size={20} strokeWidth={1.5} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-texte-secondaire" />
          <input
            type="search"
            aria-label="Rechercher un article"
            placeholder="Rechercher un article…"
            value={recherche}
            onChange={(evenement) => setRecherche(evenement.target.value)}
            className="h-11 w-full rounded border border-bordure bg-surface pl-10 pr-3 text-corps text-texte focus:border-primaire focus:outline focus:outline-2 focus:outline-offset-1 focus:outline-primaire"
          />
        </div>

        <ul aria-label="Résultats" className="max-h-72 divide-y divide-bordure overflow-y-auto rounded-lg border border-bordure">
          {articles.data?.length === 0 && <li className="p-3 text-petit text-texte-secondaire">Aucun article trouvé.</li>}
          {articles.data?.map((article) => {
            const stock = stockAffiche(article)
            return (
              <li key={cleArticle(article)}>
                <button
                  type="button"
                  disabled={stock.epuise}
                  onClick={() => ajouter(article)}
                  className="flex min-h-14 w-full cursor-pointer items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-surface-alt disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {article.photo ? (
                    <img src={article.photo} alt="" width={40} height={40} className="h-10 w-10 shrink-0 rounded object-cover" />
                  ) : (
                    <span className="h-10 w-10 shrink-0 rounded bg-surface-alt" />
                  )}
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-corps text-texte">{article.nom}</span>
                    <span className="block text-petit text-texte-secondaire">
                      {article.variante ? `${article.variante} · ` : ''}
                      {stock.texte}
                    </span>
                  </span>
                  <span className="shrink-0 text-corps tabular-nums text-texte">{formaterMontant(article.prix)}</span>
                  {dejaAjoutes.has(cleArticle(article)) && <span className="sr-only">(déjà dans la commande)</span>}
                </button>
              </li>
            )
          })}
        </ul>

        {lignes.length > 0 && (
          <ul aria-label="Commande" className="divide-y divide-bordure rounded-lg border border-primaire/40 bg-primaire/5">
            {lignes.map((ligne) => {
              const cle = cleArticle(ligne.article)
              const maximum = stockAffiche(ligne.article).maximum
              return (
                <li key={cle} className="flex flex-wrap items-center gap-2 p-3">
                  <span className="min-w-0 flex-1">
                    <span className="block text-corps text-texte">{ligne.article.nom}</span>
                    <span className="block text-petit text-texte-secondaire">
                      {ligne.article.variante ? `${ligne.article.variante} · ` : ''}
                      {formaterMontant(ligne.article.prix)}
                    </span>
                  </span>
                  <span className="flex items-center gap-1">
                    <button type="button" aria-label={`Une de moins : ${ligne.article.nom}`} onClick={() => changerQuantite(cle, ligne.quantite - 1)} className="flex h-11 w-11 cursor-pointer items-center justify-center rounded border border-bordure bg-surface">
                      {ligne.quantite === 1 ? <Trash2 aria-hidden="true" size={18} strokeWidth={1.5} className="text-danger" /> : <Minus aria-hidden="true" size={18} strokeWidth={1.5} />}
                    </button>
                    <span aria-label="Quantité" className="w-8 text-center text-corps font-medium tabular-nums">{ligne.quantite}</span>
                    <button type="button" aria-label={`Une de plus : ${ligne.article.nom}`} disabled={ligne.quantite >= maximum} onClick={() => changerQuantite(cle, ligne.quantite + 1)} className="flex h-11 w-11 cursor-pointer items-center justify-center rounded border border-bordure bg-surface disabled:cursor-not-allowed disabled:opacity-50">
                      <Plus aria-hidden="true" size={18} strokeWidth={1.5} />
                    </button>
                  </span>
                  <span className="w-24 text-right text-corps font-medium tabular-nums">{formaterMontant(ligne.article.prix * ligne.quantite)}</span>
                </li>
              )
            })}
          </ul>
        )}
        {erreurs.lignes && <p className="text-petit text-danger">{erreurs.lignes}</p>}
      </section>

      <div>
        {afficherReference ? (
          <>
            <ChampTexte id="reference" label="Référence WhatsApp (ou le message entier)" multiligne valeur={reference} onChange={setReference} erreur={erreurs.reference} />
            {aideReference && codeColle === referenceAppliquee && !erreurs.reference && (
              <p role="status" className={`mt-1 text-petit ${aideReference.erreur ? 'text-danger' : 'text-texte-secondaire'}`}>
                {aideReference.texte}
              </p>
            )}
          </>
        ) : (
          <button type="button" onClick={() => setAfficherReference(true)} className="cursor-pointer text-petit text-texte-secondaire underline underline-offset-2">
            Coller une référence WhatsApp
          </button>
        )}
      </div>

      {doublons && (
        <div role="alert" className="space-y-3 rounded-lg border border-alerte/40 bg-alerte/10 p-4">
          <p className="text-corps font-medium text-texte">Une commande semblable est déjà en cours :</p>
          <ul className="space-y-1">
            {doublons.map((doublon) => (
              <li key={doublon.id} className="text-corps">
                <Link to={`/admin/commandes/${doublon.id}`} target="_blank" className="font-medium text-primaire underline underline-offset-2">
                  {doublon.numero}
                </Link>{' '}
                — {doublon.statut_libelle}, {formaterMontant(doublon.total)}
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap gap-2">
            <Bouton type="button" variante="principal" chargement={creation.isPending} onClick={() => envoyer(true)}>
              Créer quand même
            </Bouton>
            <Bouton type="button" variante="tertiaire" onClick={() => setDoublons(null)}>
              Annuler
            </Bouton>
          </div>
        </div>
      )}

      {dejaTransformee && (
        <p role="alert" className="rounded border border-danger bg-danger/5 p-3 text-corps text-danger">
          Cette demande WhatsApp est déjà devenue la commande{' '}
          <Link to={`/admin/commandes/${dejaTransformee.id}`} className="font-semibold underline">
            {dejaTransformee.numero}
          </Link>
          . Une demande ne devient qu'une seule commande.
        </p>
      )}
      {erreurGenerale && <p role="alert" className="text-corps text-danger">{erreurGenerale}</p>}

      <div className="fixed inset-x-0 bottom-0 z-10 border-t border-bordure bg-surface p-3 sm:static sm:border-0 sm:bg-transparent sm:p-0">
        <div className="mx-auto max-w-2xl space-y-2">
          <div className="flex items-baseline justify-between text-corps text-texte-secondaire">
            <span>
              {avecLivraison ? `Livraison : ${livraisonAConvenir ? 'à convenir' : fraisCommune != null ? libelleFrais(fraisCommune) : 'selon la commune'}` : `${lignes.length} article(s)`}
            </span>
            <span className="text-titre-section font-semibold tabular-nums text-texte">{formaterMontant(total)}</span>
          </div>
          <Bouton type="submit" variante="principal" className="w-full" chargement={creation.isPending} disabled={lignes.length === 0} aria-describedby={lignes.length === 0 ? 'aide-enregistrer' : undefined}>
            {comptoir ? `Encaisser ${formaterMontant(total)}` : 'Enregistrer la commande'}
          </Bouton>
          {lignes.length === 0 && (
            <p id="aide-enregistrer" className="text-center text-petit text-texte-secondaire">
              Ajoutez au moins un article pour enregistrer.
            </p>
          )}
        </div>
      </div>
    </form>
  )
}
