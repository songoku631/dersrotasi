import { Bot, RotateCcw, Send, ShieldCheck, Sparkles } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { sendAiMessage } from '../api/aiApi'
import { buildAiHistory } from '../api/aiHistory'
import GoogleLoginButton from '../components/auth/GoogleLoginButton'
import AiMarkdown from '../components/ai/AiMarkdown'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'

const suggestions = [
  'Sıralamama uygun bölümler',
  'Favorilerimi karşılaştır',
  "İstanbul'daki mühendislikler",
  'Tercih listesi oluştur',
]

function AiConversation({ plan, refreshPlan, user }) {
  const [messages, setMessages] = useState([])
  const [draft, setDraft] = useState('')
  const [status, setStatus] = useState('idle')
  const [error, setError] = useState('')
  const [failedMessage, setFailedMessage] = useState('')
  const [failedRequestId, setFailedRequestId] = useState('')
  const textareaRef = useRef(null)
  const messagesRef = useRef(null)
  const requestRef = useRef(null)

  useEffect(() => {
    textareaRef.current?.focus()
  }, [])

  useEffect(() => {
    const element = messagesRef.current
    if (element) element.scrollTop = element.scrollHeight
  }, [messages, status])

  useEffect(() => () => requestRef.current?.abort(), [])

  async function submit(message, appendUser = true, existingRequestId = '') {
    const cleanMessage = message.trim()
    if (!cleanMessage || status === 'loading') return

    const previousMessages = appendUser ? messages : messages.slice(0, -1)
    if (appendUser) {
      setMessages((current) => [
        ...current,
        { id: crypto.randomUUID(), role: 'user', content: cleanMessage },
      ])
    }
    setDraft('')
    setError('')
    setFailedMessage('')
    setFailedRequestId('')
    setStatus('loading')

    const controller = new AbortController()
    requestRef.current = controller
    const requestId = existingRequestId || crypto.randomUUID()

    try {
      const history = buildAiHistory(previousMessages)
      const response = await sendAiMessage(user, cleanMessage, history, requestId, controller.signal)
      setMessages((current) => [
        ...current,
        { id: crypto.randomUUID(), role: 'assistant', content: response.answer },
      ])
      setStatus('idle')
      refreshPlan()
    } catch (requestError) {
      if (requestError.name === 'AbortError') return
      setError(requestError.message)
      setFailedMessage(cleanMessage)
      setFailedRequestId(requestError.status ? '' : requestId)
      setStatus('error')
      refreshPlan()
    } finally {
      requestRef.current = null
    }
  }

  function handleSubmit(event) {
    event.preventDefault()
    submit(draft)
  }

  function handleKeyDown(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
      event.preventDefault()
      submit(draft)
    }
  }

  return (
    <section className="ai-assistant__chat" aria-label="Dersrotası AI sohbeti">
      <header className="ai-assistant__chat-header">
        <span className="ai-assistant__avatar"><Sparkles aria-hidden="true" /></span>
        <div>
          <strong>Dersrotası AI</strong>
          <small>Sıralaman, hedeflerin ve tercihlerin için burada.</small>
        </div>
        <div className="ai-assistant__quota" aria-live="polite">
          <strong>{plan?.is_premium ? 'Premium' : 'Ücretsiz'}</strong>
          <small>Bugün {plan?.usage?.requests_remaining ?? 0} mesaj kaldı</small>
        </div>
      </header>

      <div aria-live="polite" className="ai-assistant__messages" ref={messagesRef}>
        {messages.length === 0 ? (
          <div className="ai-assistant__welcome">
            <span><Bot aria-hidden="true" /></span>
            <h2>Tercihlerini birlikte düşünelim.</h2>
            <p>Sıralaman, hedeflerin ve program verileri üzerinden seçeneklerini karşılaştır.</p>
            <div className="ai-assistant__chips">
              {suggestions.map((suggestion) => (
                <button
                  disabled={status === 'loading'}
                  key={suggestion}
                  onClick={() => submit(suggestion)}
                  type="button"
                >
                  {suggestion}
                </button>
              ))}
            </div>
          </div>
        ) : null}

        {messages.map((message) => (
          <div className={`ai-assistant__message ai-assistant__message--${message.role}`} key={message.id}>
            <span>{message.role === 'assistant' ? 'Dersrotası AI' : 'Sen'}</span>
            <div className="ai-assistant__message-content">
              {message.role === 'assistant'
                ? <AiMarkdown content={message.content} />
                : message.content}
            </div>
          </div>
        ))}

        {status === 'loading' ? (
          <div className="ai-assistant__message ai-assistant__message--assistant" role="status">
            <span>Dersrotası AI</span>
            <div aria-label="Yanıt hazırlanıyor" className="ai-assistant__typing">
              <i /><i /><i />
            </div>
          </div>
        ) : null}

        {error ? (
          <div className="ai-assistant__error" role="alert">
            <p>{error}</p>
            <button onClick={() => submit(failedMessage, false, failedRequestId)} type="button">
              <RotateCcw aria-hidden="true" /> Tekrar dene
            </button>
            {!plan?.is_premium && error.toLocaleLowerCase('tr-TR').includes('günlük') ? (
              <Link to="/premium">Premium planı incele</Link>
            ) : null}
          </div>
        ) : null}
      </div>

      <form className="ai-assistant__composer" onSubmit={handleSubmit}>
        <textarea
          aria-label="Dersrotası AI'ya mesaj"
          disabled={status === 'loading'}
          maxLength={plan?.limits?.max_message_chars ?? 1200}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="Örn. 80 bin sayısal sıralamayla İstanbul'da hangi mühendislikleri düşünebilirim?"
          ref={textareaRef}
          rows={3}
          value={draft}
        />
        <button disabled={status === 'loading' || !draft.trim()} type="submit">
          <Send aria-hidden="true" />
          <span>Gönder</span>
        </button>
      </form>
      <p className="ai-assistant__character-limit">
        Mesaj sınırı: {plan?.limits?.max_message_chars?.toLocaleString('tr-TR') ?? '1.200'} karakter
      </p>
      <p className="ai-assistant__notice">AI yanılabilir; nihai tercihini güncel ÖSYM kılavuzundan doğrula.</p>
    </section>
  )
}

