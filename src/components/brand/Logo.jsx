import { Link } from 'react-router-dom'
import dersrotasiLogo from '../../assets/dersrotasi-logo.png'

function Logo({ className = '', to = '/' }) {
  const content = (
    <img
      alt="DersRotası"
      className="logo__image"
      height="1254"
      src={dersrotasiLogo}
      width="1254"
    />
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
