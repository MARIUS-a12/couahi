/**
 * LE formulaire d'identité, unique (voir Étape 6A ter) : utilisé tel quel
 * par PageIdentiteEtablissement (commerçant, son propre établissement) et
 * PageIdentiteEtablissementSuperAdmin (n'importe lequel) — seul le
 * "service" injecté (api/identite.ts) change d'un appelant à l'autre.
 * Jamais deux versions de ce formulaire.
 */
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import axios from 'axios'
import { ImageOff, Loader2, Trash2, Upload } from 'lucide-react'
import type { HoraireJour, Horaires, IdentiteEtablissement, JourSemaine, ServiceIdentite } from '../api/identite'
import { JOURS_SEMAINE } from '../api/identite'
import { ratioContrasteAvecBlanc } from '../lib/couleurAccent'
import { confirmerSuppressionLogo } from '../lib/confirmations'
import { allerAuPremierChampEnErreur, extraireErreursChamps } from '../lib/erreursValidation'
import { BandeauSucces } from './BandeauSucces'
import { ChampTexte } from './ChampTexte'
import { EtatBouton } from './EtatBouton'
import { EtatChargement } from './EtatChargement'
import { EtatErreur } from './EtatErreur'

const LIBELLES_JOURS: Record<JourSemaine, string> = {
  lundi: 'Lundi',
  mardi: 'Mardi',
  mercredi: 'Mercredi',
  jeudi: 'Jeudi',
  vendredi: 'Vendredi',
  samedi: 'Samedi',
  dimanche: 'Dimanche',
}

const ORDRE_CHAMPS = [
  'nom',
  'description',
  'couleur_accent',
  'telephone_whatsapp',
  'telephone_fixe',
  'email_contact',
  'adresse',
  'lien_facebook',
  'lien_instagram',
  'lien_tiktok',
  'lien_site_web',
]

const HORAIRE_VIDE: HoraireJour = { ouverture: null, fermeture: null, ferme: true }

function extraireSuggestionCouleur(erreur: unknown): { message: string; suggestion: string } | null {
  if (!axios.isAxiosError(erreur) || erreur.response?.status !== 422) {
    return null
  }

  const donnees = erreur.response.data as { message?: string; couleur_accent_suggeree?: string }

  return donnees.couleur_accent_suggeree
    ? { message: donnees.message ?? '', suggestion: donnees.couleur_accent_suggeree }
    : null
}

