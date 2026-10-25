/**
 * Écran public "/commande/:numero?jeton=..." — confirmation après validation
 * de la commande. Le numéro seul se devine (CMD-000847) : sans le jeton
 * d'accès généré à la création, le serveur renvoie 404, affiché ici
 * identiquement à un numéro inexistant (voir VitrineCommandeController côté
 * API) — jamais un indice qu'une commande existe sans le bon jeton.
 *
 * Étape 13 — commande à régler par Mobile Money : les instructions de
 * transfert passent AVANT tout le reste (montant exact en très grand,
 * numéro copiable, nom du titulaire à vérifier, référence à citer). La page
 * ne dit JAMAIS que le paiement est reçu tant que le commerçant ne l'a pas
 * confirmé (paiement_confirme, venu du serveur) — et le statut de la
 * commande n'en dit rien non plus : "Confirmée" n'y signifie pas "payée".
 *
 * Un paiement PARTIEL n'est jamais présenté comme réglé : la page donne le
 * montant reçu et le reste à envoyer, avec les instructions pour ce reste.
 * Une commande ANNULÉE ou EXPIRÉE ne se paie plus (aucune instruction de
 * transfert) et ne remercie jamais : si de l'argent a été reçu, la page dit
 * franchement qu'un remboursement est dû et comment le demander (WhatsApp,
 * numéro de commande dans le message).
 */
import { CheckCircle2, CircleDashed, MessageCircle, ShieldCheck, XCircle } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useParams, useSearchParams } from 'react-router-dom'
import type { CommandeVitrine, InstructionsMobileMoney } from '../api/vitrine'
import { BoutonCopier } from '../components/BoutonCopier'
import { EtatErreur } from '../components/EtatErreur'
import { useEtablissementVitrine } from '../hooks/useEtablissementVitrine'
import { recupererCommandeVitrine } from '../api/vitrine'
import { formaterMontant } from '../lib/formatage'
import { libelleLivraisonCommande } from '../lib/livraison'
import { IconeMobileMoney } from '../components/IconeMobileMoney'

const LIBELLES_STATUT: Record<string, string> = {
  attente_paiement: 'En attente de confirmation',
  payee: 'Confirmée',
  prete: 'Prête',
  livree: 'Livrée',
  expiree: 'Expirée',
  annulee: 'Annulée',
}

function estAbandonnee(commande: CommandeVitrine): boolean {
  return commande.statut === 'annulee' || commande.statut === 'expiree'
}

function participeAbandon(commande: CommandeVitrine): string {
  return commande.statut === 'expiree' ? 'expirée' : 'annulée'
}

function messageWhatsapp(commande: CommandeVitrine): string {
  const mm = commande.mobile_money

  if (estAbandonnee(commande)) {
    if (commande.a_rembourser != null) {
      return (
        `Bonjour, ma commande ${commande.numero} a été ${participeAbandon(commande)}. ` +
        `Je vous avais envoyé ${formaterMontant(commande.a_rembourser)} : je souhaite être remboursé(e).`
      )
    }

    return `Bonjour, à propos de ma commande ${commande.numero}, ${participeAbandon(commande)}.`
  }

  if (commande.reste_du != null && mm) {
    return (
      `Bonjour, pour ma commande ${commande.numero}, vous avez reçu ${formaterMontant(commande.montant_recu)} ; ` +
      `il me reste ${formaterMontant(commande.reste_du)} à envoyer par ${mm.operateur_libelle} au ${mm.numero_affiche}.`
    )
  }

  if (commande.mode_paiement === 'mobile_money' && mm && !commande.paiement_confirme) {
    return (
      `Bonjour, je voudrais suivre ma commande ${commande.numero} (${formaterMontant(commande.total)}). ` +
      `Je paie par ${mm.operateur_libelle} au ${mm.numero_affiche} (${mm.beneficiaire}), ` +
      `avec la référence ${commande.numero}.`
    )
  }

  return `Bonjour, je voudrais suivre ma commande ${commande.numero}.`
}

