export function comboboxKeyAction(key, activeIndex, optionCount) {
  if (key === 'ArrowDown') {
    return { action: 'navigate', index: optionCount ? (activeIndex + 1) % optionCount : -1 }
  }
  if (key === 'ArrowUp') {
    return {
      action: 'navigate',
      index: optionCount ? (activeIndex <= 0 ? optionCount - 1 : activeIndex - 1) : -1,
    }
  }
  if ((key === 'Enter' || key === ' ') && activeIndex >= 0 && activeIndex < optionCount) {
    return { action: 'toggle', index: activeIndex }
  }
  if (key === 'Escape') return { action: 'close', index: -1 }
  if (key === 'Tab') return { action: 'tab', index: -1 }
  return null
}
