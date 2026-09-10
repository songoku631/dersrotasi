import { Clock3, Lock, Plus, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getProfile } from '../api/client'
import { createRoom, joinRoom, listRooms } from '../api/pomodoroApi'
import Button from '../components/Button'
import Container from '../components/Container'
import { useAuth } from '../context/useAuth'
import { defaultRoomName, formatTimer, roomSeconds, validateRoom } from '../utils/pomodoro'

function freshForm(name = 'Öğrenci odası') {
  return { name, category: 'TYT', description: '', visibility: 'public', password_protected: false, password: '', work_minutes: 25, break_minutes: 5, max_members: 12, voice_enabled: true, music_enabled: true }
}

function PomodoroPage() {
  const { user } = useAuth(); const navigate = useNavigate()
  const [profile, setProfile] = useState(null); const [rooms, setRooms] = useState([]); const [stats, setStats] = useState(null); const [filter, setFilter] = useState('all')
  const [modal, setModal] = useState(false); const [form, setForm] = useState(() => freshForm()); const [errors, setErrors] = useState({}); const [submitting, setSubmitting] = useState(false)
  const [joinTarget, setJoinTarget] = useState(null); const [joinPassword, setJoinPassword] = useState(''); const [joinError, setJoinError] = useState(''); const [joining, setJoining] = useState(false)
  const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [, tick] = useState(0)

  useEffect(() => { getProfile(user).then(response => setProfile(response.profile || null)).catch(() => {}) }, [user])
  useEffect(() => { const controller = new AbortController(); setLoading(true); listRooms(user, filter, controller.signal).then(response => { setRooms(response.data.items.map(room => ({ ...room, received_at: performance.now() }))); setStats(response.data.stats); setError('') }).catch(requestError => requestError.name !== 'AbortError' && setError(requestError.message)).finally(() => setLoading(false)); return () => controller.abort() }, [user, filter])
  useEffect(() => { const id = setInterval(() => tick(value => value + 1), 1000); return () => clearInterval(id) }, [])

  function openCreateModal() { setForm(freshForm(defaultRoomName(profile?.username, user?.displayName))); setErrors({}); setError(''); setModal(true) }

  async function submit(event) {
    event.preventDefault(); if (submitting) return
    const nextErrors = validateRoom(form); setErrors(nextErrors); if (Object.keys(nextErrors).length) return
    const roomTab = window.open('about:blank', '_blank')
    if (!roomTab) return setError('Yeni oda sekmesi açılamadı. Tarayıcının açılır pencere iznini kontrol et.')
    roomTab.opener = null; setSubmitting(true); setError('')
    const payload = { ...form }; if (!payload.password_protected) delete payload.password
    try {
      const response = await createRoom(user, payload); const createdRoom = response.data.room
      setRooms(current => createdRoom.visibility === 'public' ? [createdRoom, ...current.filter(room => room.id !== createdRoom.id)] : current)
      setModal(false); roomTab.location.replace(new URL(`/pomodoro/room/${createdRoom.id}`, window.location.origin).href)
    } catch (createError) { roomTab.close(); setError(createError.message) }
    finally { setSubmitting(false) }
  }

  function requestJoin(room) { if (room.password_protected) { setJoinTarget(room); setJoinPassword(''); setJoinError(''); return } enterRoom(room, '') }
  async function enterRoom(room, password) {
    if (joining) return; setJoining(true); setJoinError('')
    try { await joinRoom(user, room.id, password); setJoinTarget(null); navigate(`/pomodoro/room/${room.id}`) }
    catch (joinRequestError) { if (room.password_protected) setJoinError(joinRequestError.message); else setError(joinRequestError.message) }
    finally { setJoining(false) }
  }

  return <section className="pomodoro-page"><Container>
    <div className="pomodoro-hero"><div><span className="eyebrow">CANLI ODAKLAMA</span><h1>Pomodoro Odaları</h1><p>Birlikte çalış, daha uzun odaklan.</p></div><Button icon={Plus} onClick={openCreateModal}>Oda Oluştur</Button></div>
    {stats && <div className="pomo-stats"><span>Bugün <strong>{Math.round(stats.today_seconds / 60)} dk</strong></span><span>Bu hafta <strong>{Math.round(stats.week_seconds / 60)} dk</strong></span><span>Tamamlanan <strong>{stats.completed_cycles} tur</strong></span></div>}
    <div className="pomo-filters">{[['all','Tümü'],['TYT','TYT'],['AYT','AYT'],['popular','En kalabalık'],['new','Yeni açılan']].map(([value,label]) => <button className={filter === value ? 'active' : ''} key={value} onClick={() => setFilter(value)}>{label}</button>)}</div>
    {error && !modal && <div className="pomo-alert" role="alert">{error}</div>}
    {loading ? <p>Odalar yükleniyor…</p> : rooms.length === 0 ? <div className="pomo-empty"><Clock3/><h2>Henüz aktif oda yok</h2><p>İlk çalışma odasını sen aç.</p></div> : <div className="room-grid">{rooms.map(room => <article className="room-card" key={room.id}><div className="room-card__top"><span className="room-category">{room.category}</span><span>{room.password_protected ? <><Lock size={13}/> Şifreli</> : 'Herkese açık'}</span></div><h2>{room.name}</h2><p>{room.description || 'Birlikte sessizce odaklan.'}</p><div className="room-meta"><span><Users size={16}/>{room.member_count}/{room.max_members}</span><span>{room.work_minutes}/{room.break_minutes}</span><span className={`phase phase--${room.current_phase}`}>{room.current_phase === 'work' ? 'Çalışma' : room.current_phase === 'break' ? 'Mola' : 'Hazır'}</span></div><div className="room-card__bottom"><strong>{room.phase_ends_at ? formatTimer(roomSeconds(room, performance.now() - (room.received_at ?? performance.now()))) : 'Başlamadı'}</strong><Button onClick={() => requestJoin(room)}>Katıl</Button></div></article>)}</div>}

    {modal && <div className="modal-backdrop" onMouseDown={event => event.target === event.currentTarget && !submitting && setModal(false)}><form className="pomo-modal" onSubmit={submit}><div className="modal-title"><div><h2>Yeni oda oluştur</h2><p>Odak ritmini ve oda seçeneklerini belirle.</p></div><button type="button" disabled={submitting} onClick={() => setModal(false)} aria-label="Kapat">×</button></div>{error && <div className="pomo-alert" role="alert">{error}</div>}<label>Oda adı<input value={form.name} onChange={event => setForm({...form,name:event.target.value})}/>{errors.name && <small>{errors.name}</small>}</label><label>Ders / kategori<select value={form.category} onChange={event => setForm({...form,category:event.target.value})}><option>TYT</option><option>AYT</option><option>Matematik</option><option>Fen</option><option>Diğer</option></select></label><label>Açıklama<textarea maxLength="300" value={form.description} onChange={event => setForm({...form,description:event.target.value})}/></label><label>Görünürlük<select value={form.visibility} onChange={event => setForm({...form,visibility:event.target.value})}><option value="public">Herkese açık — listede görünür</option><option value="private">Özel — yalnız bağlantıyla</option></select></label><div className="switches"><label><input type="checkbox" checked={form.password_protected} onChange={event => setForm({...form,password_protected:event.target.checked,password:''})}/> Şifreli oda</label></div>{form.password_protected && <div className="form-row password-fields"><label>Oda şifresi<input type="password" minLength="4" maxLength="64" name="room-access-code" autoComplete="off" value={form.password} onChange={event => setForm({...form,password:event.target.value})}/>{errors.password && <small>{errors.password}</small>}</label></div>}<div className="form-row"><label>Çalışma (dk)<input type="number" min="5" max="180" value={form.work_minutes} onChange={event => setForm({...form,work_minutes:event.target.value})}/><small>{errors.work_minutes}</small></label><label>Mola (dk)<input type="number" min="1" max="60" value={form.break_minutes} onChange={event => setForm({...form,break_minutes:event.target.value})}/></label><label>Kapasite<input type="number" min="2" max="50" value={form.max_members} onChange={event => setForm({...form,max_members:event.target.value})}/></label></div><div className="switches"><label><input type="checkbox" checked={form.voice_enabled} onChange={event => setForm({...form,voice_enabled:event.target.checked})}/> Sesli sohbet</label><label><input type="checkbox" checked={form.music_enabled} onChange={event => setForm({...form,music_enabled:event.target.checked})}/> Ortak müzik</label></div><div className="pomo-modal__footer"><Button type="submit" disabled={submitting}>{submitting ? 'Oda Oluşturuluyor…' : 'Odayı Oluştur'}</Button></div></form></div>}
    {joinTarget && <div className="modal-backdrop"><form className="pomo-modal pomo-modal--small" onSubmit={event => { event.preventDefault(); enterRoom(joinTarget, joinPassword) }}><div className="modal-title"><div><h2>Bu oda şifreli</h2><p>Katılmak için oda şifresini gir.</p></div></div>{joinError && <div className="pomo-alert" role="alert">{joinError}</div>}<label>Oda şifresi<input autoFocus type="password" maxLength="64" name="room-access-code" autoComplete="off" value={joinPassword} onChange={event => setJoinPassword(event.target.value)}/></label><div className="timer-controls"><Button type="submit" disabled={joining || joinPassword.length < 4}>{joining ? 'Katılınıyor…' : 'Katıl'}</Button><Button type="button" variant="secondary" disabled={joining} onClick={() => setJoinTarget(null)}>Vazgeç</Button></div></form></div>}
  </Container></section>
}
export default PomodoroPage