export function PageConfirmationCommande() {
  const { numero } = useParams()
  const [parametres] = useSearchParams()
  const jeton = parametres.get('jeton') ?? ''

  const { data: etablissement } = useEtablissementVitrine()
  const { data: commande, isPending, isError, error, refetch } = useQuery({
    queryKey: ['commande-vitrine', numero, jeton],
    queryFn: () => recupererCommandeVitrine(numero as string, jeton),
    enabled: Boolean(numero),
    retry: false,
  })

  if (isPending) {
    return (
      <div className="mx-auto max-w-xl space-y-3">
        <div className="h-7 w-2/3 animate-pulse rounded bg-surface-alt" />
        <div className="h-32 w-full animate-pulse rounded bg-surface-alt" />
      </div>
    )
  }

  if (isError || !commande) {
    return (
      <div className="mx-auto max-w-xl">
        <EtatErreur erreur={error} onReessayer={() => refetch()} />
      </div>
    )
  }

  const parMobileMoney = commande.mode_paiement === 'mobile_money'
  const abandonnee = estAbandonnee(commande)
  const lienWhatsapp = etablissement?.numero_whatsapp
    ? `https://wa.me/${etablissement.numero_whatsapp.replace(/\D/g, '')}?text=${encodeURIComponent(
        messageWhatsapp(commande),
      )}`
    : null

  return (
    <div className="mx-auto max-w-xl space-y-6 pb-4">
      {abandonnee ? (
        <BlocCommandeAbandonnee commande={commande} lienWhatsapp={lienWhatsapp} />
      ) : (
        parMobileMoney && <BlocPaiementMobileMoney commande={commande} />
      )}

      <div>
        <p className="text-petit text-texte-secondaire">Commande</p>
        <h1 className="tabular-nums text-titre-page font-semibold text-texte">{commande.numero}</h1>
        <p className="mt-1 text-corps text-texte-secondaire">
          Statut : <span className="font-medium text-texte">{LIBELLES_STATUT[commande.statut] ?? commande.statut}</span>
        </p>
      </div>

      {!parMobileMoney && !abandonnee && (
        <p className="border border-bordure bg-surface p-3 text-corps text-texte-secondaire">
          Le paiement en ligne n'est pas encore disponible. Le commerçant va vous recontacter pour organiser le
          règlement.
        </p>
      )}

      <div className="border border-bordure bg-surface p-4">
        <h2 className="mb-3 text-titre-section font-semibold text-texte">Articles</h2>
        <ul className="space-y-1.5 text-corps text-texte">
          {commande.lignes.map((ligne, index) => (
            <li key={index} className="flex justify-between gap-2">
              <span className="truncate text-texte-secondaire">
                {ligne.quantite} × {ligne.nom}
              </span>
              <span className="shrink-0 tabular-nums">{formaterMontant(ligne.total)}</span>
            </li>
          ))}
        </ul>
        <div className="mt-3 space-y-1 border-t border-bordure pt-3 text-corps">
          <div className="flex justify-between text-texte-secondaire">
            <span>Sous-total</span>
            <span className="tabular-nums">{formaterMontant(commande.sous_total)}</span>
          </div>
          <div className="flex justify-between text-texte-secondaire">
            <span>Livraison{commande.zone_livraison ? ` (${commande.zone_livraison.nom})` : ''}</span>
            <span className="tabular-nums">{libelleLivraisonCommande(commande.zone_livraison, commande.frais_livraison)}</span>
          </div>
          <div className="flex justify-between text-titre-section font-semibold text-texte">
            <span>Total</span>
            <span className="tabular-nums">{formaterMontant(commande.total)}</span>
          </div>
          {commande.zone_livraison === null && commande.frais_livraison === 0 && (
            <p className="text-petit text-texte-secondaire">Ce total n’inclut pas la livraison : la boutique vous contactera pour la convenir.</p>
          )}
        </div>
      </div>

      {lienWhatsapp && !abandonnee && (
        <a
          href={lienWhatsapp}
          target="_blank"
          rel="noopener noreferrer"
          className="block min-h-11 w-full cursor-pointer rounded border px-3 py-2.5 text-center leading-snug border-texte text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
        >
          {/* Icône DANS le texte, pas à côté : sur deux lignes (libellé long,
              écran de 320-390 px), elle reste collée au premier mot au lieu de
              partir au bord du bouton. */}
          <MessageCircle aria-hidden="true" size={20} strokeWidth={1.5} className="mr-1.5 inline-block align-[-5px]" />
          Suivre ma commande sur WhatsApp
        </a>
      )}
    </div>
  )
}

