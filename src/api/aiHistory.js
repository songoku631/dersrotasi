export const AI_HISTORY_MAX_ITEMS = 10
export const AI_HISTORY_MAX_ITEM_LENGTH = 2000
export const AI_HISTORY_MAX_TOTAL_LENGTH = 8000

const truncationMarker = '\n… [önceki mesaj kısaltıldı] …\n'

function characters(value) {
  return Array.from(String(value))
}

export function aiHistoryContentLength(value) {
  return characters(value).length
}

export function truncateAiHistoryContent(
  value,
  maxLength = AI_HISTORY_MAX_ITEM_LENGTH,
) {
  const content = String(value)
  const contentCharacters = characters(content)
  if (contentCharacters.length <= maxLength) return content

  const markerCharacters = characters(truncationMarker)
  if (maxLength <= markerCharacters.length) {
    return contentCharacters.slice(0, maxLength).join('')
  }

  const availableLength = maxLength - markerCharacters.length
  const headLength = Math.ceil(availableLength / 2)
  const tailLength = availableLength - headLength

  return [
    ...contentCharacters.slice(0, headLength),
    ...markerCharacters,
    ...contentCharacters.slice(-tailLength),
  ].join('')
}

export function buildAiHistory(messages) {
  const history = []
  let totalLength = 0

  for (
    let index = messages.length - 1;
    index >= 0 && history.length < AI_HISTORY_MAX_ITEMS;
    index -= 1
  ) {
    const { role, content } = messages[index]
    const truncatedContent = truncateAiHistoryContent(content)
    const contentLength = aiHistoryContentLength(truncatedContent)

    if (totalLength + contentLength > AI_HISTORY_MAX_TOTAL_LENGTH) break

    history.unshift({ role, content: truncatedContent })
    totalLength += contentLength
  }

  return history
}
