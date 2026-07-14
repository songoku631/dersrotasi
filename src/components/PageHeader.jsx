import Container from './Container'

function PageHeader({ eyebrow = 'Ders Rotası', title, description }) {
  return (
    <section className="page-header">
      <Container>
        <p className="eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        <p>{description}</p>
      </Container>
    </section>
  )
}

export default PageHeader
