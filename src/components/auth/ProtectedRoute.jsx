import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

function ProtectedRoute() {
  const { authLoading, user } = useAuth()
  const location = useLocation()

  if (authLoading) {
    return (
      <section className="auth-loading" aria-live="polite">
        <div>
          <span className="auth-loading__mark"></span>
          <p>Oturumun doğrulanıyor...</p>
        </div>
      </section>
    )
  }

  if (!user) {
    return <Navigate to="/giris" replace state={{ from: location }} />
  }

  return <Outlet />
}

export default ProtectedRoute
