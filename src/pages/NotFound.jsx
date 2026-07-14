import { Home } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'

function NotFound() {
  return (
    <section className="not-found">
      <Container>
        <p className="eyebrow">404</p>
        <h1>Aradığın sayfa bulunamadı.</h1>
        <p>
          Bu adres Ders Rotası içinde tanımlı değil. Ana sayfadan araçlara
          yeniden ulaşabilirsin.
        </p>
        <Button to="/" icon={Home}>
          Ana sayfaya dön
        </Button>
      </Container>
    </section>
  )
}

export default NotFound
