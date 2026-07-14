import { Save } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { getProfile, saveProfile } from '../../api/client'
import Button from '../Button'

const initialProfile = {
  scoreType: 'sayisal',
  targetRank: '',
  targetDepartment: '',
  preferredCities: '',
  universityType: 'Fark etmez',
  dailyStudyHours: '',
  strongLessons: '',
  improvementLessons: '',
}

const scoreTypes = [
  { label: 'Sayısal', value: 'sayisal' },
  { label: 'Eşit Ağırlık', value: 'esit_agirlik' },
  { label: 'Sözel', value: 'sozel' },
  { label: 'Dil', value: 'dil' },
]
const universityTypes = ['Fark etmez', 'Devlet', 'Vakıf']

const legacyScoreTypes = {
  Dil: 'dil',
  'E\u00c5\u0178it A\u00c4\u0178\u00c4\u00b1rl\u00c4\u00b1k': 'esit_agirlik',
  'Eşit Ağırlık': 'esit_agirlik',
  'Say\u00c4\u00b1sal': 'sayisal',
  'Sayısal': 'sayisal',
  'S\u00c3\u00b6zel': 'sozel',
  Sözel: 'sozel',
  dil: 'dil',
  esit_agirlik: 'esit_agirlik',
  sayisal: 'sayisal',
  sozel: 'sozel',
}

function normalizeScoreType(value) {
  return legacyScoreTypes[value] || initialProfile.scoreType
}

function normalizeUniversityType(value) {
  if (value === 'Vak\u00c4\u00b1f' || value === 'Vakıf') {
    return 'Vakıf'
  }

  return universityTypes.includes(value) ? value : initialProfile.universityType
}

function getProfileKey(uid) {
  return `dersrotasi_profile_${uid}`
}

function readLegacyProfile(storageKey) {
  try {
    const savedProfile = localStorage.getItem(storageKey)

    if (!savedProfile) {
      return null
    }

    const savedValues = JSON.parse(savedProfile)

    if (!savedValues || typeof savedValues !== 'object') {
      return null
    }

    return {
      ...initialProfile,
      ...savedValues,
      scoreType: normalizeScoreType(savedValues.scoreType),
      universityType: normalizeUniversityType(savedValues.universityType),
    }
  } catch {
    return null
  }
}

function fromApiProfile(profile) {
  return {
    scoreType: normalizeScoreType(profile.score_type),
    targetRank: profile.target_rank == null ? '' : String(profile.target_rank),
    targetDepartment: profile.target_department || '',
    preferredCities: profile.preferred_cities || '',
    universityType: normalizeUniversityType(profile.university_type),
    dailyStudyHours:
      profile.daily_study_hours == null
        ? ''
        : String(profile.daily_study_hours),
    strongLessons: profile.strong_lessons || '',
    improvementLessons: profile.improvement_lessons || '',
  }
}

function toApiProfile(profile) {
  return {
    score_type: normalizeScoreType(profile.scoreType),
    target_rank: profile.targetRank || null,
    target_department: profile.targetDepartment,
    preferred_cities: profile.preferredCities,
    university_type: normalizeUniversityType(profile.universityType),
    daily_study_hours: profile.dailyStudyHours || null,
    strong_lessons: profile.strongLessons,
    improvement_lessons: profile.improvementLessons,
  }
}

