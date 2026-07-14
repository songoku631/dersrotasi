import PreferenceCard from './PreferenceCard'

function PreferenceList({ preferences }) {
  return (
    <div className="preference-list">
      {preferences.map((preference) => (
        <PreferenceCard key={preference.id} preference={preference} />
      ))}
    </div>
  )
}

export default PreferenceList
