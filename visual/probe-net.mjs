#!/usr/bin/env node
// Opens one page and prints every failing network call with its response body —
// used to chase Livewire update failures the screenshots only hint at.
import { spawn } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const port = Number(process.env.VISUAL_PORT ?? 8325)
const path = process.argv[2] ?? '/apm'

const server = spawn('php', ['vendor/bin/testbench', 'serve', '--port', String(port)], {
  cwd: root,
  stdio: 'ignore',
  env: { ...process.env, APP_ENV: 'local', APP_DEBUG: 'true' },
})
process.on('exit', () => server.kill('SIGTERM'))

const base = `http://127.0.0.1:${port}/vigilance`
for (let i = 0; i < 150; i++) {
  try {
    if ((await fetch(base, { redirect: 'manual' })).status < 500) break
  } catch {}
  await new Promise((r) => setTimeout(r, 400))
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: Number(process.argv[3] ?? 1440), height: 844 } })

page.on('response', async (res) => {
  if (res.status() < 400) return
  let body = ''
  try {
    body = (await res.text()).slice(0, 1200)
  } catch {}
  console.log(`\n${res.status()} ${res.url()}\n${body}\n`)
})
page.on('console', (m) => m.type() === 'error' && console.log('console:', m.text().slice(0, 300)))

await page.goto(base + path, { waitUntil: 'networkidle' })
await page.waitForTimeout(8000)
console.log('placeholders left:', await page.evaluate(() => document.querySelectorAll('.animate-pulse').length))

await browser.close()
server.kill('SIGTERM')