function ProfileForm({ user }) {
  const storageKey = useMemo(() => getProfileKey(user.uid), [user.uid])
  const [profile, setProfile] = useState(initialProfile)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)

  const loadProfile = useCallback(
    async (signal) => {
      setIsLoading(true)
      setMessage('')
      setError('')

      try {
        const response = await getProfile(user, signal)

        if (response.profile) {
          setProfile(fromApiProfile(response.profile))
          localStorage.removeItem(storageKey)
          return
        }

        const legacyProfile = readLegacyProfile(storageKey)

        if (!legacyProfile) {
          const creationResponse = await saveProfile(
            user,
            toApiProfile(initialProfile),
            signal,
          )
          setProfile(fromApiProfile(creationResponse.profile))
          return
        }

        const migrationResponse = await saveProfile(
          user,
          toApiProfile(legacyProfile),
          signal,
        )
        setProfile(fromApiProfile(migrationResponse.profile))
        localStorage.removeItem(storageKey)
        setMessage('Tarayıcıdaki eski profil bilgilerin hesabına aktarıldı.')
      } catch (requestError) {
        if (requestError.name !== 'AbortError') {
          setError(requestError.message)
        }
      } finally {
        if (!signal?.aborted) {
          setIsLoading(false)
        }
      }
    },
    [storageKey, user],
  )

  useEffect(() => {
    const controller = new AbortController()
    loadProfile(controller.signal)

    return () => controller.abort()
  }, [loadProfile])

  function updateField(field, value) {
    setProfile((current) => ({
      ...current,
      [field]: value,
    }))
  }

  function validateProfile() {
    const targetRank = profile.targetRank.trim()

    if (targetRank && (!/^\d+$/.test(targetRank) || Number(targetRank) < 1)) {
      return 'Hedef sıralama yalnızca pozitif tam sayı olabilir.'
    }

    if (profile.dailyStudyHours && Number(profile.dailyStudyHours) < 0) {
      return 'Günlük çalışma süresi negatif olamaz.'
    }

    return ''
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setMessage('')
    setError('')

    const validationError = validateProfile()

    if (validationError) {
      setError(validationError)
      return
    }

    setIsSaving(true)

    try {
      const response = await saveProfile(user, toApiProfile(profile))
      setProfile(fromApiProfile(response.profile))
      localStorage.removeItem(storageKey)
      setMessage('Profil bilgilerin başarıyla kaydedildi.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <form className="profile-form" onSubmit={handleSubmit}>
      {isLoading ? (
        <div className="form-alert" role="status">
          <p>Profil bilgilerin yükleniyor...</p>
        </div>
      ) : null}

      {error ? (
        <div className="form-alert" role="alert">
          <p>{error}</p>
        </div>
      ) : null}

      {message ? (
        <div className="success-alert" role="status">
          <p>{message}</p>
        </div>
      ) : null}

      <fieldset className="profile-form__fields" disabled={isLoading || isSaving}>
        <div className="profile-form__grid">
          <label>
            <span>Puan türü</span>
            <select
              value={profile.scoreType}
              onChange={(event) => updateField('scoreType', event.target.value)}
            >
              {scoreTypes.map((scoreType) => (
                <option key={scoreType.value} value={scoreType.value}>
                  {scoreType.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Hedef sıralama</span>
            <input
              inputMode="numeric"
              min="1"
              placeholder="Örn. 25000"
              type="number"
              value={profile.targetRank}
              onChange={(event) => updateField('targetRank', event.target.value)}
            />
          </label>

          <label>
            <span>Hedef bölüm</span>
            <input
              placeholder="Örn. Bilgisayar Mühendisliği"
              type="text"
              value={profile.targetDepartment}
              onChange={(event) =>
                updateField('targetDepartment', event.target.value)
              }
            />
          </label>

          <label>
            <span>Tercih edilen şehirler</span>
            <input
              placeholder="Örn. İstanbul, Ankara, İzmir"
              type="text"
              value={profile.preferredCities}
              onChange={(event) =>
                updateField('preferredCities', event.target.value)
              }
            />
          </label>

          <label>
            <span>Devlet / Vakıf tercihi</span>
            <select
              value={profile.universityType}
              onChange={(event) =>
                updateField('universityType', event.target.value)
              }
            >
              {universityTypes.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Günlük çalışma süresi</span>
            <input
              min="0"
              placeholder="Örn. 4"
              step="0.5"
              type="number"
              value={profile.dailyStudyHours}
              onChange={(event) =>
                updateField('dailyStudyHours', event.target.value)
              }
            />
          </label>

          <label>
            <span>Güçlü dersler</span>
            <textarea
              placeholder="Örn. Türkçe, Matematik"
              value={profile.strongLessons}
              onChange={(event) =>
                updateField('strongLessons', event.target.value)
              }
            />
          </label>

          <label>
            <span>Geliştirilmesi gereken dersler</span>
            <textarea
              placeholder="Örn. Fen Bilimleri, Sosyal Bilimler"
              value={profile.improvementLessons}
              onChange={(event) =>
                updateField('improvementLessons', event.target.value)
              }
            />
          </label>
        </div>
      </fieldset>

      <div className="profile-form__actions">
        <Button disabled={isLoading || isSaving} icon={Save} type="submit">
          {isSaving ? 'Kaydediliyor...' : 'Profili Kaydet'}
        </Button>
        {error && !isLoading ? (
          <Button type="button" variant="secondary" onClick={() => loadProfile()}>
            Tekrar Yükle
          </Button>
        ) : null}
      </div>
    </form>
  )
}

export default ProfileForm