export function FormulaireIdentiteEtablissement({ service }: { service: ServiceIdentite }) {
  const inputLogoRef = useRef<HTMLInputElement>(null)

  const [chargement, setChargement] = useState(true)
  const [erreurChargement, setErreurChargement] = useState(false)
  const [identite, setIdentite] = useState<IdentiteEtablissement | null>(null)

  const [nom, setNom] = useState('')
  const [description, setDescription] = useState('')
  const [couleurAccent, setCouleurAccent] = useState('')
  const [telephoneWhatsapp, setTelephoneWhatsapp] = useState('')
  const [telephoneFixe, setTelephoneFixe] = useState('')
  const [emailContact, setEmailContact] = useState('')
  const [adresse, setAdresse] = useState('')
  const [horaires, setHoraires] = useState<Horaires>({})
  const [lienFacebook, setLienFacebook] = useState('')
  const [lienInstagram, setLienInstagram] = useState('')
  const [lienTiktok, setLienTiktok] = useState('')
  const [lienSiteWeb, setLienSiteWeb] = useState('')

  const [enregistrement, setEnregistrement] = useState(false)
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [erreurGenerique, setErreurGenerique] = useState<string | null>(null)
  const [couleurSuggeree, setCouleurSuggeree] = useState<string | null>(null)
  const [messageSucces, setMessageSucces] = useState<string | null>(null)
  const [logoEnCours, setLogoEnCours] = useState(false)

  useEffect(() => {
    let annule = false

    service
      .recuperer()
      .then((donnees) => {
        if (!annule) appliquer(donnees)
      })
      .catch(() => {
        if (!annule) setErreurChargement(true)
      })
      .finally(() => {
        if (!annule) setChargement(false)
      })

    return () => {
      annule = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function recharger() {
    setChargement(true)
    setErreurChargement(false)

    service
      .recuperer()
      .then((donnees) => appliquer(donnees))
      .catch(() => setErreurChargement(true))
      .finally(() => setChargement(false))
  }

  function appliquer(donnees: IdentiteEtablissement) {
    setIdentite(donnees)
    setNom(donnees.nom)
    setDescription(donnees.description ?? '')
    setCouleurAccent(donnees.couleur_accent ?? donnees.couleur_accent_par_defaut)
    setTelephoneWhatsapp(donnees.telephone_whatsapp ?? '')
    setTelephoneFixe(donnees.telephone_fixe ?? '')
    setEmailContact(donnees.email_contact ?? '')
    setAdresse(donnees.adresse ?? '')
    setHoraires(donnees.horaires ?? {})
    setLienFacebook(donnees.lien_facebook ?? '')
    setLienInstagram(donnees.lien_instagram ?? '')
    setLienTiktok(donnees.lien_tiktok ?? '')
    setLienSiteWeb(donnees.lien_site_web ?? '')
  }

  function changerHoraireJour(jour: JourSemaine, champ: 'ouverture' | 'fermeture' | 'ferme', valeur: string | boolean) {
    setHoraires((actuels) => ({
      ...actuels,
      [jour]: { ...(actuels[jour] ?? HORAIRE_VIDE), [champ]: valeur },
    }))
  }

  async function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setErreurGenerique(null)
    setErreurs({})
    setCouleurSuggeree(null)
    setEnregistrement(true)

    try {
      const misAJour = await service.mettreAJour({
        nom,
        description: description || null,
        couleur_accent: couleurAccent || null,
        telephone_whatsapp: telephoneWhatsapp || null,
        telephone_fixe: telephoneFixe || null,
        email_contact: emailContact || null,
        adresse: adresse || null,
        horaires: Object.keys(horaires).length > 0 ? horaires : null,
        lien_facebook: lienFacebook || null,
        lien_instagram: lienInstagram || null,
        lien_tiktok: lienTiktok || null,
        lien_site_web: lienSiteWeb || null,
      })
      appliquer(misAJour)
      setMessageSucces('Identité enregistrée.')
    } catch (erreur) {
      const suggestion = extraireSuggestionCouleur(erreur)

      if (suggestion) {
        setErreurs({ couleur_accent: suggestion.message })
        setCouleurSuggeree(suggestion.suggestion)
      } else {
        const champs = extraireErreursChamps(erreur)
        setErreurs(champs)
        setErreurGenerique(
          Object.keys(champs).length === 0
            ? "Impossible d'enregistrer l'identité. Vérifiez votre connexion et réessayez."
            : null,
        )
        allerAuPremierChampEnErreur(champs, ORDRE_CHAMPS)
      }
    } finally {
      setEnregistrement(false)
    }
  }

  async function envoyerLogo(fichier: File) {
    setLogoEnCours(true)
    try {
      const misAJour = await service.uploaderLogo(fichier)
      appliquer(misAJour)
    } catch {
      setErreurGenerique("Impossible d'envoyer ce logo. Vérifiez qu'il s'agit bien d'une image.")
    } finally {
      setLogoEnCours(false)
    }
  }

  async function retirerLogo() {
    if (!confirmerSuppressionLogo()) return

    setLogoEnCours(true)
    try {
      const misAJour = await service.supprimerLogo()
      appliquer(misAJour)
    } finally {
      setLogoEnCours(false)
    }
  }

  if (chargement) {
    return <EtatChargement />
  }

  if (erreurChargement || !identite) {
    return (
      <EtatErreur erreur={new Error("Impossible de charger l'identité de cet établissement.")} onReessayer={recharger} />
    )
  }

  const ratio = couleurAccent.match(/^#[0-9a-fA-F]{6}$/) ? ratioContrasteAvecBlanc(couleurAccent) : null

  return (
    <form onSubmit={soumettre} className="space-y-8 pb-4">
      {messageSucces && <BandeauSucces message={messageSucces} onFermer={() => setMessageSucces(null)} />}

      {erreurGenerique && (
        <p role="alert" className="animate-entree-haut border border-danger bg-surface p-3 text-corps text-danger">
          {erreurGenerique}
        </p>
      )}

      <section className="space-y-4">
        <h2 className="text-titre-section font-semibold text-texte">Identité</h2>

        <ChampTexte id="nom" label="Nom de l'établissement" requis valeur={nom} onChange={setNom} erreur={erreurs.nom} />
        <ChampTexte
          id="description"
          label="Description (500 caractères maximum)"
          multiligne
          valeur={description}
          onChange={(v) => setDescription(v.slice(0, 500))}
          erreur={erreurs.description}
        />
        <p className="-mt-2 text-right text-petit text-texte-secondaire">{description.length}/500</p>

        <div>
          <span className="mb-1 block text-petit font-medium text-texte">Logo</span>
          <div className="flex items-center gap-3">
            <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded border border-bordure bg-surface-alt">
              {identite.logo ? (
                <picture>
                  {identite.logo.petit.webp && <source srcSet={identite.logo.petit.webp} type="image/webp" />}
                  <img
                    src={identite.logo.petit.jpg ?? identite.logo.petit.webp ?? undefined}
                    alt=""
                    className="h-full w-full object-cover"
                  />
                </picture>
              ) : (
                <ImageOff aria-hidden="true" size={24} strokeWidth={1.5} className="text-texte-secondaire" />
              )}
            </div>

            <button
              type="button"
              disabled={logoEnCours}
              onClick={() => inputLogoRef.current?.click()}
              className="flex h-11 cursor-pointer items-center gap-1.5 rounded border border-bordure px-3 text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {logoEnCours ? (
                <Loader2 aria-hidden="true" size={18} strokeWidth={1.5} className="animate-spin" />
              ) : (
                <Upload aria-hidden="true" size={18} strokeWidth={1.5} />
              )}
              {identite.logo ? 'Changer le logo' : 'Envoyer un logo'}
            </button>

            {identite.logo && (
              <button
                type="button"
                disabled={logoEnCours}
                onClick={retirerLogo}
                aria-label="Retirer le logo"
                className="flex h-11 w-11 cursor-pointer items-center justify-center rounded text-danger transition-[background-color,transform] hover:bg-danger/10 active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <Trash2 aria-hidden="true" size={18} strokeWidth={1.5} />
              </button>
            )}

            <input
              ref={inputLogoRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(evenement) => {
                const fichier = evenement.target.files?.[0]
                if (fichier) envoyerLogo(fichier)
                evenement.target.value = ''
              }}
            />
          </div>
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-titre-section font-semibold text-texte">Couleur de marque</h2>
        <div className="flex items-center gap-2">
          <input
            type="color"
            value={couleurAccent.match(/^#[0-9a-fA-F]{6}$/) ? couleurAccent : (identite?.couleur_accent_par_defaut ?? '#000000')}
            onChange={(e) => {
              setCouleurAccent(e.target.value)
              setCouleurSuggeree(null)
            }}
            aria-label="Choisir la couleur de marque"
            className="h-11 w-11 shrink-0 cursor-pointer rounded border border-bordure bg-surface p-1"
          />
          <input
            type="text"
            value={couleurAccent}
            onChange={(e) => {
              setCouleurAccent(e.target.value)
              setCouleurSuggeree(null)
            }}
            placeholder={identite?.couleur_accent_par_defaut.toUpperCase()}
            className="h-11 w-32 rounded border border-bordure bg-surface px-3 text-corps uppercase tabular-nums text-texte transition-colors focus:border-primaire focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1"
          />
          {ratio !== null && (
            <span className="text-petit text-texte-secondaire">
              Contraste avec du texte blanc : <span className="tabular-nums font-medium">{ratio.toFixed(1)}:1</span>{' '}
              {ratio >= 4.5 ? '— lisible' : '— trop clair'}
            </span>
          )}
        </div>

        {erreurs.couleur_accent && (
          <div className="animate-entree-champ space-y-2">
            <p className="text-petit text-danger">{erreurs.couleur_accent}</p>
            {couleurSuggeree && (
              <button
                type="button"
                onClick={() => {
                  setCouleurAccent(couleurSuggeree)
                  setCouleurSuggeree(null)
                  setErreurs((e) => ({ ...e, couleur_accent: '' }))
                }}
                className="inline-flex h-11 cursor-pointer items-center gap-1.5 rounded border border-bordure px-3 text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
              >
                <span
                  aria-hidden="true"
                  className="h-4 w-4 rounded-full border border-bordure"
                  style={{ backgroundColor: couleurSuggeree }}
                />
                Utiliser {couleurSuggeree.toUpperCase()} à la place
              </button>
            )}
          </div>
        )}

        {ratio !== null && ratio >= 1 && (
          <div className="flex flex-wrap items-center gap-3 border border-bordure p-3">
            <span
              className="flex h-11 items-center rounded px-4 text-corps font-medium"
              style={{ backgroundColor: couleurAccent, color: ratio >= 4.5 ? '#fff' : '#000' }}
            >
              Commander sur WhatsApp
            </span>
            <span className="truncate text-titre-section font-semibold" style={{ color: couleurAccent }}>
              {nom || 'Nom de la boutique'}
            </span>
          </div>
        )}
      </section>

      <section className="space-y-4">
        <h2 className="text-titre-section font-semibold text-texte">Contact</h2>
        <ChampTexte
          id="telephone_whatsapp"
          label="Numéro WhatsApp"
          placeholder="07 01 02 03 04"
          valeur={telephoneWhatsapp}
          onChange={setTelephoneWhatsapp}
          erreur={erreurs.telephone_whatsapp}
        />
        <ChampTexte
          id="telephone_fixe"
          label="Téléphone (optionnel)"
          valeur={telephoneFixe}
          onChange={setTelephoneFixe}
          erreur={erreurs.telephone_fixe}
        />
        <ChampTexte
          id="email_contact"
          label="Email de contact (optionnel)"
          valeur={emailContact}
          onChange={setEmailContact}
          erreur={erreurs.email_contact}
        />
        <ChampTexte id="adresse" label="Adresse (optionnel)" valeur={adresse} onChange={setAdresse} erreur={erreurs.adresse} />
      </section>

      <section className="space-y-3">
        <h2 className="text-titre-section font-semibold text-texte">Horaires</h2>
        <div className="space-y-2">
          {JOURS_SEMAINE.map((jour) => {
            const entree = horaires[jour] ?? HORAIRE_VIDE

            return (
              <div key={jour} className="flex flex-wrap items-center gap-3">
                <span className="w-24 shrink-0 text-corps text-texte">{LIBELLES_JOURS[jour]}</span>
                <label className="flex h-11 cursor-pointer items-center gap-1.5 text-petit text-texte-secondaire">
                  <input
                    type="checkbox"
                    checked={entree.ferme}
                    onChange={(e) => changerHoraireJour(jour, 'ferme', e.target.checked)}
                    className="h-4 w-4 cursor-pointer"
                  />
                  Fermé
                </label>
                {!entree.ferme && (
                  <>
                    <input
                      type="time"
                      value={entree.ouverture ?? ''}
                      onChange={(e) => changerHoraireJour(jour, 'ouverture', e.target.value)}
                      className="h-11 rounded border border-bordure bg-surface px-2 text-corps tabular-nums text-texte"
                    />
                    <span className="text-petit text-texte-secondaire">à</span>
                    <input
                      type="time"
                      value={entree.fermeture ?? ''}
                      onChange={(e) => changerHoraireJour(jour, 'fermeture', e.target.value)}
                      className="h-11 rounded border border-bordure bg-surface px-2 text-corps tabular-nums text-texte"
                    />
                  </>
                )}
              </div>
            )
          })}
        </div>
      </section>

      <section className="space-y-4">
        <h2 className="text-titre-section font-semibold text-texte">Réseaux sociaux</h2>
        <ChampTexte
          id="lien_facebook"
          label="Facebook"
          placeholder="https://facebook.com/votre-page"
          valeur={lienFacebook}
          onChange={setLienFacebook}
          erreur={erreurs.lien_facebook}
        />
        <ChampTexte
          id="lien_instagram"
          label="Instagram"
          placeholder="https://instagram.com/votre-compte"
          valeur={lienInstagram}
          onChange={setLienInstagram}
          erreur={erreurs.lien_instagram}
        />
        <ChampTexte
          id="lien_tiktok"
          label="TikTok"
          placeholder="https://tiktok.com/@votre-compte"
          valeur={lienTiktok}
          onChange={setLienTiktok}
          erreur={erreurs.lien_tiktok}
        />
        <ChampTexte
          id="lien_site_web"
          label="Site web"
          placeholder="https://votre-site.com"
          valeur={lienSiteWeb}
          onChange={setLienSiteWeb}
          erreur={erreurs.lien_site_web}
        />
      </section>

      <div className="sticky bottom-0 -mx-4 flex gap-3 border-t border-bordure bg-surface px-4 py-3 md:static md:mx-0 md:border-t-0 md:bg-transparent md:px-0 md:py-0">
        <button
          type="submit"
          disabled={enregistrement}
          className="h-11 flex-1 cursor-pointer rounded bg-primaire text-corps font-medium text-surface transition-[background-color,transform] hover:bg-primaire-fonce active:scale-[0.97] active:bg-primaire-fonce focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100"
        >
          <EtatBouton chargement={enregistrement}>Enregistrer l'identité</EtatBouton>
        </button>
      </div>
    </form>
  )
}
