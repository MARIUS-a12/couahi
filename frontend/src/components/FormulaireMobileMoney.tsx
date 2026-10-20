/**
 * Section "Paiement par Mobile Money" de la page de paiement (Étape 13),
 * sous celle de CinetPay. Un seul formulaire pour les deux chemins
 * (commerçant, super-admin) : seul le service injecté change, comme
 * FormulairePaiement.
 *
 * L'aperçu montre EN DIRECT ce que verra le client sur sa page de
 * confirmation, avec un montant d'exemple. Le nom du titulaire est la
 * protection du client contre un numéro falsifié (son application l'affiche
 * avant de valider) : un nom qui risque de ne pas correspondre (un seul mot,
 * des chiffres, un nom commercial...) déclenche un avertissement visible.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ShieldCheck, Smartphone } from 'lucide-react'
import type { FormEvent } from 'react'
import { useState } from 'react'
import type { MobileMoneyEtablissement, OperateurMobileMoney, ServiceMobileMoney } from '../api/mobileMoney'
import { LIBELLES_OPERATEUR } from '../api/mobileMoney'
import { extraireErreursChamps } from '../lib/erreursValidation'
import { formaterMontant } from '../lib/formatage'
import { Bouton } from './Bouton'
import { EtatChargement } from './EtatChargement'
import { EtatErreur } from './EtatErreur'

const MONTANT_EXEMPLE = 20309

const CLASSES_CHAMP =
  'h-11 w-full rounded border bg-surface px-3 text-corps text-texte transition-colors focus:outline focus:outline-2 focus:outline-primaire focus:outline-offset-1'

/** "0707080910" ou "+225 07 07 08 09 10" → "07 07 08 09 10", sinon tel quel. */
function formaterNumeroSaisi(saisie: string): string {
  const chiffres = saisie.replace(/\s/g, '').replace(/^\+225/, '')

  return /^0\d{9}$/.test(chiffres) ? chiffres.replace(/(\d{2})(?=\d)/g, '$1 ') : saisie.trim()
}

/** Raison de douter que ce nom soit celui du compte, ou null. */
function doutesSurLeNom(nom: string): string | null {
  const propre = nom.trim()

  if (propre === '') {
    return null
  }

  if (/\d/.test(propre)) {
    return 'Ce nom contient des chiffres.'
  }

  if (propre.split(/\s+/).length < 2) {
    return 'Un seul mot : il manque sans doute le nom ou le prénom.'
  }

  if (/\b(boutique|maquis|restaurant|shop|store|sarl|ets|établissement|etablissement)\b/i.test(propre)) {
    return "Ceci ressemble au nom de votre commerce, pas à celui du titulaire du compte."
  }

  return null
}

export function FormulaireMobileMoney({ service, cle }: { service: ServiceMobileMoney; cle: unknown[] }) {
  const { data, isPending, isError, error, refetch } = useQuery({ queryKey: cle, queryFn: service.recuperer })

  if (isPending) {
    return <EtatChargement />
  }

  if (isError) {
    return <EtatErreur erreur={error} onReessayer={() => refetch()} />
  }

  // Jamais remonté (pas de "key" dérivée des données) : un remontage après
  // l'enregistrement — même le tout premier, qui fait passer "configure" à
  // vrai — effaçait le message "Enregistré" au moment de l'afficher. Le
  // retrait vide lui-même les champs (voir Formulaire).
  return <Formulaire initial={data} service={service} cle={cle} />
}

