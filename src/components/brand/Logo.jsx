import { Link } from 'react-router-dom'

function CompassMark({ className = '' }) {
  return (
    <span className={`logo__mark ${className}`.trim()} aria-hidden="true">
      <svg viewBox="0 0 32 32" role="img">
        <path
          d="M16 5.5a10.5 10.5 0 1 0 0 21 10.5 10.5 0 0 0 0-21Z"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
        />
        <path
          d="M20.9 10.7 17.8 18a1.6 1.6 0 0 1-.83.83l-7.27 3.08 3.08-7.27c.16-.38.46-.68.83-.83l7.29-3.11Z"
          fill="currentColor"
        />
        <circle cx="16" cy="16" r="1.45" fill="#38bdf8" />
      </svg>
    </span>
  )
}

function Logo({ className = '', compact = false, to = '/' }) {
  const content = (
    <>
      <CompassMark />
      {!compact ? (
        <span className="logo__text">
          <strong>Ders Rotası</strong>
          <small>YKS çalışma rotası</small>
        </span>
      ) : null}
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
