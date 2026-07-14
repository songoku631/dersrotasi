import { ArrowRight } from 'lucide-react'
import { Link } from 'react-router-dom'

function ToolCard({ description, icon: Icon, path, title }) {
  return (
    <Link className="tool-card" to={path}>
      <span className="tool-card__icon" aria-hidden="true">
        <Icon size={24} />
      </span>
      <span className="tool-card__content">
        <strong>{title}</strong>
        <span>{description}</span>
      </span>
      <ArrowRight className="tool-card__arrow" aria-hidden="true" size={20} />
    </Link>
  )
}

export default ToolCard
