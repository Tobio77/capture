import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { BERKAS_DB_E2E, ENV_E2E } from '../../playwright.config.js'

const akarProyek = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * Dijalankan SEKALI sebelum seluruh suite E2E, dan sebelum `webServer` di
 * `playwright.config.js` menyalakan `php artisan serve`.
 *
 * Menyegarkan basis data SQLite `database/e2e.sqlite` dan mengisinya dengan
 * data contoh yang sama dipakai pengembangan lokal (`UnitKerjaSeeder`,
 * `UserSeeder`, `PegawaiSeeder` — lihat `tests/e2e/data.js` untuk daftar
 * kredensial dan NIP yang hasilnya dipakai test). Perangkat kiosk dan event
 * kegiatan SENGAJA tidak ikut di-seed di sini: test sendiri yang
 * mendaftarkan dan mengaktifkannya lewat UI, supaya jalur yang teruji betul
 * jalur yang dipakai admin sungguhan, bukan jalan pintas fixture.
 *
 * Berkasnya DIHAPUS lebih dulu, bukan sekadar di-`migrate:fresh`: yang
 * terakhir hanya men-DROP tabel yang DIKENALI skema saat ini, sehingga baris
 * cache/session dari percobaan sebelumnya (mis. penguncian login yang sempat
 * tercatat selagi menjalankan test berulang kali secara manual) bisa lolos
 * kalau baris itu tidak sempat ter-drop dengan bersih. Berkas baru berarti
 * tidak ada apa pun untuk lolos.
 *
 * `App\Console\Commands\SiapkanE2ECommand` menolak berjalan kecuali
 * `DB_DATABASE` memuat "e2e" — pagar kedua di sisi PHP, supaya kesalahan
 * konfigurasi di sini tidak berakhir menghapus database pengembangan.
 */
export default function globalSetup() {
  for (const akhiran of ['', '-wal', '-shm', '-journal']) {
    fs.rmSync(BERKAS_DB_E2E + akhiran, { force: true })
  }
  fs.mkdirSync(path.dirname(BERKAS_DB_E2E), { recursive: true })
  fs.writeFileSync(BERKAS_DB_E2E, '')

  execFileSync('php', ['artisan', 'e2e:siapkan', '--env=e2e'], {
    cwd: akarProyek,
    env: { ...process.env, ...ENV_E2E },
    stdio: 'inherit',
  })
}
