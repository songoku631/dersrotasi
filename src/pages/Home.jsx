import {
  ArrowRight,
  CheckCircle2,
  ClipboardList,
  Compass,
  ShieldCheck,
  Sparkles,
  TrendingUp,
} from 'lucide-react'
import Button from '../components/Button'
import Container from '../components/Container'
import ToolCard from '../components/ToolCard'
import { tools } from '../data/tools'

const steps = [
  {
    title: 'Aracını seç',
    text: 'Net hesaplama, puan tahmini, pomodoro veya çalışma planı ekranına hızlıca geç.',
  },
  {
    title: 'Bilgilerini gir',
    text: 'Ders ve hedef bilgilerini sade formlarla ekleyerek sınav yolculuğunu görünür hale getir.',
  },
  {
    title: 'Rotanı güncelle',
    text: 'Sonuçlarını takip et, eksiklerini fark et ve çalışma düzenini daha bilinçli kur.',
  },
]

const reasons = [
  {
    title: 'Öğrenci odaklı',
    text: 'Ekranlar hızlı anlaşılır, mobilde rahat kullanılır ve dikkat dağıtmadan çalışır.',
    icon: Compass,
  },
  {
    title: 'Güven veren yapı',
    text: 'Net, plan ve hedef araçları tek bir düzen içinde, okunaklı kartlarla sunulur.',
    icon: ShieldCheck,
  },
  {
    title: 'Gelişime açık',
    text: 'Frontend temeli hesaplama algoritmaları ve hesap sistemi eklenmeye hazırdır.',
    icon: TrendingUp,
  },
]

const faqs = [
  {
    question: 'Ders Rotası ücretsiz mi?',
    answer: 'Temel YKS araçlarının ücretsiz kullanılabilmesi hedefleniyor.',
  },
  {
    question: 'TYT net hesaplama çalışıyor mu?',
    answer: 'Evet. Bu sürümde TYT doğru-yanlış girişleriyle ders bazlı ve toplam net hesaplanır.',
  },
  {
    question: 'Mobilde kullanılabilir mi?',
    answer: 'Evet. Sayfa yapısı telefon, tablet ve masaüstü ekranlara uyumlu hazırlandı.',
  },
]

function Home() {
  return (
    <>
      <section className="hero-section">
        <Container className="hero-section__grid">
          <div className="hero-section__content">
            <p className="eyebrow">Ders Rotası ile YKS kontrol paneli</p>
            <h1>YKS yolculuğunu planla, netlerini hesapla, hedefini takip et</h1>
            <p>
              Ders Rotası; TYT net hesaplama, puan ve sıralama tahmini, pomodoro
              ve çalışma planı araçlarını tek bir modern eğitim platformunda
              toplamayı hedefler.
            </p>
            <div className="hero-section__actions">
              <Button to="/yks-siralama-tahmini" icon={ArrowRight}>
                YKS Sıralama Tahmini
              </Button>
              <Button to="/calisma-plani" icon={ClipboardList} variant="secondary">
                Çalışma Planı Oluştur
              </Button>
            </div>
          </div>

          <div className="hero-panel" aria-label="Ders Rotası örnek takip paneli">
            <div className="route-card">
              <span className="route-card__icon" aria-hidden="true">
                <Compass size={28} />
              </span>
              <div>
                <span>Bugünkü rota</span>
                <strong>TYT deneme analizi</strong>
              </div>
            </div>
            <div className="hero-metrics">
              <div>
                <span>Toplam hedef</span>
                <strong>85 net</strong>
              </div>
              <div>
                <span>Odak süresi</span>
                <strong>4 saat</strong>
              </div>
            </div>
            <div className="mini-bars" aria-hidden="true">
              <span style={{ height: '58%' }}></span>
              <span style={{ height: '74%' }}></span>
              <span style={{ height: '46%' }}></span>
              <span style={{ height: '86%' }}></span>
              <span style={{ height: '64%' }}></span>
            </div>
            <div className="hero-panel__note">
              <CheckCircle2 aria-hidden="true" size={18} />
              <span>TYT net hesaplama artık çalışır durumda.</span>
            </div>
          </div>
        </Container>
      </section>

      <section className="section">
        <Container>
          <div className="section-heading">
            <p className="eyebrow">Araçlar</p>
            <h2>YKS hazırlığında ihtiyacın olan temel ekranlar</h2>
          </div>
          <div className="tools-grid">
            {tools.map((tool) => (
              <ToolCard key={tool.path} {...tool} />
            ))}
          </div>
        </Container>
      </section>

      <section className="section section--tinted">
        <Container>
          <div className="section-heading">
            <p className="eyebrow">Nasıl çalışır?</p>
            <h2>Sınav hazırlığını küçük, takip edilebilir adımlara böler.</h2>
          </div>
          <div className="steps-grid">
            {steps.map((step, index) => (
              <article className="step-card" key={step.title}>
                <span>{index + 1}</span>
                <h3>{step.title}</h3>
                <p>{step.text}</p>
              </article>
            ))}
          </div>
        </Container>
      </section>

      <section className="section">
        <Container>
          <div className="section-heading">
            <p className="eyebrow">Neden Ders Rotası?</p>
            <h2>Dağınık hesapları ve hedefleri tek, güvenilir arayüzde toplar.</h2>
          </div>
          <div className="reason-grid">
            {reasons.map((reason) => (
              <article className="reason-card" key={reason.title}>
                <span className="reason-card__icon" aria-hidden="true">
                  <reason.icon size={24} />
                </span>
                <h3>{reason.title}</h3>
                <p>{reason.text}</p>
              </article>
            ))}
          </div>
        </Container>
      </section>

      <section className="free-banner">
        <Container className="free-banner__inner">
          <Sparkles aria-hidden="true" size={28} />
          <div>
            <h2>Temel araçlar ücretsiz kullanım odağıyla tasarlanıyor.</h2>
            <p>
              Ders Rotası, hesaplama ve planlama deneyimini sonraki aşamalarda
              daha kapsamlı sonuç ekranlarıyla geliştirmeye hazır.
            </p>
          </div>
        </Container>
      </section>

      <section className="section">
        <Container>
          <div className="section-heading">
            <p className="eyebrow">SSS</p>
            <h2>Sık sorulan sorular</h2>
          </div>
          <div className="faq-list">
            {faqs.map((faq) => (
              <article className="faq-item" key={faq.question}>
                <h3>{faq.question}</h3>
                <p>{faq.answer}</p>
              </article>
            ))}
          </div>
        </Container>
      </section>
    </>
  )
}

export default Home
