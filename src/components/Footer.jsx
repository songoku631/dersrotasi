import { Link } from 'react-router-dom'
import { toolMenuItems } from '../data/tools'
import Logo from './brand/Logo'
import Container from './Container'

function Footer() {
  return (
    <footer className="site-footer">
      <Container className="site-footer__grid">
        <div>
          <Logo className="logo--footer" />
          <p>
            Ders Rotası, YKS öğrencilerinin hesaplama, planlama ve odak
            araçlarını tek yerde toplamak için hazırlanıyor.
          </p>
        </div>
        <div>
          <h2>Araçlar</h2>
          <nav aria-label="Footer araç menüsü">
            {toolMenuItems.map((tool) => (
              <Link key={tool.path} to={tool.path}>
                {tool.title}
              </Link>
            ))}
          </nav>
        </div>
        <div>
          <h2>Platform</h2>
          <nav aria-label="Footer platform menüsü">
            <Link to="/universite-tercih">Üniversite Tercih</Link>
            <Link to="/tercihlerim">Tercihlerim</Link>
            <Link to="/pomodoro">Pomodoro</Link>
            <Link to="/calisma-plani">Çalışma Planı</Link>
            <Link to="/profil">Profil</Link>
            <Link to="/giris">Giriş</Link>
          </nav>
        </div>
      </Container>
      <Container>
        <p className="site-footer__bottom">
          © 2026 Ders Rotası. Ücretsiz kullanım odaklı frontend prototipi.
        </p>
        <p className="site-footer__disclaimer">
          Ders Rotası bağımsız bir yardımcı araçtır. Herhangi bir resmî kurumun
          uygulaması değildir. Nihai tercihlerinizi ÖSYM’nin güncel kılavuzundan
          kontrol edin.
        </p>
      </Container>
    </footer>
  )
}

export default Footer
