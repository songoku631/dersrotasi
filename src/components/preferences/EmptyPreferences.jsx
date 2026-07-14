import { Search } from 'lucide-react'
import Button from '../Button'

function EmptyPreferences({ userName }) {
  return (
    <div className="empty-state">
      <span className="empty-state__icon" aria-hidden="true">
        <Search size={30} />
      </span>
      <p className="eyebrow">Tercihlerim</p>
      <h2>Henüz tercih eklemedin{userName ? `, ${userName}` : ''}.</h2>
      <p>
        Üniversite ve bölümleri keşfederek kendi tercih listeni oluşturabilirsin.
      </p>
      <Button to="/universite-tercih">Üniversiteleri Keşfet</Button>
    </div>
  )
}

export default EmptyPreferences
