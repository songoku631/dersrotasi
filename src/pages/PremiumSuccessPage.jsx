import { CheckCircle2 } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'

function PremiumSuccessPage() {
  return <section className="section"><Container><div className="premium-result-card">
    <CheckCircle2 aria-hidden="true" /><h1>Ödeme tamamlandı</h1>
    <p>Premium durumun ödeme sağlayıcısının ardından backend tarafından doğrulanır ve hesabında güvenle etkinleştirilir.</p>
    <Button to="/profil">Profilime Git</Button>
  </div></Container></section>
}

export default PremiumSuccessPage
