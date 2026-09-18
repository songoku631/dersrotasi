import { Check, Crown, Minus, ShieldCheck, Sparkles } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import { PREMIUM_PRICE_LABEL } from '../config/premium'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'

const rows = [
  { label: 'Günlük AI mesajı', free: '15 / gün', premium: '100 / gün' },
  { label: 'Daha yüksek token bütçesi', free: false, premium: true },
  { label: 'Daha uzun AI mesajları', free: false, premium: true },
  { label: 'Üniversite arama', free: true, premium: true },
  { label: 'Favoriler', free: true, premium: true },
  { label: 'Tercih listesi', free: true, premium: true },
  { label: 'Sohbet geçmişi', free: true, premium: true },
  { label: 'Tercih listesi AI analizi', free: false, premium: true },
  { label: 'Risk / Hedef / Güvenli analizi', free: false, premium: true },
  { label: 'İki programı AI ile karşılaştırma', free: false, premium: true },
  { label: '2023–2026 trend analizi', free: false, premium: true },
  { label: 'AI destekli tercih kontrolü', free: false, premium: true },
]

function Availability({ value }) {
  if (value === true) return <span className="premium-check"><Check aria-hidden="true" /> Var</span>
  if (value === false) return <span className="premium-none"><Minus aria-hidden="true" /> Yok</span>
  return <strong>{value}</strong>
}

function PremiumPage() {
  const { user } = useAuth()
  const { error, loading, plan, refresh } = useUserPlan(user)
  const isPremium = Boolean(plan?.has_premium_access)
  const checkoutTarget = user ? '/premium/checkout' : '/login'
  const checkoutState = user ? undefined : { from: '/premium/checkout' }

  return (
    <>
      <PageHeader eyebrow="DersRotası Premium" title="DersRotası Premium" description="Daha geniş AI kullanımı ve tercih sürecinde sana eşlik eden gelişmiş özellikler." />
      <section className="section premium-page"><Container>
        {error ? <div className="form-alert" role="alert"><p>{error}</p><Button onClick={refresh} variant="secondary">Tekrar Dene</Button></div> : null}
        <div className="premium-hero-card">
          <span className="premium-card__icon"><Crown aria-hidden="true" /></span>
          <div><p className="eyebrow">Premium ayrıcalıkları</p><h2>Tercih listen yalnızca sıralanmasın, analiz edilsin.</h2><p>100 günlük AI mesajı, gelişmiş analiz araçları ve gelecekte eklenecek Premium özellikler tek planda.</p></div>
          {isPremium ? <strong className="premium-card__current">{plan?.is_admin ? 'Admin erişimi aktif' : 'Premium aktif'}</strong> : <Button state={checkoutState} to={checkoutTarget}>Premium’a Geç</Button>}
        </div>
        <div className="premium-plan-summary">
          <div><span className="premium-card__icon"><Sparkles aria-hidden="true" /></span><h2>Premium Plan</h2><p>Her gün 100 AI mesajı ve gelişmiş tercih araçları.</p></div>
          <div className="premium-plan-summary__price"><strong>{PREMIUM_PRICE_LABEL}</strong><small>Ödeme altyapısı yakında burada olacak.</small></div>
        </div>
        <div className="premium-comparison-table" aria-busy={loading}>
          <div className="premium-comparison-table__head"><span>Özellik</span><strong><Sparkles aria-hidden="true" /> Free</strong><strong><Crown aria-hidden="true" /> Premium</strong></div>
          {rows.map((row) => <div className="premium-comparison-table__row" key={row.label}><span>{row.label}</span><Availability value={row.free} /><Availability value={row.premium} /></div>)}
        </div>
        <div className="premium-feature-grid">
          <article><h3>Tercih Listemi Analiz Et</h3><p>Listenin risk dağılımını, sıralama hatalarını, yıllık başarı sırası oynaklığını ve kontenjan değişimlerini gör.</p></article>
          <article><h3>Program Karşılaştırma</h3><p>İki programın gerçek 2023–2026 verilerini yan yana incele ve kısa AI yorumunu oku.</p></article>
          <article><h3>Daha geniş AI kullanımı</h3><p>Günde 100 mesaj, daha yüksek token bütçesi ve daha uzun sorularla tercih araştırmanı kesintisiz sürdür.</p></article>
        </div>
        <div className="premium-page__trust"><ShieldCheck aria-hidden="true" /><p>Ödeme bilgileri DersRotası’nda tutulmaz. Ödeme sistemi aktif olduğunda güvenli sağlayıcı üzerinden ilerler.</p></div>
      </Container></section>
    </>
  )
}

export default PremiumPage