function Formulaire({
  initial,
  service,
  cle,
}: {
  initial: MobileMoneyEtablissement
  service: ServiceMobileMoney
  cle: unknown[]
}) {
  const queryClient = useQueryClient()
  const [numero, setNumero] = useState(initial.numero_affiche ?? '')
  const [operateur, setOperateur] = useState<OperateurMobileMoney | ''>(initial.operateur ?? '')
  const [beneficiaire, setBeneficiaire] = useState(initial.beneficiaire ?? '')
  const [erreurs, setErreurs] = useState<Record<string, string>>({})
  const [enregistre, setEnregistre] = useState(false)

  const enregistrement = useMutation({
    mutationFn: () => service.configurer({ mm_numero: numero, mm_operateur: operateur, mm_beneficiaire: beneficiaire }),
    onSuccess: (donnees) => {
      setErreurs({})
      setEnregistre(true)
      queryClient.setQueryData(cle, donnees)
    },
    onError: (erreur) => {
      setEnregistre(false)
      setErreurs(extraireErreursChamps(erreur))
    },
  })

  const retrait = useMutation({
    mutationFn: service.retirer,
    onSuccess: () => {
      setNumero('')
      setOperateur('')
      setBeneficiaire('')
      setEnregistre(false)
      queryClient.invalidateQueries({ queryKey: cle })
    },
  })

  function soumettre(evenement: FormEvent<HTMLFormElement>) {
    evenement.preventDefault()
    setEnregistre(false)
    enregistrement.mutate()
  }

  function demanderRetrait() {
    const confirme = window.confirm(
      'Le bouton « Payer par Mobile Money » disparaîtra aussitôt de votre boutique. Vos clients pourront ' +
        'toujours commander par WhatsApp.\n\nRetirer le Mobile Money ?',
    )

    if (confirme) {
      retrait.mutate()
    }
  }

  const doute = doutesSurLeNom(beneficiaire)
  const numeroApercu = numero.trim() === '' ? '07 00 00 00 00' : formaterNumeroSaisi(numero)
  const operateurApercu = operateur ? LIBELLES_OPERATEUR[operateur] : 'votre opérateur'

  return (
    <section className="space-y-4">
      <div>
        <h2 className="flex items-center gap-2 text-titre-section font-semibold text-texte">
          <Smartphone aria-hidden="true" size={22} strokeWidth={1.75} />
          Paiement par Mobile Money
        </h2>
        <p className="mt-1 text-corps text-texte-secondaire">
          Vos clients verront votre numéro et vous paieront par transfert ; vous devrez confirmer vous-même
          chaque paiement reçu, depuis le détail de la commande.
        </p>
      </div>

      {initial.cinetpay_prioritaire && (
        <p className="flex gap-2 rounded-md border border-bleu-info/40 bg-bleu-info/10 p-3 text-petit text-texte">
          <ShieldCheck aria-hidden="true" size={18} strokeWidth={1.75} className="mt-0.5 shrink-0 text-bleu-info-texte" />
          CinetPay est configuré : c'est lui que vos clients voient. Le Mobile Money ne leur sera proposé que si
          vous retirez CinetPay — jamais les deux à la fois.
        </p>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px]">
        <form onSubmit={soumettre} className="space-y-4 rounded-lg border border-bordure bg-surface p-4 sm:p-5">
          {initial.configure && (
            <p className="flex items-center gap-1.5 text-corps font-medium text-succes">
              <span aria-hidden="true" className="h-2 w-2 rounded-full bg-succes" />
              Mobile Money actif
            </p>
          )}

          <div>
            <label htmlFor="mm_operateur" className="mb-1 block text-petit font-medium text-texte">
              Opérateur <span aria-hidden="true" className="text-danger">*</span>
            </label>
            <select
              id="mm_operateur"
              value={operateur}
              onChange={(evenement) => setOperateur(evenement.target.value as OperateurMobileMoney | '')}
              className={`${CLASSES_CHAMP} ${erreurs.mm_operateur ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
            >
              <option value="">Choisir l'opérateur</option>
              {(Object.keys(LIBELLES_OPERATEUR) as OperateurMobileMoney[]).map((valeur) => (
                <option key={valeur} value={valeur}>
                  {LIBELLES_OPERATEUR[valeur]}
                </option>
              ))}
            </select>
            {erreurs.mm_operateur && <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.mm_operateur}</p>}
          </div>

          <div>
            <label htmlFor="mm_numero" className="mb-1 block text-petit font-medium text-texte">
              Numéro qui reçoit l'argent <span aria-hidden="true" className="text-danger">*</span>
            </label>
            <input
              id="mm_numero"
              type="tel"
              inputMode="tel"
              autoComplete="off"
              placeholder="07 01 02 03 04"
              value={numero}
              onChange={(evenement) => setNumero(evenement.target.value)}
              className={`${CLASSES_CHAMP} tabular-nums ${erreurs.mm_numero ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
            />
            {erreurs.mm_numero && <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.mm_numero}</p>}
          </div>

          <div>
            <label htmlFor="mm_beneficiaire" className="mb-1 block text-petit font-medium text-texte">
              Nom du titulaire du compte <span aria-hidden="true" className="text-danger">*</span>
            </label>
            <input
              id="mm_beneficiaire"
              type="text"
              maxLength={100}
              autoComplete="off"
              placeholder="KOFFI YAO ARMAND"
              value={beneficiaire}
              onChange={(evenement) => setBeneficiaire(evenement.target.value)}
              className={`${CLASSES_CHAMP} ${erreurs.mm_beneficiaire ? 'border-danger' : 'border-bordure focus:border-primaire'}`}
            />
            {erreurs.mm_beneficiaire ? (
              <p className="mt-1 animate-entree-champ text-petit text-danger">{erreurs.mm_beneficiaire}</p>
            ) : (
              <p
                className={`mt-2 flex gap-2 rounded-md p-2.5 text-petit ${
                  doute ? 'animate-entree-champ border border-alerte bg-alerte/10 text-alerte' : 'bg-surface-alt text-texte-secondaire'
                }`}
              >
                <AlertTriangle aria-hidden="true" size={16} strokeWidth={1.75} className="mt-px shrink-0" />
                <span>
                  {doute && <span className="font-semibold">{doute} </span>}
                  Ce nom doit être exactement celui de votre compte. Vos clients le vérifieront avant de payer.
                </span>
              </p>
            )}
          </div>

          {enregistre && (
            <p role="status" className="animate-entree-champ text-petit font-medium text-succes">
              Enregistré. {initial.cinetpay_prioritaire ? '' : 'Vos clients voient désormais « Payer par Mobile Money ».'}
            </p>
          )}

          <div className="flex flex-wrap gap-3">
            <Bouton variante="principal" type="submit" chargement={enregistrement.isPending}>
              Enregistrer
            </Bouton>
            {initial.configure && (
              <Bouton variante="danger" onClick={demanderRetrait} chargement={retrait.isPending}>
                Retirer le Mobile Money
              </Bouton>
            )}
          </div>
        </form>

        <div aria-label="Aperçu pour vos clients" className="h-fit rounded-lg border border-dashed border-bordure-forte bg-surface-alt p-3">
          <p className="mb-2 text-petit font-medium text-texte-secondaire">Ce que verra votre client</p>
          <div className="divide-y divide-bordure rounded-md border-2 border-texte bg-surface text-texte">
            <div className="p-3">
              <p className="text-petit font-semibold">Payez par {operateurApercu}</p>
              <p className="mt-1 text-[11px] text-texte-secondaire">Montant exact à envoyer</p>
              <p className="text-titre-section font-bold tabular-nums">{formaterMontant(MONTANT_EXEMPLE)}</p>
            </div>
            <div className="p-3">
              <p className="text-[11px] text-texte-secondaire">Au numéro {operateurApercu}</p>
              <p className="text-corps font-bold tracking-wide tabular-nums">{numeroApercu}</p>
            </div>
            <div className="p-3">
              <p className="text-[11px] text-texte-secondaire">Au nom de</p>
              <p className="text-corps font-semibold">{beneficiaire.trim() || 'Nom du titulaire'}</p>
              <p className="mt-1 text-[11px]">Vérifiez que ce nom s'affiche dans votre application avant de valider.</p>
            </div>
          </div>
          <p className="mt-2 text-[11px] text-texte-secondaire">Montant d'exemple — le vrai total de chaque commande s'affiche à sa place.</p>
        </div>
      </div>
    </section>
  )
}
