import {
  Bot,
  Menu,
  MessageSquare,
  Plus,
  RotateCcw,
  Send,
  ShieldCheck,
  Sparkles,
  X,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  createAiConversation,
  getAiConversation,
  listAiConversations,
  sendAiMessage,
} from '../api/aiApi'
import { buildAiHistory } from '../api/aiHistory'
import { addFavorite, removeFavorite } from '../api/favoritesApi'
import { addPreference } from '../api/preferencesApi'
import GoogleLoginButton from '../components/auth/GoogleLoginButton'
import AiMarkdown from '../components/ai/AiMarkdown'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProgramCard from '../components/universities/ProgramCard'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'

const suggestions = [
  'Sıralamama uygun bölümler',
  'Favorilerimi karşılaştır',
  "İstanbul'daki mühendislikler",
  'Tercih listesi oluştur',
]

function conversationDate(conversation) {
  const value = conversation.last_message_at || conversation.created_at
  const date = value ? new Date(value) : null
  return date && !Number.isNaN(date.getTime()) ? date : null
}

function ConversationSidebar({
  activeConversationId,
  busy,
  conversations,
  error,
  onClose,
  onCreate,
  onOpen,
  onSelect,
  open,
}) {
  const today = new Date().toDateString()
  const todayItems = conversations.filter((item) => conversationDate(item)?.toDateString() === today)
  const olderItems = conversations.filter((item) => conversationDate(item)?.toDateString() !== today)

  function group(title, items) {
    if (items.length === 0) return null
    return (
      <section className="ai-history__group" key={title}>
        <h3>{title}</h3>
        <div className="ai-history__list">
          {items.map((conversation) => (
            <button
              aria-current={conversation.id === activeConversationId ? 'true' : undefined}
              className={conversation.id === activeConversationId ? 'active' : ''}
              disabled={busy}
              key={conversation.id}
              onClick={() => onSelect(conversation.id)}
              type="button"
            >
              <MessageSquare aria-hidden="true" />
              <span>{conversation.title}</span>
            </button>
          ))}
        </div>
      </section>
    )
  }

  return (
    <>
      <button
        aria-expanded={open}
        className="ai-history__mobile-toggle"
        onClick={open ? onClose : onOpen}
        type="button"
      >
        <Menu aria-hidden="true" /> Sohbetler
      </button>
      {open ? <button aria-label="Sohbet geçmişini kapat" className="ai-history__backdrop" onClick={onClose} type="button" /> : null}
      <aside className={`ai-history${open ? ' ai-history--open' : ''}`} aria-label="Sohbet geçmişi">
        <div className="ai-history__header">
          <div>
            <strong>Sohbetler</strong>
            <small>Kalıcı geçmişin</small>
          </div>
          <button aria-label="Sohbet geçmişini kapat" onClick={onClose} type="button">
            <X aria-hidden="true" />
          </button>
        </div>
        <button className="ai-history__new" disabled={busy} onClick={onCreate} type="button">
          <Plus aria-hidden="true" /> Yeni Sohbet
        </button>
        {error ? <p className="ai-history__error" role="alert">{error}</p> : null}
        <div className="ai-history__scroll">
          {conversations.length === 0 && !error ? <p className="ai-history__empty">Henüz sohbet yok.</p> : null}
          {group('Bugün', todayItems)}
          {group('Daha Eski', olderItems)}
        </div>
      </aside>
    </>
  )
}

