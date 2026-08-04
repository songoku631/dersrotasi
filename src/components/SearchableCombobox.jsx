import { X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { comboboxKeyAction } from '../utils/comboboxNavigation'

function SearchableCombobox({
  id,
  label,
  values = [],
  onChange,
  loadOptions,
  placeholder = 'Seçenek ara',
}) {
  const listboxId = `${id}-listbox`
  const statusId = `${id}-status`
  const inputRef = useRef(null)
  const [inputValue, setInputValue] = useState('')
  const [options, setOptions] = useState([])
  const [activeIndex, setActiveIndex] = useState(-1)
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState(false)

  useEffect(() => {
    if (!open) return undefined
    const controller = new AbortController()
    const timer = setTimeout(() => {
      setLoading(true)
      setLoadError(false)
      Promise.resolve(loadOptions(inputValue, controller.signal))
        .then((items) => {
          if (controller.signal.aborted) return
          setOptions(items.slice(0, 20))
          setActiveIndex(-1)
        })
        .catch((error) => {
          if (error?.name === 'AbortError') return
          setOptions([])
          setActiveIndex(-1)
          setLoadError(true)
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false)
        })
    }, 180)

    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [inputValue, loadOptions, open])

  function toggleOption(option) {
    const selected = values.includes(option)
    onChange(selected ? values.filter((value) => value !== option) : [...values, option])
    setOpen(true)
  }

  function removeValue(value) {
    onChange(values.filter((item) => item !== value))
  }

  function clearAll() {
    onChange([])
    setInputValue('')
    setOpen(true)
    inputRef.current?.focus()
  }

  function handleKeyDown(event) {
    const result = comboboxKeyAction(event.key, activeIndex, options.length)
    if (!result) return
    if (result.action === 'tab') {
      setOpen(false)
      setInputValue('')
      return
    }

    event.preventDefault()
    if (result.action === 'navigate') {
      setOpen(true)
      setActiveIndex(result.index)
    } else if (result.action === 'toggle') {
      toggleOption(options[result.index])
    } else if (result.action === 'close') {
      setOpen(false)
      setInputValue('')
      setActiveIndex(-1)
    }
  }

  function handleBlur() {
    setOpen(false)
    setInputValue('')
    setActiveIndex(-1)
  }

  const status = loading
    ? 'Seçenekler yükleniyor.'
    : loadError
      ? 'Seçenekler yüklenemedi.'
      : open
        ? `${options.length} seçenek bulundu. ${values.length} seçenek işaretli.`
        : `${values.length} seçenek işaretli.`

  return (
    <div className="searchable-combobox searchable-combobox--multiple">
      <label htmlFor={id}><span>{label}</span></label>
      <div className="searchable-combobox__input-wrap">
        <input
          ref={inputRef}
          id={id}
          type="text"
          role="combobox"
          aria-autocomplete="list"
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-controls={listboxId}
          aria-activedescendant={activeIndex >= 0 ? `${id}-option-${activeIndex}` : undefined}
          aria-describedby={statusId}
          autoComplete="off"
          value={inputValue}
          placeholder={placeholder}
          onBlur={handleBlur}
          onChange={(event) => { setInputValue(event.target.value); setOpen(true) }}
          onFocus={() => setOpen(true)}
          onKeyDown={handleKeyDown}
        />
        {inputValue ? (
          <button
            className="searchable-combobox__clear"
            type="button"
            aria-label={`${label} aramasını temizle`}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => { setInputValue(''); inputRef.current?.focus() }}
          >
            <X aria-hidden="true" size={17} />
          </button>
        ) : null}
      </div>
      {values.length ? (
        <div className="searchable-combobox__selections" aria-label={`Seçili ${label.toLocaleLowerCase('tr-TR')} değerleri`}>
          {values.map((value) => (
            <span className="searchable-combobox__chip" key={value}>
              <span>{value}</span>
              <button
                type="button"
                aria-label={`${value} seçimini kaldır`}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => removeValue(value)}
              >
                <X aria-hidden="true" size={14} />
              </button>
            </span>
          ))}
          <button
            className="searchable-combobox__clear-all"
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={clearAll}
          >
            Tüm seçimleri temizle
          </button>
        </div>
      ) : null}
      {open ? (
        <ul
          id={listboxId}
          className="searchable-combobox__list"
          role="listbox"
          aria-label={`${label} seçenekleri`}
          aria-multiselectable="true"
        >
          {options.map((option, index) => {
            const selected = values.includes(option)
            const classes = [
              'searchable-combobox__option',
              index === activeIndex ? 'active' : '',
              selected ? 'selected' : '',
            ].filter(Boolean).join(' ')
            return (
              <li
                id={`${id}-option-${index}`}
                className={classes}
                key={option}
                role="option"
                aria-selected={selected}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                onClick={() => toggleOption(option)}
              >
                <input type="checkbox" checked={selected} readOnly tabIndex={-1} aria-hidden="true" />
                <span>{option}</span>
              </li>
            )
          })}
          {!loading && !loadError && options.length === 0 ? (
            <li className="searchable-combobox__empty" role="option" aria-disabled="true" aria-selected="false">Uygun seçenek bulunamadı.</li>
          ) : null}
        </ul>
      ) : null}
      <span id={statusId} className="visually-hidden" aria-live="polite">{status}</span>
    </div>
  )
}

export default SearchableCombobox
