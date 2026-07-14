function PreferenceCard({ preference }) {
  return (
    <article className="preference-card">
      <h3>{preference.departmentName}</h3>
      <p>{preference.universityName}</p>
      <span>{preference.city}</span>
    </article>
  )
}

export default PreferenceCard
