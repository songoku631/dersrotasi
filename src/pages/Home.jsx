import { ArrowUpRight, Bot, CalendarDays, GraduationCap, ListChecks, Send } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { getProfile } from '../api/client'
import Container from '../components/Container'
import { useAuth } from '../context/useAuth'

const suggestions = [
  'Bana uygun üniversiteleri bul',
  'Tercih listesi oluştur',
  'Bölüm karşılaştır',
  '2026 sıralamalarını incele',
]

const shortcuts = [
  { title: 'Üniversite Tercih', text: 'Programları sıralama ve bölüme göre keşfet.', path: '/universite-tercih', icon: GraduationCap },
  { title: 'Tercihlerim', text: 'Kaydettiğin tercih listesini düzenle.', path: '/tercihlerim', icon: ListChecks },
  { title: 'Çalışma Planı', text: 'Hedefine uygun çalışma düzenini oluştur.', path: '/calisma-plani', icon: CalendarDays },
]

function Home() {
  const { authLoading, user } = useAuth()
  const navigate = useNavigate()
  const [profile, setProfile] = useState(null)
  const [question, setQuestion] = useState('')

  useEffect(() => {
    if (!user) { setProfile(null); return undefined }
    const controller = new AbortController()
    getProfile(user, controller.signal)
      .then((response) => setProfile(response.profile || null))
      .catch(() => {})
    return () => controller.abort()
  }, [user])

  function askAi(message) {
    const cleanMessage = message.trim()
    if (!cleanMessage) return
    const destination = { pathname: '/ai-asistan' }
    if (!user) {
      navigate('/giris', { state: { aiPrompt: cleanMessage, from: destination } })
      return
    }
    navigate(destination, { state: { aiPrompt: cleanMessage } })
  }

  function submit(event) {
    event.preventDefault()
    askAi(question)
  }

  const visibleName = profile?.first_name || profile?.username || user?.displayName?.split(' ')[0]

  return (
    <>
      <section className="home-ai">
        <Container className="home-ai__inner">
          <div className="home-ai__mark" aria-hidden="true"><Bot /></div>
          <p className="eyebrow">Dersrotası AI</p>
          <h1>
            {!authLoading && user
              ? `Merhaba${visibleName ? ` ${visibleName}` : ''} 👋 Bugün neye bakalım?`
              : 'YKS tercihlerini birlikte planlayalım'}
          </h1>
          <p className="home-ai__intro">Sıralamanı, düşündüğün bölümü veya hedeflerini yaz; rotanı birlikte netleştirelim.</p>

          <form className="home-ai__composer" onSubmit={submit}>
            <textarea
              aria-label="Dersrotası AI'ya sorunu yaz"
              maxLength="1200"
              onChange={(event) => setQuestion(event.target.value)}
              placeholder="140 bin sıralamayla İstanbul’da ne yazabilirim?"
              rows="3"
              value={question}
            />
            <button aria-label="Dersrotası AI'ya gönder" disabled={!question.trim()} type="submit"><Send /></button>
          </form>

          <div className="home-ai__suggestions" aria-label="Hızlı sorular">
            {suggestions.map((suggestion) => <button key={suggestion} onClick={() => askAi(suggestion)} type="button">{suggestion}</button>)}
          </div>
        </Container>
      </section>

      <section className="home-shortcuts">
        <Container>
          <p className="home-shortcuts__label">Hızlı erişim</p>
          <div className="home-shortcuts__grid">
            {shortcuts.map(({ icon: Icon, path, text, title }) => (
              <Link className="home-shortcut" key={path} to={path}>
                <span><Icon aria-hidden="true" /></span>
                <div><strong>{title}</strong><small>{text}</small></div>
                <ArrowUpRight aria-hidden="true" />
              </Link>
            ))}
          </div>
        </Container>
      </section>
    </>
  )
}

export default Home
