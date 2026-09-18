import { CircleX } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'

function PremiumCancelPage() {
  return <section className="section"><Container><div className="premium-result-card">
    <CircleX aria-hidden="true" /><h1>Ödeme tamamlanmadı</h1>
    <p>Herhangi bir ücretlendirme veya plan değişikliği yapılmadı. İstediğinde Premium planını yeniden inceleyebilirsin.</p>
    <Button to="/premium">Premium’u İncele</Button>
  </div></Container></section>
}

export default PremiumCancelPage
