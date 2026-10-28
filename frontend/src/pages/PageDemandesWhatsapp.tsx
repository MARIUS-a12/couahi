/**
 * Lot D — « Demandes WhatsApp » : chaque clic sur « Commander sur WhatsApp »
 * de la vitrine. Une demande n'est pas une commande : elle ne réserve rien
 * et ne compte dans aucun chiffre. Le commerçant la transforme en commande
 * (saisie manuelle du lot C, déjà remplie), la marque traitée, ou l'ignore.
 *
 * Au clic, wa.me ne dit RIEN du client : la ligne ne montre que ce qu'on
 * sait (produit, variante, heure, référence, statut). Pas de colonne nom ou
 * téléphone, qui resterait vide pour toujours et passerait pour un bug.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MessageCircle } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import type { DemandeWhatsapp, StatutDemande } from '../api/demandesWhatsapp'
import { changerStatutDemande, listerDemandes } from '../api/demandesWhatsapp'
import { Bouton } from '../components/Bouton'
import { BoutonCopier } from '../components/BoutonCopier'
import { CarreInitiale } from '../components/CarreInitiale'
import { EnteteDePage } from '../components/EnteteDePage'
import { EtatChargement } from '../components/EtatChargement'
import { EtatErreur } from '../components/EtatErreur'
import { useMoi } from '../hooks/useMoi'
import { dateExacte, tempsRelatif } from '../lib/tempsRelatif'

const FILTRES: { valeur: StatutDemande; libelle: string }[] = [
  { valeur: 'nouvelle', libelle: 'Nouvelles' },
  { valeur: 'traitee', libelle: 'Traitées' },
  { valeur: 'ignoree', libelle: 'Ignorées' },
]

const VIDE: Record<StatutDemande, string> = {
  nouvelle: 'Quand un client touchera « Commander sur WhatsApp » sur votre boutique, sa demande apparaîtra ici, avec le produit et la référence de son message.',
  traitee: 'Les demandes que vous aurez transformées en commande ou marquées traitées apparaîtront ici.',
  ignoree: 'Les demandes que vous aurez ignorées apparaîtront ici.',
}

export function PageDemandesWhatsapp() {
  const [statut, setStatut] = useState<StatutDemande>('nouvelle')
  const [page, setPage] = useState(1)
  const demandes = useQuery({ queryKey: ['demandes-whatsapp', statut, page], queryFn: () => listerDemandes(statut, page) })

  function filtrer(valeur: StatutDemande) {
    setStatut(valeur)
    setPage(1)
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <EnteteDePage
        icone={<MessageCircle aria-hidden="true" size={22} strokeWidth={1.75} />}
        couleur="vert"
        titre="Demandes WhatsApp"
        sousTitre="Les clics sur « Commander sur WhatsApp ». Ce ne sont pas des commandes : rien n'est réservé."
      />

      <div className="flex gap-2 overflow-x-auto pb-1">
        {FILTRES.map((filtre) => (
          <button
            key={filtre.valeur}
            type="button"
            onClick={() => filtrer(filtre.valeur)}
            aria-pressed={statut === filtre.valeur}
            className={`h-11 shrink-0 cursor-pointer rounded-md px-3 text-corps font-medium transition-[background-color,transform] active:scale-[0.97] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primaire focus-visible:outline-offset-1 ${
              statut === filtre.valeur ? 'bg-marine text-white' : 'border border-bordure bg-surface text-texte hover:bg-surface-alt'
            }`}
          >
            {filtre.libelle}
          </button>
        ))}
      </div>

      {demandes.isPending && <EtatChargement />}
      {demandes.isError && <EtatErreur erreur={demandes.error} onReessayer={() => demandes.refetch()} />}

      {demandes.data && demandes.data.data.length === 0 && (
        <p className="rounded-lg border border-bordure bg-surface px-4 py-10 text-center text-corps text-texte-secondaire">{VIDE[statut]}</p>
      )}

      {demandes.data && demandes.data.data.length > 0 && (
        <>
          <ul aria-label="Demandes" className="space-y-3">
            {demandes.data.data.map((demande) => (
              <LigneDemande key={demande.id} demande={demande} />
            ))}
          </ul>

          {demandes.data.meta.last_page > 1 && (
            <div className="flex items-center justify-between gap-2">
              <Bouton disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Précédent
              </Bouton>
              <span className="text-petit text-texte-secondaire">
                Page {demandes.data.meta.current_page} sur {demandes.data.meta.last_page}
              </span>
              <Bouton disabled={page >= demandes.data.meta.last_page} onClick={() => setPage(page + 1)}>
                Suivant
              </Bouton>
            </div>
          )}
        </>
      )}
    </div>
  )
}

function LigneDemande({ demande }: { demande: DemandeWhatsapp }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { data: moi } = useMoi()
  const peutTransformer = moi?.permissions.includes('creer_commandes') ?? false
  const [dateVisible, setDateVisible] = useState(false)

  const changement = useMutation({
    mutationFn: (statut: 'traitee' | 'ignoree') => changerStatutDemande(demande.id, statut),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['demandes-whatsapp'] })
      queryClient.invalidateQueries({ queryKey: ['demandes-whatsapp-compte'] })
    },
  })

  function transformer() {
    const parametres = new URLSearchParams({ canal: 'whatsapp', produit: String(demande.produit.id), reference: demande.code })
    if (demande.variante) {
      parametres.set('variante', String(demande.variante.id))
    }
    navigate(`/admin/commandes/nouvelle?${parametres.toString()}`)
  }

  const nom = demande.produit.nom ?? 'Produit retiré'

  return (
    <li className="space-y-3 rounded-lg border border-bordure bg-surface p-4">
      <div className="flex items-start gap-3">
        <CarreInitiale nom={nom} taille={48} photo={demande.photo ? { jpg: demande.photo, webp: null } : null} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-corps font-semibold text-texte">{nom}</p>
          {demande.variante && <p className="text-petit text-texte-secondaire">{demande.variante.nom}</p>}
          <button
            type="button"
            onClick={() => setDateVisible(!dateVisible)}
            aria-expanded={dateVisible}
            className="min-h-11 cursor-pointer text-left text-petit text-texte-secondaire underline decoration-dotted"
          >
            {dateVisible ? dateExacte(demande.cree_le) : tempsRelatif(demande.cree_le)}
          </button>
        </div>
        <span className="shrink-0 rounded-md bg-surface-alt px-2 py-1 text-petit font-medium text-texte">{demande.statut_libelle}</span>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <code className="rounded bg-surface-alt px-2 py-1 text-petit text-texte">{demande.reference}</code>
        <BoutonCopier valeur={demande.reference} libelle={`la référence ${demande.reference}`} />
        {demande.expiree && (
          <span className="rounded-md border border-alerte bg-alerte/10 px-2 py-1 text-petit font-medium text-texte">Référence expirée</span>
        )}
      </div>
      {demande.expiree && demande.commande === null && (
        <p className="text-petit text-texte-secondaire">Le client ne peut plus utiliser ce lien, mais vous pouvez toujours transformer la demande.</p>
      )}

      {demande.commande ? (
        <p className="text-petit text-texte">
          Devenue la commande{' '}
          <Link to={`/admin/commandes/${demande.commande.id}`} className="font-medium text-primaire underline">
            {demande.commande.numero}
          </Link>
          .
        </p>
      ) : (
        <div className="flex flex-wrap gap-2">
          {peutTransformer && (
            <Bouton variante="principal" onClick={transformer}>
              Transformer en commande
            </Bouton>
          )}
          {demande.statut !== 'traitee' && (
            <Bouton onClick={() => changement.mutate('traitee')} chargement={changement.isPending && changement.variables === 'traitee'}>
              Marquer traitée
            </Bouton>
          )}
          {demande.statut !== 'ignoree' && (
            <Bouton variante="tertiaire" onClick={() => changement.mutate('ignoree')} chargement={changement.isPending && changement.variables === 'ignoree'}>
              Ignorer
            </Bouton>
          )}
        </div>
      )}
      {changement.isError && <p role="alert" className="text-petit text-danger">Le changement n'a pas pu être enregistré. Réessayez.</p>}
    </li>
  )
}
