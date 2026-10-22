/**
 * "Consultations par l'équipe de la plateforme" — sur la page Identité du
 * commerçant (chemin commerçant seulement, jamais sur la fiche super-admin).
 * L'état vide le dit en clair : l'absence de ligne est elle-même une
 * information, pas un chargement raté.
 */
import { useQuery } from '@tanstack/react-query'
import { Eye } from 'lucide-react'
import { recupererConsultationsPlateforme } from '../api/consultationsPlateforme'
import { EtatChargement } from './EtatChargement'
import { EtatErreur } from './EtatErreur'

function formaterDateHeure(date: string): string {
  return new Date(date).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function SectionConsultationsPlateforme() {
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: ['consultations-plateforme'],
    queryFn: recupererConsultationsPlateforme,
  })

  return (
    <section aria-labelledby="titre-consultations" className="rounded-lg border border-bordure bg-surface">
      <div className="border-b border-bordure px-4 py-3">
        <h2 id="titre-consultations" className="text-titre-section font-semibold text-texte">
          Consultations par l'équipe de la plateforme
        </h2>
        <p className="mt-1 text-petit text-texte-secondaire">
          Chaque fois qu'un membre de l'équipe de la plateforme ouvre vos commandes, vos clients ou votre chiffre
          d'affaires, c'est noté ici : qui, quand, et quelle page. Jamais ce qu'il y a vu.
        </p>
      </div>

      {isPending && <EtatChargement />}
      {isError && <EtatErreur erreur={error} onReessayer={() => refetch()} />}

      {data && data.data.length === 0 && (
        <p className="p-4 text-corps text-texte-secondaire">
          Personne de l'équipe de la plateforme n'a consulté vos données commerciales.
        </p>
      )}

      {data && data.data.length > 0 && (
        <>
          <ul className="divide-y divide-bordure">
            {data.data.map((consultation) => (
              <li key={consultation.id} className="flex items-start gap-3 px-4 py-3">
                <Eye aria-hidden="true" size={18} strokeWidth={1.75} className="mt-0.5 text-texte-secondaire" />
                <div className="min-w-0 text-corps text-texte">
                  <p>
                    <span className="font-medium">{consultation.qui}</span> a consulté{' '}
                    <span className="font-medium">{consultation.page_libelle}</span>
                  </p>
                  <p className="text-petit text-texte-secondaire">
                    <time dateTime={consultation.consulte_le}>{formaterDateHeure(consultation.consulte_le)}</time>
                  </p>
                </div>
              </li>
            ))}
          </ul>
          {data.total > data.data.length && (
            <p className="border-t border-bordure px-4 py-2 text-petit text-texte-secondaire">
              {data.data.length} consultations les plus récentes affichées sur {data.total}.
            </p>
          )}
        </>
      )}
    </section>
  )
}
