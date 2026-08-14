#!/usr/bin/env node
// Ad-hoc measuring tape: boots the workbench, opens one page and prints what
// the layout actually did. `node visual/probe.mjs /issues` — used while fixing
// a fault the sweep flagged, not part of the release gate.
import { spawn } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const port = Number(process.env.VISUAL_PORT ?? 8324)
const path = process.argv[2] ?? '/'
const width = Number(process.argv[3] ?? 1440)

const server = spawn('php', ['vendor/bin/testbench', 'serve', '--port', String(port)], {
  cwd: root,
  stdio: 'ignore',
  env: { ...process.env, APP_ENV: 'local', APP_DEBUG: 'true' },
})
process.on('exit', () => server.kill('SIGTERM'))

const base = `http://127.0.0.1:${port}/vigilance`
for (let i = 0; i < 150; i++) {
  try {
    const r = await fetch(base, { redirect: 'manual' })
    if (r.status < 500) break
  } catch {}
  await new Promise((r) => setTimeout(r, 400))
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width, height: 900 }, locale: 'fr-FR' })
await page.goto(base + path, { waitUntil: 'networkidle' })

console.log(
  JSON.stringify(
    await page.evaluate(() =>
      [...document.querySelectorAll('[data-slot="table-container"], table')].map((el) => ({
        tag: el.tagName,
        slot: el.dataset.slot ?? null,
        clientWidth: el.clientWidth,
        scrollWidth: el.scrollWidth,
        overflowX: getComputedStyle(el).overflowX,
        columns: el.tagName === 'TABLE' ? [...el.querySelectorAll('thead th')].map((th) => `${th.textContent.trim()}:${Math.round(th.getBoundingClientRect().width)}`) : null,
      })),
    ),
    null,
    2,
  ),
)

await browser.close()
server.kill('SIGTERM')
