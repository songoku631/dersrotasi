import { useCallback, useEffect, useState } from 'react'
import { getFavorites, removeFavorite } from '../api/favoritesApi'

export function useFavorites(user, enabled = true) {
  const [favorites, setFavorites] = useState([])
  const [status, setStatus] = useState(enabled ? 'loading' : 'idle')
  const [message, setMessage] = useState('')
  const [busyId, setBusyId] = useState(null)

  const load = useCallback((signal) => {
    if (!enabled || !user) {
      setFavorites([])
      setStatus('idle')
      return Promise.resolve()
    }

    setStatus('loading')
    setMessage('')

    return getFavorites(user, signal)
      .then((response) => {
        setFavorites(response.data?.items || [])
        setStatus('ready')
      })
      .catch((error) => {
        if (error.name !== 'AbortError') {
          setMessage(error.message)
          setStatus('error')
        }
      })
  }, [enabled, user])

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal)
    return () => controller.abort()
  }, [load])

  const remove = useCallback(async (program) => {
    if (!user) return false

    setBusyId(program.id)
    setMessage('')
    try {
      const response = await removeFavorite(user, program.id)
      setFavorites((current) => current.filter((item) => item.id !== program.id))
      setMessage(response.message || 'Program favorilerinden çıkarıldı.')
      return true
    } catch (error) {
      setMessage(error.message)
      return false
    } finally {
      setBusyId(null)
    }
  }, [user])

  return { busyId, favorites, load, message, remove, status }
}
