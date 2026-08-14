// Runs inside the page. Returns the layout faults a screenshot shows but a PHP
// test never will: cells the stylesheet never reached, content clipped by a
// container that can't scroll, and a page that scrolls sideways.
export function auditPage() {
  const faults = []
  const vw = window.innerWidth

  const push = (kind, detail, sample) => faults.push({ kind, detail, sample })

  // 1. The whole page scrolling sideways is never intentional: the shell is
  //    supposed to confine overflow to the individual scroll containers.
  const docWidth = document.documentElement.scrollWidth
  if (docWidth > vw + 1) {
    push('page-overflow', `document scrolls ${docWidth - vw}px past the ${vw}px viewport`)
  }

  // 2. A table cell with no horizontal padding means the table stylesheet never
  //    applied — the exact failure that ships an unstyled dashboard.
  const cells = [...document.querySelectorAll('table td, table th')]
  const unstyled = cells.filter((el) => {
    const s = getComputedStyle(el)
    return parseFloat(s.paddingLeft) === 0 && parseFloat(s.paddingRight) === 0 && el.textContent.trim() !== ''
  })
  if (unstyled.length) {
    push('unstyled-cells', `${unstyled.length}/${cells.length} cells have no horizontal padding`, unstyled[0].outerHTML.slice(0, 160))
  }

  // 3. Header rows that kept the browser default (bold + centred) are a second
  //    symptom of the same thing, and survive even if padding is inherited.
  const heads = [...document.querySelectorAll('table thead th')]
  const centred = heads.filter((el) => {
    const s = getComputedStyle(el)
    return s.textAlign === 'center' && !el.className.includes('text-center')
  })
  if (centred.length) {
    push('default-th-alignment', `${centred.length} header cells are browser-default centred`, centred[0].outerHTML.slice(0, 160))
  }

  // 4. Content wider than a container that cannot scroll is content the user
  //    can never read.
  const clipped = []
  for (const el of document.querySelectorAll('main *')) {
    if (el.scrollWidth <= el.clientWidth + 1 || el.clientWidth === 0) continue
    const s = getComputedStyle(el)
    const scrollable = s.overflowX === 'auto' || s.overflowX === 'scroll'
    const truncating = s.textOverflow === 'ellipsis' || s.overflowX === 'hidden'
    if (!scrollable && !truncating) {
      clipped.push(el)
    }
  }
  if (clipped.length) {
    push('clipped-content', `${clipped.length} elements overflow a non-scrollable box`, clipped[0].outerHTML.slice(0, 160))
  }

  // 5. Anything painted past the right edge of the viewport by a parent that
  //    isn't itself a horizontal scroller.
  const escaping = [...document.querySelectorAll('main *')].filter((el) => {
    const r = el.getBoundingClientRect()
    if (r.width === 0 || r.right <= vw + 1) return false
    let p = el.parentElement
    while (p && p !== document.body) {
      const ox = getComputedStyle(p).overflowX
      if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') return false
      p = p.parentElement
    }
    return true
  })
  if (escaping.length) {
    push('escapes-viewport', `${escaping.length} elements paint past the right edge`, escaping[0].outerHTML.slice(0, 160))
  }

  // 6. A Livewire lazy card stuck on its skeleton means the screenshot below is
  //    of a loading state, not of the design.
  const placeholders = document.querySelectorAll('.animate-pulse')
  if (placeholders.length) {
    push('stuck-placeholder', `${placeholders.length} lazy cards never resolved`)
  }

  return faults
}
