export const labelSelector = 'h1,h2,h3,h4,h5,h6,label,legend,summary,dt'
const destinations = labelSelector + ',.ctl-label,.stat > span,[data-admin-search-title]'

export function normalize(value) {
  return value.toLowerCase().replaceAll('ё', 'е').replace(/[^\p{L}\p{N}]+/gu, ' ').trim()
}

export function matchingLabel(section, title) {
  const wanted = normalize(title)
  return [...section.querySelectorAll(destinations)].find(node => {
    if (!node.getClientRects().length) return false
    // Exclude the text of options, inputs and controls nested inside a label.
    const own = [...node.childNodes].filter(child => child.nodeType === 3).map(child => child.textContent).join(' ')
    const explicit = node.dataset.adminSearchTitle
    return normalize(own) === wanted
      || (explicit && !explicit.includes('|') && normalize(explicit) === wanted)
      || (explicit && normalize(node.textContent) === wanted)
  })
}

export function matchParts(title, terms) {
  const folded = title.toLowerCase().replaceAll('ё', 'е')
  const highlighted = Array(title.length).fill(false)
  const phrase = terms.join(' ')
  const matches = new Set([phrase, ...terms].filter(word => word.length > 1))
  for (const word of matches) {
    let start = folded.indexOf(word)
    while (start !== -1) {
      highlighted.fill(true, start, start + word.length)
      start = folded.indexOf(word, start + word.length)
    }
  }
  const parts = []
  for (let i = 0; i < title.length; i++) {
    if (parts.at(-1)?.match === highlighted[i]) parts.at(-1).text += title[i]
    else parts.push({ text: title[i], match: highlighted[i] })
  }
  return parts
}
