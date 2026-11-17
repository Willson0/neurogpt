import MarkdownIt from 'markdown-it'

/**
 * Markdown of AI answers: CommonMark + tables + strikethrough, LaTeX ($…$, $$…$$, \(…\), \[…\]) through KaTeX,
 * highlighted code blocks with a copy button. Raw HTML in the answer is NOT rendered (html: false), so a model
 * cannot inject markup or scripts into the page.
 */
const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))

const MATH = /\$|\\\(|\\\[/
const CODE = /```|~~~|^(?: {4}|\t)\S/m

/**
 * A renderer for this text. KaTeX (with its fonts) and highlight.js are big, so they are loaded only when the text
 * has formulas or code at all.
 */
export async function createRenderer(text = '') {
  const [math, hljs] = await Promise.all([
    MATH.test(text) ? Promise.all([import('katex'), import('markdown-it-texmath'), import('katex/dist/katex.min.css')]) : null,
    CODE.test(text) ? import('./highlight').then((m) => m.default) : null,
  ])
  const md = new MarkdownIt({ html: false, linkify: true, breaks: true, typographer: false })
  if (math) {
    md.use(math[1].default, {
      engine: math[0].default,
      delimiters: ['dollars', 'brackets'],
      katexOptions: { throwOnError: false, strict: 'ignore', output: 'htmlAndMathml', trust: false },
    })
  }

  // Code blocks: a header with the language and a copy button. The code itself is kept in `codes` by index,
  // the button only carries the index.
  const codes = []
  md.renderer.rules.fence = (tokens, idx) => {
    const t = tokens[idx]
    const lang = (t.info || '').trim().split(/\s+/)[0].toLowerCase()
    const code = t.content.replace(/\n$/, '')
    const n = codes.push(code) - 1
    let html
    try {
      if (!hljs) throw new Error('no highlighter')
      html = lang && hljs.getLanguage(lang) ? hljs.highlight(code, { language: lang, ignoreIllegals: true }).value : hljs.highlightAuto(code).value
    } catch {
      html = esc(code)
    }
    return `<figure class="ans-code"><figcaption><span>${esc(lang || 'код')}</span>`
      + `<button type="button" class="ans-copy" data-copy="${n}">Копировать</button></figcaption>`
      + `<pre><code class="hljs">${html}</code></pre></figure>`
  }
  md.renderer.rules.code_block = md.renderer.rules.fence

  // Wide tables scroll sideways instead of stretching the page.
  md.renderer.rules.table_open = () => '<div class="ans-table"><table>'
  md.renderer.rules.table_close = () => '</table></div>'

  // Links open outside the page.
  const linkOpen = md.renderer.rules.link_open || ((tokens, idx, options, env, self) => self.renderToken(tokens, idx, options))
  md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
    tokens[idx].attrSet('target', '_blank')
    tokens[idx].attrSet('rel', 'noopener noreferrer')
    return linkOpen(tokens, idx, options, env, self)
  }

  return {
    render(text) {
      codes.length = 0
      return md.render(text || '')
    },
    code: (n) => codes[n] ?? '',
  }
}
