import { Check, CreditCard, LockKeyhole, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { startIyzicoCheckout } from '../api/paymentsApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import { PAYMENT_UNAVAILABLE_MESSAGE, PREMIUM_PRICE_LABEL } from '../config/premium'

function PremiumCheckoutPage() {
  const [message, setMessage] = useState('')

  async function startCheckout() {
    setMessage('')
    try {
      await startIyzicoCheckout()
    } catch (error) {
      setMessage(error.message || PAYMENT_UNAVAILABLE_MESSAGE)
    }
  }

  return <>
    <PageHeader eyebrow="Premium satın alma" title="Premium’a Geç" description="Planını gözden geçir; ödeme altyapısı aktif olduğunda güvenli ödeme adımına geçebilirsin." />
    <section className="section premium-checkout-page"><Container>
      <div className="premium-checkout-grid">
        <article className="premium-checkout-card">
          <p className="eyebrow">Seçili plan</p>
          <h2>DersRotası Premium</h2>
          <ul><li><Check aria-hidden="true" /> Günde 100 AI mesajı</li><li><Check aria-hidden="true" /> Gelişmiş tercih araçları</li><li><Check aria-hidden="true" /> Gelecekteki Premium özelliklere erişim</li></ul>
          <div className="premium-checkout-total"><span>Toplam</span><strong>{PREMIUM_PRICE_LABEL}</strong></div>
        </article>
        <aside className="premium-checkout-card premium-checkout-card--secure">
          <span className="premium-card__icon"><LockKeyhole aria-hidden="true" /></span>
          <h2>Güvenli ödeme</h2>
          <p>Ödeme, Iyzico Checkout Form ile güvenli olarak tamamlanacak. DersRotası kart bilgilerini toplamaz veya saklamaz.</p>
          {message ? <div className="form-alert" role="alert"><p>{message}</p></div> : null}
          <Button icon={CreditCard} onClick={startCheckout}>Güvenli Ödemeye Geç</Button>
          <small>Devam ederek <Link to="/">Kullanım Koşulları</Link> ve <Link to="/">Gizlilik Politikası</Link> taslaklarını kabul etmiş olursun.</small>
          <p className="premium-checkout-card__provider"><ShieldCheck aria-hidden="true" /> Iyzico entegrasyonu hazırlık aşamasında.</p>
        </aside>
      </div>
    </Container></section>
  </>
}

export default PremiumCheckoutPage
