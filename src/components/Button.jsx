import { Link } from 'react-router-dom'

function Button({
  children,
  className = '',
  icon: Icon,
  to,
  type = 'button',
  variant = 'primary',
  ...props
}) {
  const classes = `button button--${variant} ${className}`.trim()
  const content = (
    <>
      {Icon ? <Icon aria-hidden="true" size={18} /> : null}
      <span>{children}</span>
    </>
  )

  if (to) {
    return (
      <Link className={classes} to={to} {...props}>
        {content}
      </Link>
    )
  }

  return (
    <button className={classes} type={type} {...props}>
      {content}
    </button>
  )
}

export default Button
