import { useEffect, useState } from 'react'

function getInitials(user) {
  const source = user?.displayName || user?.email || 'DR'
  const parts = source
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (parts.length >= 2) {
    return `${parts[0][0]}${parts[1][0]}`.toUpperCase()
  }

  return source.slice(0, 2).toUpperCase()
}

function UserAvatar({ className = '', size = 36, user }) {
  const [imageFailed, setImageFailed] = useState(false)
  const photoURL = user?.photoURL
  const initials = getInitials(user)

  useEffect(() => {
    setImageFailed(false)
  }, [photoURL])

  const style = {
    '--avatar-size': `${size}px`,
  }

  if (photoURL && !imageFailed) {
    return (
      <span className={`user-avatar ${className}`.trim()} style={style}>
        <img
          alt={`${user?.displayName || 'Kullanıcı'} profil fotoğrafı`}
          referrerPolicy="no-referrer"
          src={photoURL}
          onError={() => setImageFailed(true)}
        />
      </span>
    )
  }

  return (
    <span
      aria-label={`${user?.displayName || 'Kullanıcı'} profil avatarı`}
      className={`user-avatar user-avatar--fallback ${className}`.trim()}
      role="img"
      style={style}
    >
      {initials}
    </span>
  )
}

export default UserAvatar
