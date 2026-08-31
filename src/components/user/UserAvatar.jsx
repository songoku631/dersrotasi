import { useEffect, useState } from 'react'

function getInitials(user, profile) {
  const fullName = [profile?.first_name, profile?.last_name].filter(Boolean).join(' ')
  const source = profile?.username || fullName || user?.displayName || user?.email || 'DR'
  const parts = source.trim().split(/\s+/).filter(Boolean)
  if (parts.length >= 2) return `${parts[0][0]}${parts[1][0]}`.toUpperCase()
  return source.slice(0, 2).toUpperCase()
}

function UserAvatar({ className = '', profile = null, profilePhotoUrl = '', size = 36, user }) {
  const [imageFailed, setImageFailed] = useState(false)
  const photoURL = profilePhotoUrl || user?.photoURL
  const displayName = profile?.username || user?.displayName || 'Kullanıcı'
  const initials = getInitials(user, profile)

  useEffect(() => setImageFailed(false), [photoURL])

  const style = { '--avatar-size': `${size}px` }
  if (photoURL && !imageFailed) {
    return <span className={`user-avatar ${className}`.trim()} style={style}><img alt={`${displayName} profil fotoğrafı`} referrerPolicy="no-referrer" src={photoURL} onError={() => setImageFailed(true)} /></span>
  }
  return <span aria-label={`${displayName} profil avatarı`} className={`user-avatar user-avatar--fallback ${className}`.trim()} role="img" style={style}>{initials}</span>
}

export default UserAvatar
