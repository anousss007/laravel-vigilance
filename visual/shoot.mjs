#!/usr/bin/env node
// Boots the workbench app, walks every dashboard page in both themes at desktop
// and phone width, screenshots each one and reports the layout faults found.
//
//   npm run visual            # shoot + audit, writes visual/output/
//   npm run visual -- --open  # …and leave the contact sheet path printed
//
// This is the release gate: a page nobody has looked at is a page that ships
// broken, and the audit only catches faults it knows about — the screenshots
// are there to be read.
import { spawn } from 'node:child_process'
import { mkdir, readFile, rm, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'
import { auditPage } from './audit.mjs'
import { routes } from './routes.mjs'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const outDir = resolve(root, 'visual/output')
const port = Number(process.env.VISUAL_PORT ?? 8321)
const base = `http://127.0.0.1:${port}/vigilance`

const viewports = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'phone', width: 390, height: 844 },
]
const themes = ['dark', 'light']

async function waitForServer(url, timeoutMs = 60000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    try {
      const res = await fetch(url, { redirect: 'manual' })
      if (res.status < 500) return
    } catch {}
    await new Promise((r) => setTimeout(r, 400))
  }
  throw new Error(`Workbench server never came up on ${url}`)
}

// `testbench serve` boots the skeleton app, which has no .env of its own — so
// the database is handed over as process env (Laravel leaves real env vars
// alone) and by absolute path, since the served app's cwd is not this one.
const server = spawn(
  'php',
  ['vendor/bin/testbench', 'serve', '--port', String(port)],
  {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
    env: {
      ...process.env,
      APP_ENV: 'local',
      APP_DEBUG: 'true',
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: resolve(root, 'workbench/database/database.sqlite'),
    },
  },
)

let serverLog = ''
server.stdout.on('data', (d) => (serverLog += d))
server.stderr.on('data', (d) => (serverLog += d))

const shutdown = () => {
  if (!server.killed) server.kill('SIGTERM')
}
process.on('exit', shutdown)
process.on('SIGINT', () => {
  shutdown()
  process.exit(130)
})

let failed = 0
const report = []
const axeSource = await readFile(resolve(root, 'node_modules/axe-core/axe.min.js'), 'utf8')

await rm(outDir, { recursive: true, force: true })
await mkdir(outDir, { recursive: true })

