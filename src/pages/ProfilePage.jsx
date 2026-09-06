import { Pencil, Save, Upload, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { getCurrentUser, profileMediaUrl, saveProfile, uploadProfilePhoto } from '../api/client'
import Button from '../components/Button'
import Container from '../components/Container'
import PageHeader from '../components/PageHeader'
import ProfilePhotoCropModal from '../components/profile/ProfilePhotoCropModal'
import UserAvatar from '../components/user/UserAvatar'
import { useAuth } from '../context/useAuth'
import { useUserPlan } from '../hooks/useUserPlan'
import { profilePayload } from '../utils/profile'

const educationLabels = { ortaokul: 'Ortaokul', lise: 'Lise', universite: 'Üniversite', mezun: 'Mezun' }
const emptyProfile = {
  username: '', first_name: '', last_name: '', birth_year: '', bio: '', education_status: '', school_name: '',
  graduated_high_school: '', university: '', department: '', grade_level: '', city: '', profile_photo_path: '',
  profile_visibility: 'private', email_public: 0, birth_year_public: 0,
  score_type: 'sayisal', target_rank: '', target_department: '', preferred_cities: '', university_type: 'Fark etmez',
  daily_study_hours: '', strong_lessons: '', improvement_lessons: '',
}

function valueOrDash(value) { return value === null || value === undefined || value === '' ? '—' : value }

function ProfilePage() {
  const { authLoading, user } = useAuth()
  const { plan } = useUserPlan(user)
  const [profile, setProfile] = useState(emptyProfile)
  const [draft, setDraft] = useState(emptyProfile)
  const [mode, setMode] = useState('view')
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [photoToCrop, setPhotoToCrop] = useState(null)
  const saveFeedback = useRef(null)
  const saving = useRef(false)

  const load = useCallback(async () => {
    if (!user) return
    setStatus('loading'); setError('')
    try {
      const response = await getCurrentUser(user)
      const next = { ...emptyProfile, ...(response.profile || {}) }
      setProfile(next); setDraft(next); setStatus('ready')
    } catch (requestError) { setError(requestError.message); setStatus('error') }
  }, [user])

  useEffect(() => { if (!authLoading) load() }, [authLoading, load])
  useEffect(() => {
    if (error || message) saveFeedback.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }, [error, message])

  function update(name, value) { setDraft((current) => ({ ...current, [name]: value })) }
  function notifyProfileUpdated() { window.dispatchEvent(new Event('dersrotasi:profile-updated')) }

  async function submit(event) {
    event.preventDefault()
    if (saving.current || status !== 'ready') return
    setError(''); setMessage('')
    try {
      const payload = profilePayload(draft)
      if (!event.currentTarget.checkValidity()) {
        const invalid = event.currentTarget.querySelector(':invalid')
        const label = invalid?.closest('label')?.querySelector('span')?.textContent || 'Profil alanı'
        invalid?.focus()
        throw new Error(`${label}: ${invalid?.validationMessage || 'Bu alanı kontrol et.'}`)
      }
      saving.current = true; setStatus('saving')
      const response = await saveProfile(user, payload)
      if (!response.profile || response.success === false) throw new Error('Profil kaydı doğrulanamadı. Lütfen tekrar dene.')
      const next = { ...emptyProfile, ...response.profile }
      setProfile(next); setDraft(next); setMode('view'); setStatus('ready')
      setMessage('Profil kaydedildi'); notifyProfileUpdated()
    } catch (requestError) { setError(requestError.message || 'Profil kaydedilemedi. Lütfen tekrar dene.'); setStatus('ready') }
    finally { saving.current = false }
  }

  async function changePhoto(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setError('JPEG, PNG veya WebP biçiminde en fazla 2 MB bir görsel seç.'); return
    }
    setError(''); setMessage(''); setPhotoToCrop(file)
  }

  async function saveCroppedPhoto(file) {
    setStatus('uploading'); setError(''); setMessage('')
    try {
      const response = await uploadProfilePhoto(user, file)
      const next = { ...profile, ...response.profile }
      setProfile(next); setDraft(next); setPhotoToCrop(null); setMessage('Profil fotoğrafın güncellendi.'); notifyProfileUpdated()
    } catch (requestError) { setError(requestError.message); throw requestError } finally { setStatus('ready') }
  }

  if (authLoading || status === 'loading') return <section className="auth-loading"><div><span className="auth-loading__mark" /><p>Profilin yükleniyor...</p></div></section>
  if (status === 'error') return <section className="section"><Container><div className="form-alert"><p>{error}</p></div><Button onClick={load}>Tekrar Dene</Button></Container></section>

  const displayName = [profile.first_name, profile.last_name].filter(Boolean).join(' ') || user.displayName || 'Dersrotası kullanıcısı'
  const photoUrl = profileMediaUrl(profile.profile_photo_path)
  const info = [
    ['İsim', profile.first_name], ['Soyisim', profile.last_name], ['Eğitim durumu', educationLabels[profile.education_status]],
    ['Okul adı', profile.school_name], ['Mezun olunan lise', profile.graduated_high_school], ['Üniversite', profile.university],
    ['Bölüm', profile.department], ['Sınıf', profile.grade_level], ['Şehir', profile.city],
  ]

  return <>
    <PageHeader title="Profilim" description="Kimliğini, eğitim bilgilerini ve YKS hedeflerini tek yerden yönet." />
    <section className="section"><Container>
      {error && mode === 'view' ? <div ref={saveFeedback} className="form-alert" role="alert"><p>{error}</p></div> : null}
      {message ? <div ref={saveFeedback} className="success-alert" role="status"><p>{message}</p></div> : null}
      <div className="profile-shell">
        <aside className="profile-summary">
          <UserAvatar className="profile-avatar" profile={profile} profilePhotoUrl={photoUrl} user={user} size={112} />
          <h2>{displayName}</h2><strong className="profile-username">@{profile.username || 'kullanici-adi-yok'}</strong>
          {profile.bio ? <p className="profile-bio">{profile.bio}</p> : null}
          <label className="profile-photo-button"><Upload size={17} /><span>{status === 'uploading' ? 'Yükleniyor...' : 'Fotoğrafı Değiştir'}</span><input accept="image/jpeg,image/png,image/webp" disabled={status !== 'ready'} onChange={changePhoto} type="file" /></label>
          <small>JPEG, PNG veya WebP · En fazla 2 MB</small>
          <div className="profile-private-info"><span>E-posta</span><strong>{user.email}</strong><small>Varsayılan olarak herkese açık değildir.</small></div>
          <div className="profile-plan"><small>Mevcut plan</small><strong>{plan?.is_premium ? 'Premium' : 'Ücretsiz'}</strong></div>
        </aside>

        <div className="profile-panel">
          <div className="profile-mode-header"><div><p className="eyebrow">{mode === 'view' ? 'Profil görünümü' : 'Düzenleme modu'}</p><h2>{mode === 'view' ? 'Profil bilgilerin' : 'Profilini düzenle'}</h2></div>{mode === 'view' ? <Button icon={Pencil} onClick={() => { setDraft(profile); setMode('edit'); setMessage('') }}>Profili Düzenle</Button> : null}</div>
          {mode === 'view' ? <div className="profile-view">
            <div className="profile-details">{info.map(([label, value]) => <div key={label}><span>{label}</span><strong>{valueOrDash(value)}</strong></div>)}</div>
            <div className="profile-privacy-note"><strong>Gizlilik</strong><p>Profilin şu anda {profile.profile_visibility === 'public' ? 'herkese açık' : 'gizli'} olarak ayarlı. Doğum yılını public profilde gösterme tercihin: {Number(profile.birth_year_public) ? 'Açık' : 'Kapalı'}.</p></div>
            <div className="profile-goals"><h3>YKS hedefleri</h3><p><strong>Hedef bölüm:</strong> {valueOrDash(profile.target_department)}</p><p><strong>Hedef sıralama:</strong> {valueOrDash(profile.target_rank)}</p><p><strong>Puan türü:</strong> {({ sayisal: 'Sayısal', esit_agirlik: 'Eşit Ağırlık', sozel: 'Sözel', dil: 'Dil' })[profile.score_type]}</p><p><strong>Günlük çalışma saati:</strong> {valueOrDash(profile.daily_study_hours)}</p></div>
          </div> : <form className="profile-form" noValidate onSubmit={submit}>
            <fieldset className="profile-form__fields" disabled={status !== 'ready'}>
              <h3>Temel bilgiler</h3><div className="profile-form__grid">
                <Field label="Kullanıcı adı"><input autoCapitalize="none" maxLength="24" pattern="[a-z0-9_]{3,24}" required value={draft.username || ''} onChange={(e) => update('username', e.target.value.toLowerCase())} /></Field>
                <Field label="E-posta"><input disabled type="email" value={user.email || ''} /></Field>
                <Field label="İsim"><input maxLength="80" value={draft.first_name} onChange={(e) => update('first_name', e.target.value)} /></Field>
                <Field label="Soyisim"><input maxLength="80" value={draft.last_name} onChange={(e) => update('last_name', e.target.value)} /></Field>
                <Field label="Doğum yılı"><input max={new Date().getFullYear()} min="1900" type="number" value={draft.birth_year || ''} onChange={(e) => update('birth_year', e.target.value)} /></Field>
                <Field label="Şehir"><input maxLength="100" value={draft.city} onChange={(e) => update('city', e.target.value)} /></Field>
                <Field className="profile-form__wide" label="Kısa biyografi"><textarea maxLength="300" value={draft.bio} onChange={(e) => update('bio', e.target.value)} /></Field>
              </div>
              <h3>Eğitim bilgileri</h3><div className="profile-form__grid">
                <Field label="Eğitim durumu"><select value={draft.education_status || ''} onChange={(e) => update('education_status', e.target.value)}><option value="">Seçilmedi</option>{Object.entries(educationLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
                <Field label="Okul adı"><input maxLength="180" value={draft.school_name} onChange={(e) => update('school_name', e.target.value)} /></Field>
                <Field label="Mezun olunan lise"><input maxLength="180" value={draft.graduated_high_school} onChange={(e) => update('graduated_high_school', e.target.value)} /></Field>
                <Field label="Üniversite"><input maxLength="180" value={draft.university} onChange={(e) => update('university', e.target.value)} /></Field>
                <Field label="Bölüm"><input maxLength="180" value={draft.department} onChange={(e) => update('department', e.target.value)} /></Field>
                <Field label="Sınıf"><input maxLength="40" value={draft.grade_level} onChange={(e) => update('grade_level', e.target.value)} /></Field>
              </div>
              <h3>YKS hedefleri</h3><div className="profile-form__grid">
                <Field label="Hedef bölüm"><input maxLength="160" value={draft.target_department} onChange={(e) => update('target_department', e.target.value)} /></Field>
                <Field label="Hedef sıralama"><input min="1" type="number" value={draft.target_rank || ''} onChange={(e) => update('target_rank', e.target.value)} /></Field>
                <Field label="Puan türü"><select value={draft.score_type} onChange={(e) => update('score_type', e.target.value)}><option value="sayisal">Sayısal</option><option value="esit_agirlik">Eşit Ağırlık</option><option value="sozel">Sözel</option><option value="dil">Dil</option></select></Field>
                <Field label="Günlük çalışma saati"><input inputMode="decimal" type="text" placeholder="Örn. 2,5" value={draft.daily_study_hours ?? ''} onChange={(e) => update('daily_study_hours', e.target.value)} /></Field>
              </div>
              <h3>Gizlilik</h3><div className="profile-form__grid">
                <Field label="Profil görünürlüğü"><select value={draft.profile_visibility} onChange={(e) => update('profile_visibility', e.target.value)}><option value="private">Gizli</option><option value="public">Herkese açık</option></select></Field>
                <label className="profile-checkbox"><input checked={Boolean(Number(draft.birth_year_public))} type="checkbox" onChange={(e) => update('birth_year_public', e.target.checked ? 1 : 0)} /><span>Doğum yılını ileride public profilde göster</span></label>
              </div>
            </fieldset>
            {error ? <div ref={saveFeedback} className="form-alert" role="alert"><p>{error}</p></div> : null}<div className="profile-form__actions"><Button disabled={status !== 'ready'} icon={Save} type="submit">{status === 'saving' ? 'Kaydediliyor...' : 'Kaydet'}</Button><Button disabled={status !== 'ready'} icon={X} type="button" variant="secondary" onClick={() => { setDraft(profile); setMode('view'); setError('') }}>İptal</Button></div>
          </form>}
        </div>
      </div>
    </Container></section>
    {photoToCrop ? <ProfilePhotoCropModal file={photoToCrop} onCancel={() => setPhotoToCrop(null)} onSave={saveCroppedPhoto} /> : null}
  </>
}

function Field({ children, className = '', label }) { return <label className={className}><span>{label}</span>{children}</label> }
export default ProfilePage
