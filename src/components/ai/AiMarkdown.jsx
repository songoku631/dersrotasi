function InlineMarkdown({ text }) {
  return String(text).split(/(\*\*[^*]+\*\*)/g).map((part, index) => {
    if (part.startsWith('**') && part.endsWith('**')) {
      return <strong key={`${index}-${part}`}>{part.slice(2, -2)}</strong>
    }
    return part
  })
}

function AiMarkdown({ content }) {
  const blocks = []
  let list = null

  function flushList() {
    if (!list) return
    const ListTag = list.type
    blocks.push(
      <ListTag key={`list-${blocks.length}`}>
        {list.items.map((item, index) => (
          <li key={`${index}-${item}`}><InlineMarkdown text={item} /></li>
        ))}
      </ListTag>,
    )
    list = null
  }

  String(content).replace(/\r\n?/g, '\n').split('\n').forEach((line) => {
    const unordered = line.match(/^\s*[-*]\s+(.+)$/)
    const ordered = line.match(/^\s*\d+[.)]\s+(.+)$/)
    const match = unordered || ordered

    if (match) {
      const type = ordered ? 'ol' : 'ul'
      if (list && list.type !== type) flushList()
      list ||= { type, items: [] }
      list.items.push(match[1])
      return
    }

    flushList()
    if (line.trim() === '') {
      blocks.push(<span aria-hidden="true" className="ai-message-markdown__break" key={`break-${blocks.length}`} />)
      return
    }
    blocks.push(
      <p key={`paragraph-${blocks.length}`}>
        <InlineMarkdown text={line} />
      </p>,
    )
  })
  flushList()

  return <div className="ai-message-markdown">{blocks}</div>
}

export default AiMarkdown
