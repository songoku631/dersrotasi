import { useCallback, useEffect, useState } from 'react'
import { getMyPlan } from '../api/client'

export function useUserPlan(user) {
  const [plan, setPlan] = useState(null)
  const [loading, setLoading] = useState(Boolean(user))
  const [error, setError] = useState('')
  const [version, setVersion] = useState(0)

  const refresh = useCallback(() => setVersion((current) => current + 1), [])

  useEffect(() => {
    if (!user) {
      setPlan(null)
      setLoading(false)
      setError('')
      return undefined
    }

    const controller = new AbortController()
    let active = true
    setLoading(true)
    setError('')

    getMyPlan(user, controller.signal)
      .then((response) => {
        if (active) setPlan(response.data)
      })
      .catch((requestError) => {
        if (active && requestError.name !== 'AbortError') setError(requestError.message)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
      controller.abort()
    }
  }, [user, version])

  return { error, loading, plan, refresh }
}
