import { Crown, X } from 'lucide-react'
import Button from '../Button'

function PremiumGateModal({ open, onClose, title = 'Bu özellik Dersrotası Premium’a özel.', description = 'Tercih listesi analizi ve program karşılaştırmasına Premium ile erişebilirsin.' }) {
  if (!open) return null
  return (
    <div className="premium-modal-backdrop" role="presentation" onMouseDown={onClose}>
      <section className="premium-modal premium-gate" role="dialog" aria-modal="true" aria-labelledby="premium-gate-title" onMouseDown={(event) => event.stopPropagation()}>
        <button className="premium-modal__close" type="button" aria-label="Kapat" onClick={onClose}><X /></button>
        <Crown className="premium-gate__icon" aria-hidden="true" />
        <p className="eyebrow">Dersrotası Premium</p>
        <h2 id="premium-gate-title">{title}</h2>
        <p>{description}</p>
        <div className="premium-modal__actions"><Button to="/premium">Premium’u İncele</Button><Button variant="secondary" onClick={onClose}>Şimdi Değil</Button></div>
      </section>
    </div>
  )
}

export default PremiumGateModal
