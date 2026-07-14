import { Construction, TimerReset } from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'

function PlaceholderPage({ title, description }) {
  return (
    <>
      <PageHeader title={title} description={description} />
      <section className="section">
        <Container>
          <div className="placeholder-card">
            <span className="placeholder-card__icon" aria-hidden="true">
              <Construction size={28} />
            </span>
            <div>
              <h2>Bu araç bir sonraki aşamada eklenecek.</h2>
              <p>
                Şimdilik sayfa yapısı, yönlendirme ve responsive arayüz hazır.
                Form alanları, hesaplama mantığı ve gerçek sonuç ekranları
                sonraki geliştirme adımında bağlanacak.
              </p>
              <Button to="/" icon={TimerReset} variant="secondary">
                Ana sayfaya dön
              </Button>
            </div>
          </div>
        </Container>
      </section>
    </>
  )
}

export default PlaceholderPage
