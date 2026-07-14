import { Navigate, Route, Routes } from 'react-router-dom'
import ProtectedRoute from './components/auth/ProtectedRoute'
import MainLayout from './layouts/MainLayout'
import Home from './pages/Home'
import Login from './pages/Login'
import NotFound from './pages/NotFound'
import PlaceholderPage from './pages/PlaceholderPage'
import PreferencesPage from './pages/PreferencesPage'
import FavoritesPage from './pages/FavoritesPage'
import ProfilePage from './pages/ProfilePage'
import YksEstimatePage from './pages/YksEstimatePage'
import UniversityPreferencePage from './pages/UniversityPreferencePage'
import UniversityDetailPage from './pages/UniversityDetailPage'
import { tools } from './data/tools'

const placeholderRoutes = tools
  .filter(
    (tool) =>
      tool.path !== '/yks-siralama-tahmini' && tool.path !== '/calisma-plani',
  )
  .map((tool) => ({
    path: tool.path,
    title: tool.title,
    description: tool.description,
  }))

function App() {
  return (
    <Routes>
      <Route element={<MainLayout />}>
        <Route index element={<Home />} />
        <Route path="/yks-siralama-tahmini" element={<YksEstimatePage />} />
        <Route path="/tyt-net-hesaplama" element={<Navigate replace to="/yks-siralama-tahmini" />} />
        <Route path="/ayt-net-hesaplama" element={<Navigate replace to="/yks-siralama-tahmini" />} />
        <Route path="/obp-hesaplama" element={<Navigate replace to="/yks-siralama-tahmini" />} />
        <Route path="/universite-tercih" element={<UniversityPreferencePage />} />
        <Route path="/universite-tercih/:id" element={<UniversityDetailPage />} />
        {placeholderRoutes.map((route) => (
          <Route
            key={route.path}
            path={route.path}
            element={
              <PlaceholderPage
                title={route.title}
                description={route.description}
              />
            }
          />
        ))}
        <Route element={<ProtectedRoute />}>
          <Route path="/profil" element={<ProfilePage />} />
          <Route path="/favorilerim" element={<FavoritesPage />} />
          <Route path="/tercihlerim" element={<PreferencesPage />} />
          <Route
            path="/calisma-plani"
            element={
              <PlaceholderPage
                title="Çalışma Planı"
                description="Haftalık hedeflerini derslere göre planlamak için başlangıç noktası."
              />
            }
          />
        </Route>
        <Route path="/giris" element={<Login />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}

export default App
