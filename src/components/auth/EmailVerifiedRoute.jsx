import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

function EmailVerifiedRoute() {
  const { authLoading, emailVerificationRequired, user } = useAuth()
  const location = useLocation()

  if (authLoading) return null

  if (user && emailVerificationRequired) {
    return <Navigate to="/eposta-dogrula" replace state={{ from: location.pathname }} />
  }

  return <Outlet />
}

export default EmailVerifiedRoute
