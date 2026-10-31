// Builds the website for the Laravel (Hostinger) setup and copies it into laravel/public.
//   npm run build:laravel
// The website then calls the API on the same domain (/api) and shows shop links as /s/<shop>.
import { execSync } from 'node:child_process'
import { cpSync, existsSync, readdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'

const root = new URL('..', import.meta.url).pathname
const out = join(root, 'dist-laravel')
const pub = join(root, 'laravel', 'public')

execSync(`npx vite build --outDir ${out} --emptyOutDir`, {
  cwd: root,
  stdio: 'inherit',
  env: { ...process.env, VITE_API_URL: '/api', VITE_STORE_LINKS: 'path', VITE_ROOT_DOMAIN: process.env.VITE_ROOT_DOMAIN || 'toplinehub.co.ke' },
})

// Remove the previous build's files (Laravel's own index.php, .htaccess, robots.txt stay).
const keep = new Set(['index.php', '.htaccess', 'robots.txt'])
rmSync(join(pub, 'assets'), { recursive: true, force: true })
if (existsSync(join(pub, 'index.html'))) rmSync(join(pub, 'index.html'))

for (const name of readdirSync(out)) {
  if (keep.has(name)) continue // Laravel's .htaccess routes /api to PHP; the website's own one must not replace it
  cpSync(join(out, name), join(pub, name), { recursive: true })
}
rmSync(out, { recursive: true, force: true })
console.log(`\nWebsite copied into ${pub}`)