function AiConversation({ plan, refreshPlan, user }) {
  const [conversations, setConversations] = useState([])
  const [activeConversationId, setActiveConversationId] = useState(null)
  const [messages, setMessages] = useState([])
  const [draft, setDraft] = useState('')
  const [status, setStatus] = useState('idle')
  const [historyStatus, setHistoryStatus] = useState('loading')
  const [historyError, setHistoryError] = useState('')
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [error, setError] = useState('')
  const [failedMessage, setFailedMessage] = useState('')
  const [failedRequestId, setFailedRequestId] = useState('')
  const [busyProgramId, setBusyProgramId] = useState(null)
  const [programAction, setProgramAction] = useState(null)
  const textareaRef = useRef(null)
  const messagesRef = useRef(null)
  const requestRef = useRef(null)
  const historyRequestRef = useRef(null)

  useEffect(() => {
    textareaRef.current?.focus()
  }, [activeConversationId])

  useEffect(() => {
    const controller = new AbortController()
    historyRequestRef.current = controller

    async function restoreHistory() {
      setHistoryStatus('loading')
      setHistoryError('')
      try {
        const listResponse = await listAiConversations(user, controller.signal)
        let items = listResponse.data?.items || []
        let active = items[0]
        if (!active) {
          const createResponse = await createAiConversation(user, controller.signal)
          active = createResponse.data
          items = [active]
        }
        const detailResponse = await getAiConversation(user, active.id, controller.signal)
        setConversations(items)
        setActiveConversationId(active.id)
        setMessages(detailResponse.data?.messages || [])
        setHistoryStatus('idle')
      } catch (restoreError) {
        if (restoreError.name === 'AbortError') return
        setHistoryError(restoreError.message)
        setHistoryStatus('error')
      } finally {
        if (historyRequestRef.current === controller) historyRequestRef.current = null
      }
    }

    restoreHistory()
    return () => controller.abort()
  }, [user])

  useEffect(() => {
    const element = messagesRef.current
    if (element) element.scrollTop = element.scrollHeight
  }, [messages, status])

  useEffect(() => () => {
    requestRef.current?.abort()
    historyRequestRef.current?.abort()
  }, [])

  function resetConversationUi() {
    setDraft('')
    setError('')
    setFailedMessage('')
    setFailedRequestId('')
    setProgramAction(null)
  }

  async function handleNewConversation() {
    if (status === 'loading' || historyStatus === 'loading') return
    const controller = new AbortController()
    historyRequestRef.current?.abort()
    historyRequestRef.current = controller
    setHistoryStatus('loading')
    setHistoryError('')
    try {
      const response = await createAiConversation(user, controller.signal)
      const conversation = response.data
      setConversations((current) => [
        conversation,
        ...current.filter((item) => item.id !== conversation.id),
      ])
      setActiveConversationId(conversation.id)
      setMessages([])
      resetConversationUi()
      setSidebarOpen(false)
      setHistoryStatus('idle')
    } catch (createError) {
      if (createError.name === 'AbortError') return
      setHistoryError(createError.message)
      setHistoryStatus('error')
    } finally {
      if (historyRequestRef.current === controller) historyRequestRef.current = null
    }
  }

  async function handleSelectConversation(conversationId) {
    if (conversationId === activeConversationId) {
      setSidebarOpen(false)
      return
    }
    if (status === 'loading' || historyStatus === 'loading') return
    const controller = new AbortController()
    historyRequestRef.current?.abort()
    historyRequestRef.current = controller
    setHistoryStatus('loading')
    setHistoryError('')
    setActiveConversationId(null)
    setMessages([])
    resetConversationUi()
    try {
      const response = await getAiConversation(user, conversationId, controller.signal)
      const selectedConversation = response.data?.conversation
      if (selectedConversation) {
        setConversations((current) => [
          selectedConversation,
          ...current.filter((item) => item.id !== selectedConversation.id),
        ])
      }
      setActiveConversationId(conversationId)
      setMessages(response.data?.messages || [])
      setSidebarOpen(false)
      setHistoryStatus('idle')
    } catch (loadError) {
      if (loadError.name === 'AbortError') return
      setHistoryError(loadError.message)
      setHistoryStatus('error')
    } finally {
      if (historyRequestRef.current === controller) historyRequestRef.current = null
    }
  }

  async function submit(message, appendUser = true, existingRequestId = '') {
    const cleanMessage = message.trim()
    if (!cleanMessage || status === 'loading' || historyStatus !== 'idle' || !activeConversationId) return

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
    setProgramAction(null)
    setStatus('loading')

    const controller = new AbortController()
    requestRef.current = controller
    const requestId = existingRequestId || crypto.randomUUID()

    try {
      const history = buildAiHistory(previousMessages)
      const response = await sendAiMessage(
        user,
        activeConversationId,
        cleanMessage,
        history,
        requestId,
        controller.signal,
      )
      setMessages((current) => [
        ...current,
        {
          id: crypto.randomUUID(),
          role: 'assistant',
          content: response.answer,
          programs: response.programs || response.data || [],
        },
      ])
      if (response.conversation) {
        setConversations((current) => [
          response.conversation,
          ...current.filter((item) => item.id !== response.conversation.id),
        ])
      }
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

  function updateProgramFavorite(program, isFavorite) {
    setMessages((current) => current.map((message) => ({
      ...message,
      programs: message.programs?.map((item) => (
        item.program_code === program.program_code
          ? { ...item, is_favorite: isFavorite ? 1 : 0, favorite_id: isFavorite ? item.id : null }
          : item
      )),
    })))
  }

  async function handleFavorite(program) {
    setBusyProgramId(program.id)
    setProgramAction(null)
    try {
      const response = Number(program.is_favorite)
        ? await removeFavorite(user, program.id)
        : await addFavorite(user, program.id)
      updateProgramFavorite(program, !Number(program.is_favorite))
      setProgramAction({ type: 'success', text: response.message })
    } catch (favoriteError) {
      setProgramAction({ type: 'error', text: favoriteError.message })
    } finally {
      setBusyProgramId(null)
    }
  }

  async function handlePreference(program) {
    setBusyProgramId(program.id)
    setProgramAction(null)
    try {
      const response = await addPreference(user, program.id)
      setProgramAction({ type: 'success', text: response.message })
    } catch (preferenceError) {
      setProgramAction({ type: 'error', text: preferenceError.message })
    } finally {
      setBusyProgramId(null)
    }
  }

  return (
    <div className="ai-assistant__workspace">
      <ConversationSidebar
        activeConversationId={activeConversationId}
        busy={status === 'loading' || historyStatus === 'loading'}
        conversations={conversations}
        error={historyError}
        onClose={() => setSidebarOpen(false)}
        onCreate={handleNewConversation}
        onOpen={() => setSidebarOpen(true)}
        onSelect={handleSelectConversation}
        open={sidebarOpen}
      />
      <section className="ai-assistant__chat" aria-label="Dersrotası AI sohbeti">
      <header className="ai-assistant__chat-header">
        <span className="ai-assistant__avatar"><Sparkles aria-hidden="true" /></span>
        <div>
          <strong>Dersrotası AI</strong>
          <small>Sıralaman, hedeflerin ve tercihlerin için burada.</small>
        </div>
        <div className="ai-assistant__quota" aria-live="polite">
          {plan?.is_admin ? (
            <strong>Admin • Sınırsız test</strong>
          ) : (
            <>
              <strong>{plan?.is_premium ? 'Premium' : 'Ücretsiz'}</strong>
              <small>
                Bugün {plan?.limits?.daily_requests ?? 0} mesaj hakkından{' '}
                {plan?.usage?.requests_remaining ?? 0} kaldı
              </small>
            </>
          )}
        </div>
      </header>

      <div aria-live="polite" className="ai-assistant__messages" ref={messagesRef}>
        {historyStatus === 'loading' ? (
          <div className="ai-assistant__history-loading" role="status">
            <span className="auth-loading__mark" />
            <p>Sohbet yükleniyor...</p>
          </div>
        ) : null}

        {historyStatus === 'error' ? (
          <div className="ai-assistant__error" role="alert">
            <p>{historyError}</p>
            <button onClick={handleNewConversation} type="button">
              <Plus aria-hidden="true" /> Yeni sohbet oluştur
            </button>
          </div>
        ) : null}

        {historyStatus === 'idle' && messages.length === 0 ? (
          <div className="ai-assistant__welcome">
            <span><Bot aria-hidden="true" /></span>
            <h2>Tercihlerini birlikte düşünelim.</h2>
            <p>Sıralaman, hedeflerin ve program verileri üzerinden seçeneklerini karşılaştır.</p>
            <div className="ai-assistant__chips">
              {suggestions.map((suggestion) => (
                <button
                  disabled={status === 'loading' || historyStatus !== 'idle'}
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
          <div
            className={`ai-assistant__message ai-assistant__message--${message.role}${message.programs?.length ? ' ai-assistant__message--programs' : ''}`}
            key={message.id}
          >
            <span>{message.role === 'assistant' ? 'Dersrotası AI' : 'Sen'}</span>
            <div className="ai-assistant__message-content">
              {message.role === 'assistant'
                ? <AiMarkdown content={message.content} />
                : message.content}
              {message.programs?.length ? (
                <div className="ai-assistant__programs">
                  {message.programs.map((program) => (
                    <ProgramCard
                      busy={busyProgramId === program.id}
                      key={program.id}
                      onFavorite={handleFavorite}
                      onPreference={handlePreference}
                      program={program}
                    />
                  ))}
                </div>
              ) : null}
            </div>
          </div>
        ))}

        {programAction ? (
          <div
            className={programAction.type === 'error' ? 'form-alert' : 'success-alert'}
            role="status"
          >
            <p>{programAction.text}</p>
          </div>
        ) : null}

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
            {!plan?.is_admin && !plan?.is_premium && error.toLocaleLowerCase('tr-TR').includes('günlük') ? (
              <Link to="/premium">Premium planı incele</Link>
            ) : null}
          </div>
        ) : null}
      </div>

      <form className="ai-assistant__composer" onSubmit={handleSubmit}>
        <textarea
          aria-label="Dersrotası AI'ya mesaj"
          disabled={status === 'loading' || historyStatus !== 'idle' || !activeConversationId}
          maxLength={plan?.limits?.max_message_chars ?? 1200}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="Örn. 80 bin sayısal sıralamayla İstanbul'da hangi mühendislikleri düşünebilirim?"
          ref={textareaRef}
          rows={3}
          value={draft}
        />
        <button
          disabled={status === 'loading' || historyStatus !== 'idle' || !activeConversationId || !draft.trim()}
          type="submit"
        >
          <Send aria-hidden="true" />
          <span>Gönder</span>
        </button>
      </form>
      <p className="ai-assistant__character-limit">
        Mesaj sınırı: {plan?.limits?.max_message_chars?.toLocaleString('tr-TR') ?? '1.200'} karakter
      </p>
      <p className="ai-assistant__notice">AI yanılabilir; nihai tercihini güncel ÖSYM kılavuzundan doğrula.</p>
      </section>
    </div>
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
          {!authLoading && user && planLoading && !plan ? (
            <div className="ai-assistant__loading" aria-live="polite"><p>Plan bilgin yükleniyor...</p></div>
          ) : null}
          {!authLoading && user && planError && !plan ? (
            <div className="ai-assistant__access">
              <div className="form-alert" role="alert"><p>{planError}</p></div>
              <button className="button button--secondary" onClick={refresh} type="button">Tekrar Dene</button>
            </div>
          ) : null}
          {!authLoading && user && plan ? (
            <AiConversation key={user.uid} plan={plan} refreshPlan={refresh} user={user} />
          ) : null}
        </Container>
      </section>
    </>
  )
}

export default AiAssistantPage