/**
 * Annulée ou expirée : jamais de remerciement ni de "vous serez contacté".
 * Trois cas, dits franchement : argent reçu et à rendre (avec le moyen de
 * le réclamer), argent déjà rendu, ou rien n'a été payé (et rien ne doit
 * plus l'être).
 */
function BlocCommandeAbandonnee({ commande, lienWhatsapp }: { commande: CommandeVitrine; lienWhatsapp: string | null }) {
  return (
    <section role="status" className="animate-entree-carte space-y-3 border-2 border-texte bg-surface p-4 text-corps text-texte">
      <p className="flex items-start gap-2 font-semibold">
        <XCircle aria-hidden="true" size={22} strokeWidth={1.75} className="mt-0.5 shrink-0" />
        Cette commande a été {participeAbandon(commande)}.
      </p>

      {commande.a_rembourser != null ? (
        <p>
          Le commerçant a reçu <span className="font-semibold tabular-nums">{formaterMontant(commande.a_rembourser)}</span>{' '}
          de votre part pour cette commande : <span className="font-semibold">un remboursement vous est dû.</span>{' '}
          Contactez-le pour l'obtenir, en rappelant le numéro {commande.numero}.
        </p>
      ) : commande.remboursement ? (
        <p>
          Le commerçant indique vous avoir remboursé{' '}
          <span className="font-semibold tabular-nums">{formaterMontant(commande.remboursement.montant)}</span> le{' '}
          {new Date(commande.remboursement.le).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}
          . Si vous ne l'avez pas reçu, contactez-le.
        </p>
      ) : (
        <p>N'envoyez pas d'argent pour cette commande. Pour toute question, contactez le commerçant.</p>
      )}

      {lienWhatsapp && (
        <a
          href={lienWhatsapp}
          target="_blank"
          rel="noopener noreferrer"
          className="block min-h-11 w-full cursor-pointer rounded border px-3 py-2.5 text-center leading-snug border-texte text-corps font-medium text-texte transition-[background-color,transform] hover:bg-surface-alt active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1"
        >
          {/* Icône DANS le texte, pas à côté : sur deux lignes (libellé long,
              écran de 320-390 px), elle reste collée au premier mot au lieu de
              partir au bord du bouton. */}
          <MessageCircle aria-hidden="true" size={20} strokeWidth={1.5} className="mr-1.5 inline-block align-[-5px]" />
          {commande.a_rembourser != null ? 'Demander mon remboursement sur WhatsApp' : 'Contacter le commerçant sur WhatsApp'}
        </a>
      )}
    </section>
  )
}

