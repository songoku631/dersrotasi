import { Outlet } from 'react-router-dom'
import Footer from '../components/Footer'
import Header from '../components/Header'

function MainLayout() {
  return (
    <div className="app-shell">
      <Header />
      <main>
        <Outlet />
      </main>
      <Footer />
    </div>
  )
}

export default MainLayout
