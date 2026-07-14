import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/useAuth'

function ProtectedRoute() {
  const { isAuthenticated, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <section className="auth-loading" aria-live="polite">
        <div>
          <span className="auth-loading__mark"></span>
          <p>Oturum bilgilerin kontrol ediliyor...</p>
        </div>
      </section>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/giris" replace state={{ from: location }} />
  }

  return <Outlet />
}

export default ProtectedRoute
