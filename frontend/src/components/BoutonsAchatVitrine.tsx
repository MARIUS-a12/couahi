/**
 * Les actions d'achat de la fiche produit, dans l'ordre imposé : UN bouton
 * de paiement selon le mode proposé par l'établissement (Étape 13, voir
 * "modePaiement"), commander sur WhatsApp, ajouter au panier.
 * - "cinetpay" : "Payer maintenant". Le serveur ne l'envoie que si CinetPay
 *   est DISPONIBLE (configuré ET intégration active, CINETPAY_ACTIF) —
 *   jamais un bouton qui ne mène nulle part. Même chemin que le Mobile
 *   Money : le paiement s'engage à la validation de commande.
 * - "mobile_money" : "Payer par Mobile Money" — met le produit au panier
 *   (s'il n'y est pas déjà) et mène à la validation de commande, d'où le
 *   client reçoit le numéro à payer.
 * - aucun : pas de bouton de paiement, seulement WhatsApp et le panier.
 * Jamais deux boutons de paiement ensemble : un seul chemin, pour ne pas
 * perdre le client dans un choix. WhatsApp et le panier,
 * eux, fonctionnent réellement : le lien WhatsApp (avec sa référence
 * opaque) est composé côté serveur (voir api/vitrine.ts), le panier vit
 * dans localStorage (voir lib/panier.ts) et confirme l'ajout sans quitter
 * la page — jamais de navigation, jamais de rechargement.
 *
 * Si le produit a des variantes et qu'aucune n'est choisie, les boutons
 * restent ACTIFS mais gardés : un clic ne commande rien, il appelle
 * onVarianteManquante (la fiche fait défiler jusqu'au sélecteur et
 * l'entoure). Un bouton désactivé ne répondait à rien, ne recevait pas le
 * focus et n'était pas annoncé : le client ne pouvait pas comprendre
 * pourquoi. Le message d'explication est AU-DESSUS des boutons (sous eux,
 * il tombait sous la ligne de flottaison à 390 px) et leur est lié par
 * aria-describedby. Un produit ou une variante épuisés, eux, gardent des
 * boutons désactivés : aucun choix du client ne peut les débloquer.
 */
import { useEffect, useState } from 'react'
import { Check, CreditCard, MessageCircle, ShoppingCart } from 'lucide-react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import type { ModePaiementVitrine, VarianteVitrine } from '../api/vitrine'
import { genererLienWhatsapp } from '../api/vitrine'
import { usePanier } from '../hooks/usePanier'
import { EtatBouton } from './EtatBouton'
import { IconeMobileMoney } from './IconeMobileMoney'

