import { Navigate, Route, Routes } from 'react-router-dom'
import ProtectedRoute from './components/auth/ProtectedRoute'
import EmailVerifiedRoute from './components/auth/EmailVerifiedRoute'
import MainLayout from './layouts/MainLayout'
import AiAssistantPage from './pages/AiAssistantPage'
import Home from './pages/Home'
import Login from './pages/Login'
import Register from './pages/Register'
import EmailVerificationPage from './pages/EmailVerificationPage'
import NotFound from './pages/NotFound'
import PlaceholderPage from './pages/PlaceholderPage'
import PreferencesPage from './pages/PreferencesPage'
import FavoritesPage from './pages/FavoritesPage'
import ProfilePage from './pages/ProfilePage'
import PremiumPage from './pages/PremiumPage'
import PremiumCheckoutPage from './pages/PremiumCheckoutPage'
import PremiumCancelPage from './pages/PremiumCancelPage'
import PremiumSuccessPage from './pages/PremiumSuccessPage'
import StudyPlanPage from './pages/StudyPlanPage'
import YksEstimatePage from './pages/YksEstimatePage'
import UniversityPreferencePage from './pages/UniversityPreferencePage'
import UniversityDetailPage from './pages/UniversityDetailPage'
import PomodoroPage from './pages/PomodoroPage'
import PomodoroRoomPage from './pages/PomodoroRoomPage'
import { tools } from './data/tools'

const placeholderRoutes = tools
  .filter(
    (tool) =>
      tool.path !== '/yks-siralama-tahmini'
      && tool.path !== '/calisma-plani'
      && tool.path !== '/pomodoro',
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
        <Route element={<EmailVerifiedRoute />}>
          <Route index element={<Home />} />
          <Route path="/ai-asistan" element={<AiAssistantPage />} />
          <Route path="/premium" element={<PremiumPage />} />
          <Route path="/premium/success" element={<PremiumSuccessPage />} />
          <Route path="/premium/cancel" element={<PremiumCancelPage />} />
        </Route>
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
          <Route path="/calisma-plani" element={<StudyPlanPage />} />
          <Route path="/pomodoro" element={<PomodoroPage />} />
          <Route path="/pomodoro/room/:roomId" element={<PomodoroRoomPage />} />
        </Route>
        <Route element={<ProtectedRoute />}>
          <Route path="/premium/checkout" element={<PremiumCheckoutPage />} />
        </Route>
        <Route path="/giris" element={<Login />} />
        <Route path="/login" element={<Login />} />
        <Route path="/kayit" element={<Register />} />
        <Route path="/eposta-dogrula" element={<EmailVerificationPage />} />
        <Route path="/kullanici-adi" element={<Navigate replace to="/" />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}

export default App
