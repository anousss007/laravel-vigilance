// Every page the dashboard serves, with the query string needed to reach a
// populated state. Detail pages use the deterministic ids the workbench seeder
// produces, so this list stays valid across re-seeds.
import { createHash } from 'node:crypto'

// Mirrors DatabaseSeeder::uuid() — the seeder derives ids from a counter so
// they can be addressed here by URL.
const seededUuid = (prefix, n) => {
  const hex = createHash('sha256').update(`${prefix}${n}`).digest('hex').slice(0, 32)
  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    `7${hex.slice(12, 15)}`,
    `8${hex.slice(16, 19)}`,
    hex.slice(20, 32),
  ].join('-')
}

/** Picks the first real entry of a page's leading <select> and waits for the
 *  Livewire round trip it triggers. */
const selectFirstOption = async (page) => {
  const select = page.locator('select').first()
  const value = await select.locator('option[value]:not([value=""])').first().getAttribute('value')

  if (value) {
    await select.selectOption(value)
    await page.waitForTimeout(1500)
  }
}

export const routes = [
  { name: 'overview', path: '/' },
  { name: 'runs', path: '/runs' },
  { name: 'run-detail', path: '/runs/1' },
  { name: 'pending', path: '/pending' },
  { name: 'issues', path: '/issues' },
  { name: 'issue-detail', path: '/issues/1' },
  { name: 'batches', path: '/batches' },
  { name: 'tags', path: '/tags' },
  { name: 'workload', path: '/workload' },
  { name: 'workers', path: '/workers' },
  { name: 'schedule', path: '/schedule' },
  { name: 'apm', path: '/apm' },
  { name: 'routes', path: '/routes' },
  { name: 'traces', path: '/traces' },
  { name: 'trace-detail', path: `/traces/${seededUuid('trace', 1)}` },
  { name: 'metrics', path: '/metrics' },
  { name: 'metric-detail', path: '/metrics/view?type=queue&scope=mail' },
  { name: 'custom-metrics', path: '/custom-metrics' },
  { name: 'incidents', path: '/incidents' },
  { name: 'releases', path: '/releases' },
  { name: 'logs', path: '/logs' },
  { name: 'vitals', path: '/vitals' },
  { name: 'slos', path: '/slos' },
  {
    name: 'dispatch',
    path: '/dispatch',
    // The page's whole point is the form it reflects from a job's constructor,
    // which only appears once a job is picked — so pick one.
    prepare: selectFirstOption,
  },
  { name: 'commands', path: '/commands', prepare: selectFirstOption },
  { name: 'usage', path: '/usage' },
]
