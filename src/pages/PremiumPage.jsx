import { Check, Crown, Sparkles } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'

const planCards = [
  {
    code: 'free',
    title: 'Ücretsiz',
    description: 'Dersrotası AI’ı günlük temel kullanım için dene.',
  },
  {
    code: 'premium',
    title: 'Premium',
    description: 'Daha yoğun tercih araştırmaları ve uzun sorular için.',
  },
]

function PremiumPage() {
  const { user } = useAuth()
  const { error, loading, plan, refresh } = useUserPlan(user)

  return (
    <>
      <PageHeader
        eyebrow="Dersrotası planları"
        title="İhtiyacına uygun kullanım planı"
        description="Her iki plan da günlük adil kullanım sınırlarıyla çalışır. Ödeme sistemi henüz aktif değildir."
      />
      <section className="section premium-page">
        <Container>
          {error ? (
            <div className="form-alert" role="alert">
              <p>{error}</p>
              <Button onClick={refresh} variant="secondary">Tekrar Dene</Button>
            </div>
          ) : null}
          <div className="premium-grid" aria-busy={loading}>
            {planCards.map((card) => {
              const current = plan?.plan === card.code
              const limits = plan?.available_plans?.[card.code]
              const features = limits ? [
                `Günde ${limits.daily_requests.toLocaleString('tr-TR')} AI mesajı`,
                `Mesaj başına ${limits.max_message_chars.toLocaleString('tr-TR')} karakter`,
                `Günlük ${limits.daily_token_budget.toLocaleString('tr-TR')} token bütçesi`,
              ] : []
              return (
                <article className={`premium-card premium-card--${card.code}`} key={card.code}>
                  <span className="premium-card__icon">
                    {card.code === 'premium' ? <Crown aria-hidden="true" /> : <Sparkles aria-hidden="true" />}
                  </span>
                  <p className="eyebrow">{current ? 'Mevcut planın' : 'Plan'}</p>
                  <h2>{card.title}</h2>
                  <p>{card.description}</p>
                  <ul>
                    {features.map((feature) => (
                      <li key={feature}><Check aria-hidden="true" /> {feature}</li>
                    ))}
                  </ul>
                  {current ? <strong className="premium-card__current">Aktif plan</strong> : null}
                </article>
              )
            })}
          </div>
          <p className="premium-page__note">
            Premium satın alma ve ödeme akışı henüz sunulmuyor. Plan değişiklikleri yalnızca yetkili yerel yönetim aracıyla yapılabilir.
          </p>
        </Container>
      </section>
    </>
  )
}

export default PremiumPage
