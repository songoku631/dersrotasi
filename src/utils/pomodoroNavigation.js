const mobileRoomQuery = '(max-width: 767px)'

export function roomPath(roomId) {
  return `/pomodoro/room/${roomId}`
}

export function prepareRoomNavigation(roomId, windowObject = window) {
  const path = roomPath(roomId)
  if (windowObject.matchMedia?.(mobileRoomQuery).matches) return { path, tab: null }

  const tab = windowObject.open('about:blank', '_blank')
  if (!tab) return { path, tab: null }
  tab.opener = null
  return { path, tab }
}

export function finishRoomNavigation(destination, navigate, windowObject = window) {
  if (destination.tab) {
    destination.tab.location.replace(new URL(destination.path, windowObject.location.origin).href)
    return
  }
  navigate(destination.path)
}

export function cancelRoomNavigation(destination) {
  destination.tab?.close()
}
