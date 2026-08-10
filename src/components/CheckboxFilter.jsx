import { ChevronDown, Search, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'

function CheckboxFilter({
  id,
  label,
  options = [],
  values = [],
  onChange,
  formatOption = (value) => value,
  searchable = false,
}) {
  const panelId = `${id}-panel`
  const statusId = `${id}-status`
  const rootRef = useRef(null)
  const triggerRef = useRef(null)
  const searchRef = useRef(null)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')

  useEffect(() => {
    if (!open) return undefined

    function closeOnOutsidePointer(event) {
      if (!rootRef.current?.contains(event.target)) {
        setOpen(false)
        setQuery('')
      }
    }

    document.addEventListener('pointerdown', closeOnOutsidePointer)
    return () => document.removeEventListener('pointerdown', closeOnOutsidePointer)
  }, [open])

  useEffect(() => {
    if (open && searchable) {
      searchRef.current?.focus()
    }
  }, [open, searchable])

  const visibleOptions = useMemo(() => {
    const normalizedQuery = query.trim().toLocaleLowerCase('tr-TR')
    if (!normalizedQuery) return options
    return options.filter((option) => (
      `${option} ${formatOption(option)}`.toLocaleLowerCase('tr-TR').includes(normalizedQuery)
    ))
  }, [formatOption, options, query])

  function toggleValue(value) {
    onChange(values.includes(value)
      ? values.filter((selected) => selected !== value)
      : [...values, value])
  }

  function clearValues() {
    onChange([])
    setQuery('')
  }

  function closePanel({ restoreFocus = false } = {}) {
    setOpen(false)
    setQuery('')
    if (restoreFocus) triggerRef.current?.focus()
  }

  function handleKeyDown(event) {
    if (event.key === 'Escape' && open) {
      event.preventDefault()
      closePanel({ restoreFocus: true })
    }
  }

  function handleBlur(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
      closePanel()
    }
  }

  const selectedSummary = values.length === 0
    ? 'Tümü'
    : values.length === 1
      ? formatOption(values[0])
      : `${values.length} seçim`
  const visibleChips = values.slice(0, 4)

  return (
    <div
      ref={rootRef}
      className={`checkbox-filter${open ? ' checkbox-filter--open' : ''}`}
      onBlur={handleBlur}
      onKeyDown={handleKeyDown}
    >
      <span className="checkbox-filter__label" id={`${id}-label`}>
        {label}{values.length ? ` (${values.length})` : ''}
      </span>
      <button
        ref={triggerRef}
        id={id}
        className="checkbox-filter__trigger"
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        aria-describedby={statusId}
        aria-haspopup="true"
        aria-labelledby={`${id}-label ${id}-summary`}
        onClick={() => setOpen((current) => !current)}
      >
        <span id={`${id}-summary`}>{selectedSummary}</span>
        <ChevronDown aria-hidden="true" size={18} />
      </button>

      {values.length ? (
        <div className="checkbox-filter__chips" aria-label={`Seçili ${label.toLocaleLowerCase('tr-TR')} değerleri`}>
          {visibleChips.map((value) => (
            <span className="checkbox-filter__chip" key={value}>
              <span>{formatOption(value)}</span>
              <button
                type="button"
                aria-label={`${formatOption(value)} seçimini kaldır`}
                onClick={() => toggleValue(value)}
              >
                <X aria-hidden="true" size={13} />
              </button>
            </span>
          ))}
          {values.length > visibleChips.length ? (
            <span className="checkbox-filter__more">+{values.length - visibleChips.length} diğer</span>
          ) : null}
          <button className="checkbox-filter__chips-clear" type="button" onClick={clearValues}>
            Temizle
          </button>
        </div>
      ) : null}

      {open ? (
        <div
          id={panelId}
          className="checkbox-filter__panel"
          role="group"
          aria-labelledby={`${id}-label`}
        >
          <div className="checkbox-filter__panel-header">
            <strong>{values.length ? `${values.length} seçili` : 'Tümü seçili'}</strong>
            {values.length ? (
              <button type="button" onClick={clearValues}>Temizle</button>
            ) : null}
          </div>
          {searchable ? (
            <div className="checkbox-filter__search">
              <Search aria-hidden="true" size={16} />
              <input
                ref={searchRef}
                type="search"
                value={query}
                aria-label={`${label} seçeneklerinde ara`}
                placeholder={`${label} ara`}
                onChange={(event) => setQuery(event.target.value)}
              />
            </div>
          ) : null}
          <div className="checkbox-filter__options">
            {visibleOptions.map((option) => (
              <label className="checkbox-filter__option" key={option}>
                <input
                  type="checkbox"
                  checked={values.includes(option)}
                  onChange={() => toggleValue(option)}
                />
                <span>{formatOption(option)}</span>
              </label>
            ))}
            {visibleOptions.length === 0 ? (
              <p className="checkbox-filter__empty">Uygun seçenek bulunamadı.</p>
            ) : null}
          </div>
        </div>
      ) : null}
      <span id={statusId} className="visually-hidden" aria-live="polite">
        {values.length ? `${values.length} seçenek işaretli.` : 'Tüm seçenekler gösteriliyor.'}
      </span>
    </div>
  )
}

export default CheckboxFilter