function BlocPaiementMobileMoney({ commande }: { commande: CommandeVitrine }) {
  if (commande.paiement_confirme && commande.reste_du != null) {
    return (
      <div className="space-y-4">
        <section className="animate-entree-carte flex gap-3 border-2 border-alerte bg-surface p-4 text-corps text-texte">
          <CircleDashed aria-hidden="true" size={24} strokeWidth={1.75} className="shrink-0 text-alerte" />
          <p>
            <span className="font-semibold">Paiement incomplet.</span> Le commerçant a reçu{' '}
            <span className="tabular-nums">{formaterMontant(commande.montant_recu)}</span> sur{' '}
            <span className="tabular-nums">{formaterMontant(commande.total)}</span>. Il reste{' '}
            <span className="font-semibold tabular-nums">{formaterMontant(commande.reste_du)}</span> à envoyer.
          </p>
        </section>
        {commande.mobile_money && (
          <InstructionsTransfert
            commande={commande}
            mm={commande.mobile_money}
            montant={commande.reste_du}
            libelleMontant="Montant restant à envoyer"
            phraseFin={`Votre commande sera confirmée dès que le commerçant aura reçu les ${formaterMontant(commande.reste_du)} restants.`}
          />
        )}
      </div>
    )
  }

  if (commande.paiement_confirme) {
    return (
      <section className="animate-entree-carte flex gap-3 border-2 border-succes bg-surface p-4 text-corps text-texte">
        <CheckCircle2 aria-hidden="true" size={24} strokeWidth={1.75} className="shrink-0 text-succes" />
        <p>
          <span className="font-semibold">Le commerçant a confirmé avoir reçu votre paiement.</span> Merci ! Vous
          serez contacté pour la suite de votre commande.
        </p>
      </section>
    )
  }

  if (!commande.mobile_money) {
    return (
      <section className="animate-entree-carte border-2 border-texte bg-surface p-4 text-corps text-texte">
        Le commerçant n'affiche plus de numéro Mobile Money. N'envoyez pas d'argent : contactez-le pour savoir
        comment régler votre commande.
      </section>
    )
  }

  return (
    <InstructionsTransfert
      commande={commande}
      mm={commande.mobile_money}
      montant={commande.total}
      libelleMontant="Montant exact à envoyer"
      phraseFin="Votre commande sera confirmée dès que le commerçant aura reçu votre paiement."
    />
  )
}

function InstructionsTransfert({
  commande,
  mm,
  montant,
  libelleMontant,
  phraseFin,
}: {
  commande: CommandeVitrine
  mm: InstructionsMobileMoney
  montant: number
  libelleMontant: string
  phraseFin: string
}) {
  return (
    <section
      aria-labelledby="titre-paiement-mm"
      className="animate-entree-carte divide-y divide-bordure border-2 border-texte bg-surface"
    >
      <div className="p-4 sm:p-5">
        <h2 id="titre-paiement-mm" className="flex items-center gap-2 text-corps font-semibold text-texte">
          <IconeMobileMoney size={20} strokeWidth={1.75} />
          Payez par {mm.operateur_libelle}
        </h2>
        <p className="mt-3 text-petit text-texte-secondaire">{libelleMontant}</p>
        <p className="text-[2.5rem] font-bold leading-tight tabular-nums text-texte">{formaterMontant(montant)}</p>
      </div>

      <div className="p-4 sm:p-5">
        <p className="text-petit text-texte-secondaire">Au numéro {mm.operateur_libelle}</p>
        <div className="mt-1 flex flex-wrap items-center justify-between gap-3">
          <p className="select-all text-[1.75rem] font-bold tracking-wide tabular-nums text-texte">
            {mm.numero_affiche}
          </p>
          <BoutonCopier valeur={mm.numero_affiche.replace(/\s/g, '')} libelle="le numéro" />
        </div>
      </div>

      <div className="p-4 sm:p-5">
        <p className="text-petit text-texte-secondaire">Au nom de</p>
        <p className="text-titre-section font-semibold text-texte">{mm.beneficiaire}</p>
        <p className="mt-2 flex gap-2 text-corps text-texte">
          <ShieldCheck aria-hidden="true" size={20} strokeWidth={1.75} className="mt-0.5 shrink-0" />
          Vérifiez que ce nom s'affiche dans votre application avant de valider.
        </p>
      </div>

      <div className="p-4 sm:p-5">
        <p className="text-petit text-texte-secondaire">Référence à indiquer dans le transfert</p>
        <div className="mt-1 flex flex-wrap items-center justify-between gap-3">
          <p className="select-all text-titre-section font-semibold tabular-nums text-texte">{commande.numero}</p>
          <BoutonCopier valeur={commande.numero} libelle="la référence" />
        </div>
      </div>

      <p className="bg-surface-alt p-4 text-corps text-texte sm:p-5">{phraseFin}</p>
    </section>
  )
}
