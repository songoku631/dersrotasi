import { Link } from 'react-router-dom'
function Logo({ className = '', to = '/' }) {
  const content = (
    <>
      <img alt="" aria-hidden="true" className="logo__image" height="64" src="/favicon.svg" width="64" />
      <span className="logo__wordmark">DersRotası</span>
    </>
  )

  if (to) {
    return (
      <Link className={`logo ${className}`.trim()} to={to} aria-label="Ders Rotası ana sayfa">
        {content}
      </Link>
    )
  }

  return <div className={`logo ${className}`.trim()}>{content}</div>
}

export default Logo