try {
  await waitForServer(base)

  const browser = await chromium.launch()

  for (const viewport of viewports) {
    for (const theme of themes) {
      const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        deviceScaleFactor: 2,
        locale: 'fr-FR',
        timezoneId: 'Africa/Casablanca',
        reducedMotion: 'reduce',
      })

      // The layout reads this before first paint, so seeding it here avoids
      // photographing a theme flash.
      await context.addInitScript((t) => {
        try {
          localStorage.setItem('vigilance-theme', t)
        } catch {}
      }, theme)

      const page = await context.newPage()
      const consoleErrors = []
      const badResponses = []
      page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text()))
      page.on('pageerror', (e) => consoleErrors.push(String(e)))
      page.on('response', (r) => {
        if (r.status() >= 400) badResponses.push(`HTTP ${r.status()} ${r.url()}`)
      })

      for (const route of routes) {
        const id = `${route.name}--${viewport.name}--${theme}`
        consoleErrors.length = 0
        badResponses.length = 0

        let status = 0
        try {
          const res = await page.goto(base + route.path, { waitUntil: 'networkidle', timeout: 45000 })
          status = res?.status() ?? 0
        } catch (e) {
          report.push({ id, name: route.name, path: route.path, viewport: viewport.name, theme, status, faults: [{ kind: 'navigation', detail: String(e).split('\n')[0] }] })
          failed++
          continue
        }

        // Some pages only reveal their real content after an interaction.
        if (route.prepare) {
          await route.prepare(page).catch((e) => consoleErrors.push(`prepare: ${e}`))
        }

        // Livewire loads a lazy card when its placeholder reaches the viewport,
        // so a full-page screenshot taken without scrolling photographs
        // skeletons for everything below the fold. Walk the page down and back.
        await page.evaluate(async () => {
          const step = window.innerHeight * 0.8
          for (let y = 0; y < document.body.scrollHeight; y += step) {
            window.scrollTo(0, y)
            await new Promise((r) => setTimeout(r, 120))
          }
          window.scrollTo(0, 0)
        })

        await page
          .waitForFunction(() => document.querySelectorAll('.animate-pulse').length === 0, null, { timeout: 15000 })
          .catch(() => {})

        const faults = await page.evaluate(auditPage)

        // WCAG 2.1 AA, on the same shot: the release checklist wants every page
        // audited at both viewports, and the page is already open and settled.
        await page.addScriptTag({ content: axeSource })
        const violations = await page.evaluate(async () => {
          const { violations } = await window.axe.run(document, {
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
          })
          return violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, target: v.nodes[0]?.target?.join(' ') ?? '' }))
        })

        for (const v of violations) {
          faults.push({ kind: `a11y:${v.id}`, detail: `${v.impact} · ${v.nodes} node(s) · ${v.target}`.slice(0, 200) })
        }

        if (status >= 400) faults.unshift({ kind: 'http', detail: `HTTP ${status}` })
        if (consoleErrors.length) faults.push({ kind: 'console', detail: consoleErrors[0].slice(0, 200) })
        if (badResponses.length) faults.push({ kind: 'request-failed', detail: `${badResponses.length}× — ${badResponses[0]}` })

        const file = `${id}.png`
        await page.screenshot({ path: resolve(outDir, file), fullPage: true })

        if (faults.length) failed++
        report.push({ id, file, name: route.name, path: route.path, viewport: viewport.name, theme, status, faults })
        process.stdout.write(faults.length ? `✗ ${id} — ${faults.map((f) => f.kind).join(', ')}\n` : `✓ ${id}\n`)
      }

      await context.close()
    }
  }

  await browser.close()
} catch (e) {
  console.error(String(e))
  console.error(serverLog.slice(-4000))
  process.exitCode = 1
} finally {
  shutdown()
}

await writeFile(resolve(outDir, 'report.json'), JSON.stringify(report, null, 2))
await writeFile(resolve(outDir, 'index.html'), contactSheet(report))

const faulty = report.filter((r) => r.faults.length)
console.log(`\n${report.length} shots, ${faulty.length} with faults → visual/output/index.html`)
if (faulty.length) process.exitCode = 1

function contactSheet(rows) {
  const card = (r) => `
    <figure class="${r.faults.length ? 'bad' : 'ok'}">
      <figcaption>
        <strong>${r.name}</strong> <span>${r.viewport} · ${r.theme}</span>
        ${r.faults.map((f) => `<em>${f.kind}: ${escapeHtml(f.detail ?? '')}</em>`).join('')}
      </figcaption>
      ${r.file ? `<a href="${r.file}"><img loading="lazy" src="${r.file}" alt="${r.id}"></a>` : ''}
    </figure>`

  return `<!doctype html><meta charset="utf-8"><title>Vigilance — visual sweep</title>
<style>
  body{font:14px/1.5 system-ui;margin:0;padding:24px;background:#0b0b0e;color:#e6e6ea}
  h1{font-size:18px;margin:0 0 16px}
  .grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(340px,1fr))}
  figure{margin:0;border:1px solid #26262e;border-radius:10px;overflow:hidden;background:#131318}
  figure.bad{border-color:#b4213b}
  figcaption{padding:10px 12px;display:flex;flex-direction:column;gap:2px}
  figcaption span{color:#8b8b96;font-size:12px}
  figcaption em{color:#ff8098;font-size:12px;font-style:normal}
  img{display:block;width:100%;border-top:1px solid #26262e}
</style>
<h1>Vigilance — ${rows.length} shots, ${rows.filter((r) => r.faults.length).length} with faults</h1>
<div class="grid">${rows.map(card).join('')}</div>`
}

function escapeHtml(s) {
  return String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c])
}
