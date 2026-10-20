/**
 * Formulaire UNIQUE de configuration du paiement CinetPay (Étape 6C-1),
 * partagé par les deux chemins d'accès (commerçant, super-admin) — seul le
 * service injecté change (voir api/paiement.ts), exactement comme
 * FormulaireIdentiteEtablissement (même convention de chargement : un
 * useEffect au montage, pas react-query — le service lui-même n'est pas une
 * valeur sérialisable utilisable comme clé de requête). Le secret ne se
 * relit jamais : une fois configuré, l'écran montre un état "Paiement
 * actif" + un bouton "Remplacer les identifiants", jamais les champs
 * pré-remplis. Configuré n'est pas disponible : tant que l'intégration
 * CinetPay n'est pas active sur la plateforme, l'état dit "Identifiants
 * enregistrés" et que les clients ne voient pas encore "Payer maintenant".
 */
import { Eye, EyeOff } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import type { PaiementEtablissement, ServicePaiement } from '../api/paiement'
import { Bouton } from './Bouton'
import { ChampTexte } from './ChampTexte'
import { EtatChargement } from './EtatChargement'
import { EtatErreur } from './EtatErreur'
import { extraireErreursChamps } from '../lib/erreursValidation'

export function FormulairePaiement({ service }: { service: ServicePaiement }) {
  const [chargement, setChargement] = useState(true)
  const [erreurChargement, setErreurChargement] = useState(false)
  const [paiement, setPaiement] = useState<PaiementEtablissement | null>(null)

  const [formulaireOuvert, setFormulaireOuvert] = useState(false)
  const [siteId, setSiteId] = useState('')
  const [cleApi, setCleApi] = useState('')
  const [secret, setSecret] = useState('')
  const [secretVisible, setSecretVisible] = useState(false)
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [enregistrement, setEnregistrement] = useState(false)
  const [suppressionEnCours, setSuppressionEnCours] = useState(false)

  function charger() {
    setChargement(true)
    setErreurChargement(false)

    service
      .recuperer()
      .then((donnees) => setPaiement(donnees))
      .catch(() => setErreurChargement(true))
      .finally(() => setChargement(false))
  }

  useEffect(() => {
    let annule = false

    service
      .recuperer()
      .then((donnees) => {
        if (!annule) setPaiement(donnees)
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

  async function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setEnregistrement(true)
    setErreurs({})

    try {
      const donnees = await service.configurer({
        cinetpay_site_id: siteId,
        cinetpay_cle_api: cleApi,
        cinetpay_secret: secret,
      })
      setPaiement(donnees)
      setFormulaireOuvert(false)
      setSiteId('')
      setCleApi('')
      setSecret('')
    } catch (erreur) {
      setErreurs(extraireErreursChamps(erreur))
    } finally {
      setEnregistrement(false)
    }
  }

  async function demanderSuppression() {
    const confirme = window.confirm(
      (paiement?.disponible
        ? 'Le bouton « Payer maintenant » disparaîtra aussitôt de la vitrine. Vos clients pourront toujours ' +
          'commander par WhatsApp.\n\n'
        : '') + 'Retirer la configuration de paiement ?',
    )

    if (!confirme) {
      return
    }

    setSuppressionEnCours(true)
    await service.supprimer()
    setSuppressionEnCours(false)
    charger()
  }

  if (chargement) {
    return <EtatChargement />
  }

  if (erreurChargement || !paiement) {
    return <EtatErreur erreur={new Error('Impossible de charger la configuration.')} onReessayer={charger} />
  }

  const afficherFormulaire = formulaireOuvert || !paiement.configure

  return (
    <div className="space-y-6">
      <p className="text-corps text-texte-secondaire">
        L'argent de vos ventes arrive directement sur VOTRE compte CinetPay — jamais sur un compte de la
        plateforme. C'est pour ça que vous fournissez vos propres identifiants, les mêmes que ceux de votre
        tableau de bord CinetPay.
      </p>

      {!afficherFormulaire && paiement.configure && (
        <div className="rounded-lg border border-bordure bg-surface p-4 sm:p-5">
          {paiement.disponible ? (
            <p className="flex items-center gap-1.5 text-corps font-medium text-succes">
              <span aria-hidden="true" className="h-2 w-2 rounded-full bg-succes" />
              Paiement actif
            </p>
          ) : (
            <>
              <p className="flex items-center gap-1.5 text-corps font-medium text-texte">
                <span aria-hidden="true" className="h-2 w-2 rounded-full bg-bordure-forte" />
                Identifiants enregistrés
              </p>
              <p className="mt-1 text-petit text-texte-secondaire">
                Le paiement en ligne n'est pas encore ouvert sur la plateforme : vos clients ne voient pas
                « Payer maintenant ». Ils voient le Mobile Money s'il est configuré ci-dessous, sinon le panier et
                WhatsApp.
              </p>
            </>
          )}
          <p className="mt-1 text-petit text-texte-secondaire">
            Configuré le{' '}
            {paiement.configure_le &&
              new Date(paiement.configure_le).toLocaleDateString('fr-FR', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
              })}
          </p>
          <dl className="mt-3 space-y-1 text-petit text-texte-secondaire">
            <div className="flex gap-2">
              <dt className="font-medium text-texte">Site ID :</dt>
              <dd className="tabular-nums">{paiement.cinetpay_site_id_masque}</dd>
            </div>
            <div className="flex gap-2">
              <dt className="font-medium text-texte">Clé API :</dt>
              <dd className="tabular-nums">{paiement.cinetpay_cle_api_masque}</dd>
            </div>
          </dl>

          <div className="mt-4 flex flex-wrap gap-3">
            <Bouton variante="secondaire" onClick={() => setFormulaireOuvert(true)}>
              Remplacer les identifiants
            </Bouton>
            <Bouton variante="danger" onClick={demanderSuppression} chargement={suppressionEnCours}>
              Retirer la configuration
            </Bouton>
          </div>
        </div>
      )}

      {!afficherFormulaire && !paiement.configure && (
        <div className="rounded-lg border border-bordure bg-surface p-4 sm:p-5">
          <p className="flex items-center gap-1.5 text-corps font-medium text-texte-secondaire">
            <span aria-hidden="true" className="h-2 w-2 rounded-full bg-bordure-forte" />
            Paiement non configuré
          </p>
          <p className="mt-1 text-petit text-texte-secondaire">
            Vos clients peuvent déjà commander par WhatsApp en attendant — rien ne les en empêche.
          </p>
          <Bouton variante="principal" className="mt-4" onClick={() => setFormulaireOuvert(true)}>
            Configurer le paiement
          </Bouton>
        </div>
      )}

      {afficherFormulaire && (
        <form onSubmit={soumettre} className="space-y-4 rounded-lg border border-bordure bg-surface p-4 sm:p-5">
          <div className="rounded-md border border-bordure-forte bg-surface-alt p-3 text-petit text-texte-secondaire">
            Ces informations se trouvent dans votre tableau de bord CinetPay, sous « Intégration » →
            « Identifiants API ».
          </div>

          <ChampTexte
            id="cinetpay_site_id"
            label="Site ID"
            valeur={siteId}
            onChange={setSiteId}
            erreur={erreurs.cinetpay_site_id}
            requis
          />
          <ChampTexte
            id="cinetpay_cle_api"
            label="Clé API"
            valeur={cleApi}
            onChange={setCleApi}
            erreur={erreurs.cinetpay_cle_api}
            requis
          />

          <div>
            <label htmlFor="cinetpay_secret" className="mb-1 block text-petit font-medium text-texte">
              Secret <span aria-hidden="true" className="text-danger">*</span>
            </label>
            <div className="relative">
              <input
                id="cinetpay_secret"
                type={secretVisible ? 'text' : 'password'}
                autoComplete="off"
                required
                value={secret}
                onChange={(evenement) => setSecret(evenement.target.value)}
                className={`h-11 w-full rounded border bg-surface px-3 pr-11 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1 ${
                  erreurs.cinetpay_secret ? 'border-danger' : 'border-bordure focus:border-primaire'
                }`}
              />
              <button
                type="button"
                onClick={() => setSecretVisible((v) => !v)}
                aria-label={secretVisible ? 'Masquer le secret' : 'Afficher le secret'}
                className="absolute right-1 top-1/2 flex h-9 w-9 -translate-y-1/2 cursor-pointer items-center justify-center rounded text-texte-secondaire transition-colors hover:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1"
              >
                {secretVisible ? (
                  <EyeOff aria-hidden="true" size={18} strokeWidth={1.5} />
                ) : (
                  <Eye aria-hidden="true" size={18} strokeWidth={1.5} />
                )}
              </button>
            </div>
            {erreurs.cinetpay_secret && (
              <p className="animate-entree-champ mt-1 text-petit text-danger">{erreurs.cinetpay_secret}</p>
            )}
          </div>

          <div className="flex gap-3">
            {paiement.configure && (
              <Bouton variante="tertiaire" type="button" onClick={() => setFormulaireOuvert(false)}>
                Annuler
              </Bouton>
            )}
            <Bouton variante="principal" type="submit" chargement={enregistrement}>
              Enregistrer
            </Bouton>
          </div>
        </form>
      )}
    </div>
  )
}