function AiAccessCard() {
  const { error, loginWithGoogle } = useAuth()
  const [isSigningIn, setIsSigningIn] = useState(false)
  const [localError, setLocalError] = useState('')

  async function handleLogin() {
    setIsSigningIn(true)
    setLocalError('')

    try {
      await loginWithGoogle()
    } catch (loginError) {
      setLocalError(loginError.message)
    } finally {
      setIsSigningIn(false)
    }
  }

  return (
    <section className="ai-assistant__access" aria-labelledby="ai-access-title">
      <span className="ai-assistant__access-icon"><ShieldCheck aria-hidden="true" /></span>
      <h2 id="ai-access-title">Sohbet etmek için giriş yap</h2>
      <p>
        Dersrotası AI, sana özel öneriler sunabilmek ve favorilerini güvenle
        kullanabilmek için Google hesabınla giriş yapmanı ister.
      </p>
      {localError || error ? (
        <div className="form-alert" role="alert">
          <p>{localError || error}</p>
        </div>
      ) : null}
      <GoogleLoginButton isLoading={isSigningIn} onClick={handleLogin} />
      <small>Kimlik doğrulama Firebase üzerinden güvenli biçimde yapılır.</small>
    </section>
  )
}

function AiAssistantPage() {
  const { authLoading, user } = useAuth()
  const { error: planError, loading: planLoading, plan, refresh } = useUserPlan(user)

  return (
    <>
      <PageHeader
        eyebrow="Akıllı tercih desteği"
        title="Dersrotası AI Tercih Asistanı"
        description="Hedef sıralamana, bölüm tercihlerine ve favorilerine göre sana yardımcı olur."
      />
      <section className="ai-assistant">
        <Container>
          {authLoading ? (
            <div className="ai-assistant__loading" aria-live="polite">
              <span className="auth-loading__mark" />
              <p>Oturumun doğrulanıyor...</p>
            </div>
          ) : null}
          {!authLoading && !user ? <AiAccessCard /> : null}
          {!authLoading && user && planLoading ? (
            <div className="ai-assistant__loading" aria-live="polite"><p>Plan bilgin yükleniyor...</p></div>
          ) : null}
          {!authLoading && user && planError ? (
            <div className="ai-assistant__access">
              <div className="form-alert" role="alert"><p>{planError}</p></div>
              <button className="button button--secondary" onClick={refresh} type="button">Tekrar Dene</button>
            </div>
          ) : null}
          {!authLoading && user && !planLoading && !planError && plan ? (
            <AiConversation key={user.uid} plan={plan} refreshPlan={refresh} user={user} />
          ) : null}
        </Container>
      </section>
    </>
  )
}

export default AiAssistantPage