export function BoutonsAchatVitrine({
  produitId,
  prix,
  disponible,
  variantes,
  varianteChoisie,
  optionsManquantes = [],
  modePaiement,
  onVarianteManquante,
}: {
  produitId: number
  prix: number
  disponible: boolean
  variantes: VarianteVitrine[]
  varianteChoisie: VarianteVitrine | null
  /** Les lignes du sélecteur encore vides (« Pointure »…), pour dire quoi choisir. */
  optionsManquantes?: string[]
  modePaiement: ModePaiementVitrine | null
  onVarianteManquante: () => void
}) {
  const { ajouter, lignes } = usePanier()
  const navigate = useNavigate()
  const [ajoute, setAjoute] = useState(false)

  const aBesoinDuneVariante = variantes.length > 0
  const varianteIndisponible = varianteChoisie !== null && !varianteChoisie.disponible

  const varianteManquante = aBesoinDuneVariante && varianteChoisie === null
  // Désactivé seulement quand rien ne peut débloquer l'achat (épuisé) ;
  // une variante manquante, elle, se règle d'un geste : bouton gardé.
  const bloque = varianteManquante ? false : aBesoinDuneVariante ? !varianteChoisie?.disponible : !disponible
  const idMessage = `message-achat-${produitId}`

  /** Garde commune aux trois boutons : sans variante, on guide au lieu d'agir. */
  function garde(action: () => void) {
    return () => {
      if (varianteManquante) {
        onVarianteManquante()
        return
      }
      action()
    }
  }

  const messageBlocage = aBesoinDuneVariante && varianteChoisie === null
    ? optionsManquantes.length > 0
      ? `Choisissez d’abord ci-dessus : ${listerEnFrancais(optionsManquantes.map((nom) => nom.toLowerCase()))}.`
      : 'Choisissez une variante ci-dessus pour continuer.'
    : varianteIndisponible
      ? 'Cette variante est épuisée.'
      : !aBesoinDuneVariante && !disponible
        ? 'Ce produit est épuisé.'
        : null

  const lienWhatsapp = useMutation({
    mutationFn: () => genererLienWhatsapp(produitId, varianteChoisie?.id ?? null),
    onSuccess: (url) => {
      window.open(url, '_blank', 'noopener,noreferrer')
    },
  })

  // Confirmation visuelle temporaire sur le bouton lui-même — jamais une
  // navigation, jamais un rechargement (voir docblock). 2s : le temps de la
  // remarquer sans ralentir qui enchaîne plusieurs ajouts.
  useEffect(() => {
    if (!ajoute) {
      return
    }

    const minuteur = setTimeout(() => setAjoute(false), 2000)
    return () => clearTimeout(minuteur)
  }, [ajoute])

  function allerPayer() {
    const dejaAuPanier = lignes.some(
      (ligne) => ligne.produitId === produitId && ligne.varianteId === (varianteChoisie?.id ?? null),
    )

    if (!dejaAuPanier) {
      ajouter(produitId, varianteChoisie?.id ?? null, 1, varianteChoisie?.prix ?? prix)
    }

    navigate('/commander')
  }

  function ajouterAuPanier() {
    ajouter(produitId, varianteChoisie?.id ?? null, 1, varianteChoisie?.prix ?? prix)
    setAjoute(true)
  }

  return (
    <div>
      {/* Au-dessus des boutons, jamais en dessous ; aria-live : le message
          est annoncé quand il change (variante choisie, épuisée...). */}
      {messageBlocage && (
        <p id={idMessage} aria-live="polite" className="mb-2 text-petit font-medium text-texte">
          {messageBlocage}
        </p>
      )}

      <div className="flex flex-col gap-3 md:flex-row">
        {/* Principal : l'action la plus engageante, la plus visible des
            trois — pleine, dense, une vraie ombre qui s'aplatit à la
            pression pour suggérer la matière. Absent tant qu'aucun moyen
            de paiement n'est réellement disponible. */}
        {modePaiement === 'cinetpay' && (
          <button
            type="button"
            disabled={bloque}
            aria-describedby={messageBlocage ? idMessage : undefined}
            onClick={garde(allerPayer)}
            className="flex min-h-12 py-2 text-center leading-snug flex-1 cursor-pointer items-center justify-center gap-2 rounded-md bg-accent text-corps font-semibold text-accent-texte shadow-bouton transition-[opacity,transform,box-shadow] duration-rapide ease-apparition hover:opacity-90 active:translate-y-px active:scale-[0.97] active:opacity-90 active:shadow-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100 disabled:active:shadow-bouton disabled:active:translate-y-0"
          >
            <CreditCard aria-hidden="true" size={20} strokeWidth={1.5} />
            Payer maintenant
          </button>
        )}

        {modePaiement === 'mobile_money' && (
          <button
            type="button"
            disabled={bloque}
            aria-describedby={messageBlocage ? idMessage : undefined}
            onClick={garde(allerPayer)}
            className="flex min-h-12 py-2 text-center leading-snug flex-1 cursor-pointer items-center justify-center gap-2 rounded-md bg-accent text-corps font-semibold text-accent-texte shadow-bouton transition-[opacity,transform,box-shadow] duration-rapide ease-apparition hover:opacity-90 active:translate-y-px active:scale-[0.97] active:opacity-90 active:shadow-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100 disabled:active:shadow-bouton disabled:active:translate-y-0"
          >
            <IconeMobileMoney size={20} strokeWidth={1.5} />
            Payer par Mobile Money
          </button>
        )}

        {/* Secondaire : contour marqué, moins engageant que le paiement
            direct mais toujours une vraie action d'achat. */}
        <button
          type="button"
          disabled={bloque || lienWhatsapp.isPending}
          aria-describedby={messageBlocage ? idMessage : undefined}
          onClick={garde(() => lienWhatsapp.mutate())}
          className="flex min-h-12 py-2 text-center leading-snug flex-1 cursor-pointer items-center justify-center gap-2 rounded-md border-2 border-texte text-corps font-medium text-texte transition-[background-color,transform] duration-rapide ease-apparition hover:bg-surface-alt active:scale-[0.97] active:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100"
        >
          <EtatBouton chargement={lienWhatsapp.isPending}>
            <MessageCircle aria-hidden="true" size={20} strokeWidth={1.5} />
            Commander sur WhatsApp
          </EtatBouton>
        </button>

        {/* Tertiaire : la moins engageante, un simple ajout en attente. */}
        <button
          type="button"
          disabled={bloque}
          aria-describedby={messageBlocage ? idMessage : undefined}
          onClick={garde(ajouterAuPanier)}
          className="flex min-h-12 py-2 text-center leading-snug flex-1 cursor-pointer items-center justify-center gap-2 rounded-md text-corps font-medium text-texte-secondaire transition-[background-color,transform] duration-rapide ease-apparition hover:bg-surface-alt active:scale-[0.97] active:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-texte focus-visible:outline-offset-1 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100"
        >
          {ajoute ? (
            <>
              <Check aria-hidden="true" size={20} strokeWidth={1.5} />
              Ajouté au panier
            </>
          ) : (
            <>
              <ShoppingCart aria-hidden="true" size={20} strokeWidth={1.5} />
              Ajouter au panier
            </>
          )}
        </button>
      </div>

      {lienWhatsapp.isError && (
        <p className="animate-entree-carte mt-2 text-petit text-danger">
          Impossible de préparer le message WhatsApp. Réessayez.
        </p>
      )}
    </div>
  )
}

/** « pointure », « pointure et coloris », « taille, coloris et longueur ». */
function listerEnFrancais(mots: string[]): string {
  return mots.length <= 1 ? mots.join('') : `${mots.slice(0, -1).join(', ')} et ${mots[mots.length - 1]}`
}
