import { Check, ChevronLeft, ChevronRight, LockKeyhole, Pencil, Plus, Sparkles, Trash2, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { getProfile } from '../api/client'
import { addStudyTask, applyGeneratedStudyPlan, clearStudyPlan, deleteStudyTask, generateStudyPlan, getStudyPlan, updateStudyTask } from '../api/studyPlansApi'
import Button from '../components/Button'
import Container from '../components/Container'
import PremiumGateModal from '../components/premium/PremiumGateModal'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'

const days = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar']
const shortDays = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz']
const commonSubjects = ['Türkçe', 'Matematik', 'Geometri', 'Fizik', 'Kimya', 'Biyoloji', 'Tarih', 'Coğrafya', 'Felsefe', 'Din', 'Edebiyat', 'Diğer']
const emptyTask = { day_of_week: 1, subject: 'Matematik', topic: '', duration_minutes: 45, question_target: '', note: '', is_completed: false }
const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
function currentMonday() { const date = new Date(); date.setDate(date.getDate() - ((date.getDay() + 6) % 7)); return localDate(date) }
function shiftWeek(value, amount) { const date = new Date(`${value}T12:00:00`); date.setDate(date.getDate() + amount * 7); return localDate(date) }
function dateForDay(week, day) { const date = new Date(`${week}T12:00:00`); date.setDate(date.getDate() + day - 1); return date }
function weekLabel(value) { const start = dateForDay(value, 1); const end = dateForDay(value, 7); const f = new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short' }); return `${f.format(start)} – ${f.format(end)}` }
function longDayLabel(week, day) { return `${days[day - 1]}, ${new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'long' }).format(dateForDay(week, day))}` }
function initialDay(week) { return week === currentMonday() ? new Date().getDay() || 7 : 1 }

function TaskModal({ task, onClose, onSave, busy }) {
  const knownSubject = commonSubjects.includes(task.subject) && task.subject !== 'Diğer'
  const [subjectChoice, setSubjectChoice] = useState(knownSubject ? task.subject : 'Diğer')
  const [form, setForm] = useState(task)
  function chooseSubject(value) { setSubjectChoice(value); if (value !== 'Diğer') setForm({ ...form, subject: value }); else setForm({ ...form, subject: '' }) }
  return <div className="premium-modal-backdrop" role="presentation" onMouseDown={onClose}><form className="premium-modal study-task-modal study-compact-modal" role="dialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()} onSubmit={(event) => { event.preventDefault(); onSave(form) }}>
    <button className="premium-modal__close" type="button" aria-label="Kapat" onClick={onClose}><X /></button><h2>{task.id ? 'Görevi Düzenle' : 'Görev Ekle'}</h2>
    <div className="study-form-grid">
      {task.id ? <label><span>Gün / başka güne taşı</span><select value={form.day_of_week} onChange={(event) => setForm({ ...form, day_of_week: Number(event.target.value) })}>{days.map((day, index) => <option value={index + 1} key={day}>{day}</option>)}</select></label> : null}
      <label><span>Ders</span><select value={subjectChoice} onChange={(event) => chooseSubject(event.target.value)}>{commonSubjects.map((subject) => <option key={subject}>{subject}</option>)}</select></label>
      {subjectChoice === 'Diğer' ? <label className="study-form-grid__wide"><span>Ders adı</span><input required maxLength="80" value={form.subject} onChange={(event) => setForm({ ...form, subject: event.target.value })} /></label> : null}
      <label className="study-form-grid__wide"><span>Konu</span><input required autoFocus maxLength="160" value={form.topic} onChange={(event) => setForm({ ...form, topic: event.target.value })} /></label>
      <label><span>Süre</span><div className="study-input-suffix"><input required type="number" min="5" max="720" value={form.duration_minutes} onChange={(event) => setForm({ ...form, duration_minutes: Number(event.target.value) })} /><small>dk</small></div></label>
      <label><span>Soru hedefi</span><input type="number" min="1" max="5000" placeholder="Opsiyonel" value={form.question_target ?? ''} onChange={(event) => setForm({ ...form, question_target: event.target.value })} /></label>
      <label className="study-form-grid__wide"><span>Not</span><input maxLength="1000" placeholder="Opsiyonel" value={form.note || ''} onChange={(event) => setForm({ ...form, note: event.target.value })} /></label>
    </div><Button type="submit" disabled={busy}>{busy ? 'Kaydediliyor...' : 'Kaydet'}</Button>
  </form></div>
}

function AiPlanModal({ profile, onClose, onGenerate, busy }) {
  const [form, setForm] = useState({ exam_type: 'tyt_ayt', score_type: profile?.score_type || 'sayisal', daily_minutes: profile?.daily_study_hours ? Math.round(Number(profile.daily_study_hours) * 60) : 180, target_rank: profile?.target_rank || '', weak_subjects: profile?.improvement_lessons || '', note: '' })
  return <div className="premium-modal-backdrop" role="presentation" onMouseDown={onClose}><form className="premium-modal study-ai-modal study-compact-modal" role="dialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()} onSubmit={(event) => { event.preventDefault(); onGenerate({ ...form, working_days: [1, 2, 3, 4, 5, 6, 7], exam_timing: '2027 YKS', strong_subjects: profile?.strong_lessons || '' }) }}>
    <button className="premium-modal__close" type="button" aria-label="Kapat" onClick={onClose}><X /></button><p className="eyebrow">Premium AI</p><h2>Planını birkaç adımda oluştur</h2>
    <div className="study-form-grid">
      <label><span>Sınav</span><select value={form.exam_type} onChange={(event) => setForm({ ...form, exam_type: event.target.value })}><option value="tyt">TYT</option><option value="ayt">AYT</option><option value="tyt_ayt">TYT + AYT</option></select></label>
      <label><span>Alan</span><select value={form.score_type} onChange={(event) => setForm({ ...form, score_type: event.target.value })}><option value="sayisal">Sayısal</option><option value="esit_agirlik">EA</option><option value="sozel">Sözel</option><option value="dil">Dil</option></select></label>
      <label><span>Günlük çalışma</span><div className="study-input-suffix"><input type="number" min="30" max="720" required value={form.daily_minutes} onChange={(event) => setForm({ ...form, daily_minutes: Number(event.target.value) })} /><small>dk</small></div></label>
      <label><span>Hedef sıralama</span><input type="number" min="1" max="10000000" value={form.target_rank} onChange={(event) => setForm({ ...form, target_rank: event.target.value })} /></label>
      <label className="study-form-grid__wide"><span>Zorlandığın dersler</span><input maxLength="500" value={form.weak_subjects} onChange={(event) => setForm({ ...form, weak_subjects: event.target.value })} /></label>
      <label className="study-form-grid__wide"><span>Özel not</span><input maxLength="1000" placeholder="Opsiyonel" value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} /></label>
    </div><Button type="submit" icon={Sparkles} disabled={busy}>{busy ? 'Hazırlanıyor...' : 'Planımı Oluştur'}</Button>
  </form></div>
}

function PreviewModal({ preview, onClose, onApply, busy }) {
  return <div className="premium-modal-backdrop" role="presentation" onMouseDown={onClose}><section className="premium-modal study-preview-modal" role="dialog" aria-modal="true" onMouseDown={(event) => event.stopPropagation()}>
    <button className="premium-modal__close" type="button" aria-label="Kapat" onClick={onClose}><X /></button><p className="eyebrow">Plan önizlemesi</p><h2>{preview.summary}</h2><p className="study-preview-warning">Bu plan mevcut haftandaki görevlere eklenecek.</p>
    <div className="study-preview-list">{preview.tasks.map((task, index) => <div key={`${task.day_of_week}-${index}`}><strong>{shortDays[task.day_of_week - 1]}</strong><span>{task.subject} · {task.topic}</span><small>{task.duration_minutes} dk{task.question_target ? ` · ${task.question_target} soru` : ''}</small></div>)}</div>
    <small className="premium-analysis__disclaimer">{preview.disclaimer}</small><div className="premium-modal__actions"><Button onClick={onApply} disabled={busy}>{busy ? 'Kaydediliyor...' : 'Planı Onayla ve Ekle'}</Button><Button variant="secondary" onClick={onClose}>Vazgeç</Button></div>
  </section></div>
}

function StudyTask({ task, onToggle, onEdit, onRemove, board = false }) {
  return <article className={`${board ? 'study-board-task' : 'study-compact-task'} ${task.is_completed ? (board ? 'study-board-task--done' : 'study-compact-task--done') : ''}`}>
    <button type="button" className="study-task__check" aria-label="Tamamlanma durumunu değiştir" onClick={() => onToggle(task)}>{task.is_completed ? <Check /> : null}</button>
    <button type="button" className="study-task__content" onClick={() => onEdit(task)}><strong>{task.subject}</strong><span>{task.topic}</span><small>{task.duration_minutes} dk{task.question_target ? ` • ${task.question_target} soru` : ''}</small></button>
    <div className="study-task__actions"><button type="button" aria-label="Düzenle veya başka güne taşı" onClick={() => onEdit(task)}><Pencil /></button><button type="button" aria-label="Sil" onClick={() => onRemove(task)}><Trash2 /></button></div>
  </article>
}

function StudyPlanPage() {
  const { user } = useAuth()
  const { plan: userPlan, loading: planLoading, refresh: refreshPlan } = useUserPlan(user)
  const [weekStart, setWeekStart] = useState(currentMonday)
  const [selectedDay, setSelectedDay] = useState(() => initialDay(currentMonday()))
  const [plan, setPlan] = useState({ tasks: [], progress: { completed: 0, total: 0 } })
  const [profile, setProfile] = useState(null)
  const [status, setStatus] = useState('loading')
  const [message, setMessage] = useState('')
  const [taskModal, setTaskModal] = useState(null)
  const [aiOpen, setAiOpen] = useState(false)
  const [preview, setPreview] = useState(null)
  const [gateOpen, setGateOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const hasPremium = Boolean(userPlan?.has_premium_access)

  const load = useCallback((signal, showLoading = true) => {
    if (showLoading) { setStatus('loading'); setMessage('') }
    return Promise.all([getStudyPlan(user, weekStart, signal), getProfile(user, signal)])
      .then(([planResponse, profileResponse]) => { setPlan(planResponse.data); setProfile(profileResponse.profile); setStatus('ready') })
      .catch((error) => { if (error.name !== 'AbortError') { setMessage(error.message); setStatus('error') } })
  }, [user, weekStart])
  useEffect(() => { const controller = new AbortController(); load(controller.signal); return () => controller.abort() }, [load])

  const byDay = useMemo(() => days.map((_, index) => plan.tasks.filter((task) => task.day_of_week === index + 1)), [plan.tasks])
  const selectedTasks = byDay[selectedDay - 1]
  const percent = plan.progress.total ? Math.round(plan.progress.completed / plan.progress.total * 100) : 0
  const todayTasks = weekStart === currentMonday() ? byDay[initialDay(weekStart) - 1] : []
  const todayCompleted = todayTasks.filter((task) => task.is_completed).length
  const todayRemaining = todayTasks.filter((task) => !task.is_completed).reduce((sum, task) => sum + task.duration_minutes, 0)

  function changeWeek(amount) { const next = shiftWeek(weekStart, amount); setWeekStart(next); setSelectedDay(initialDay(next)) }
  async function refreshAfter(messageText) { setMessage(messageText); await load(undefined, false) }
  async function saveTask(form) { setBusy(true); try { const response = form.id ? await updateStudyTask(user, form.id, form) : await addStudyTask(user, weekStart, form); setTaskModal(null); await refreshAfter(response.message) } catch (error) { setMessage(error.message) } finally { setBusy(false) } }
  async function toggleTask(task) { try { const response = await updateStudyTask(user, task.id, { is_completed: !task.is_completed }); await refreshAfter(response.message) } catch (error) { setMessage(error.message) } }
  async function removeTask(task) { if (!window.confirm(`“${task.topic}” görevini silmek istiyor musun?`)) return; try { const response = await deleteStudyTask(user, task.id); await refreshAfter(response.message) } catch (error) { setMessage(error.message) } }
  async function clearWeek() { if (!plan.tasks.length || !window.confirm('Bu haftadaki tüm görevleri silmek istediğine emin misin?')) return; try { const response = await clearStudyPlan(user, weekStart); await refreshAfter(response.message) } catch (error) { setMessage(error.message) } }
  function openAi() { if (planLoading) return; if (!hasPremium) { setGateOpen(true); return } setAiOpen(true) }
  async function generate(form) { setBusy(true); setMessage(''); try { const response = await generateStudyPlan(user, weekStart, form, crypto.randomUUID()); setPreview(response.data); setAiOpen(false); refreshPlan() } catch (error) { setMessage(error.message) } finally { setBusy(false) } }
  async function applyPreview() { setBusy(true); try { const response = await applyGeneratedStudyPlan(user, weekStart, preview.tasks); setPlan(response.data.plan); setMessage(response.message); setPreview(null) } catch (error) { setMessage(error.message) } finally { setBusy(false) } }

  return <><section className="study-planner"><Container>
    <header className="study-planner__header"><div><div className="study-planner__title"><h1>Çalışma Planım</h1>{userPlan?.is_admin ? <span className="study-role-badge">Admin • Test modu</span> : userPlan?.is_premium ? <span className="study-role-badge">Premium</span> : null}</div><p>Bu hafta {plan.progress.completed} / {plan.progress.total} görev tamamlandı</p><div className="study-progress"><span style={{ width: `${percent}%` }} /></div>{weekStart === currentMonday() && todayTasks.length ? <small className="study-today-summary"><strong>Bugün</strong> {todayCompleted} / {todayTasks.length} tamamlandı • {Math.floor(todayRemaining / 60) ? `${Math.floor(todayRemaining / 60)} sa ` : ''}{todayRemaining % 60} dk kaldı</small> : null}</div><Button icon={hasPremium ? Sparkles : LockKeyhole} variant={hasPremium ? 'primary' : 'secondary'} onClick={openAi} disabled={planLoading}>AI ile Plan Oluştur</Button></header>
    <nav className="study-week-navigation" aria-label="Hafta seçimi"><button type="button" onClick={() => changeWeek(-1)}><ChevronLeft /> Önceki Hafta</button><strong>{weekLabel(weekStart)}</strong><button type="button" onClick={() => changeWeek(1)}>Sonraki Hafta <ChevronRight /></button>{weekStart !== currentMonday() ? <button type="button" className="study-week-navigation__today" onClick={() => { setWeekStart(currentMonday()); setSelectedDay(initialDay(currentMonday())) }}>Bugüne Dön</button> : null}</nav>
    <div className="study-day-tabs study-day-tabs--mobile" role="tablist" aria-label="Haftanın günleri">{days.map((day, index) => { const dayTasks = byDay[index]; const completed = dayTasks.filter((task) => task.is_completed).length; const date = dateForDay(weekStart, index + 1); return <button type="button" role="tab" aria-selected={selectedDay === index + 1} className={selectedDay === index + 1 ? 'active' : ''} key={day} onClick={() => setSelectedDay(index + 1)}><strong>{shortDays[index]}</strong><span>{new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short' }).format(date)}</span><small>{completed}/{dayTasks.length}</small></button> })}</div>
    {message ? <div className={status === 'error' ? 'form-alert' : 'success-alert'} role="status"><p>{message}</p></div> : null}{status === 'loading' ? <div className="loading-panel">Planın yükleniyor...</div> : null}{status === 'error' ? <Button onClick={() => load()}>Yeniden Dene</Button> : null}
    {status === 'ready' ? <><div className="study-week-board">{days.map((day, index) => { const dayTasks = byDay[index]; const completed = dayTasks.filter((task) => task.is_completed).length; return <section className="study-board-day" key={day}><header><strong>{shortDays[index].toUpperCase()}</strong><span>{new Intl.DateTimeFormat('tr-TR', { day: 'numeric', month: 'short' }).format(dateForDay(weekStart, index + 1))}</span><small>{completed}/{dayTasks.length}</small></header><div className="study-board-day__tasks">{dayTasks.map((task) => <StudyTask key={task.id} task={task} board onToggle={toggleTask} onEdit={setTaskModal} onRemove={removeTask} />)}</div><button className="study-board-day__add" type="button" onClick={() => setTaskModal({ ...emptyTask, day_of_week: index + 1 })}><Plus /> Görev Ekle</button></section> })}</div><section className="study-selected-day study-selected-day--mobile"><div className="study-selected-day__header"><div><h2>{longDayLabel(weekStart, selectedDay)}</h2><p>{selectedTasks.reduce((sum, task) => sum + task.duration_minutes, 0)} dakika • {selectedTasks.length} görev</p></div><Button icon={Plus} variant="secondary" onClick={() => setTaskModal({ ...emptyTask, day_of_week: selectedDay })}>Görev Ekle</Button></div><div className="study-compact-list">{selectedTasks.length ? selectedTasks.map((task) => <StudyTask key={task.id} task={task} onToggle={toggleTask} onEdit={setTaskModal} onRemove={removeTask} />) : <div className="study-empty-day"><p>Bu gün için henüz görev yok.</p><button type="button" onClick={() => setTaskModal({ ...emptyTask, day_of_week: selectedDay })}>+ İlk görevi ekle</button></div>}</div></section>{plan.tasks.length ? <button className="study-clear-week" type="button" onClick={clearWeek}>Haftadaki tüm görevleri sil</button> : null}</> : null}
  </Container></section>{taskModal ? <TaskModal task={taskModal} onClose={() => setTaskModal(null)} onSave={saveTask} busy={busy} /> : null}{aiOpen ? <AiPlanModal profile={profile} onClose={() => setAiOpen(false)} onGenerate={generate} busy={busy} /> : null}{preview ? <PreviewModal preview={preview} onClose={() => setPreview(null)} onApply={applyPreview} busy={busy} /> : null}<PremiumGateModal open={gateOpen} onClose={() => setGateOpen(false)} title="AI destekli çalışma planı Dersrotası Premium’a özel." description="Manuel planın tamamen kullanıma açık. AI ile otomatik plan için Premium’u inceleyebilirsin." /></>
}

export default StudyPlanPage
