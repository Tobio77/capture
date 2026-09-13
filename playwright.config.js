import { defineConfig, devices } from '@playwright/test'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const akarProyek = path.dirname(fileURLToPath(import.meta.url))

/**
 * Konfigurasi Playwright (E2E) — dua environment: lokal dan CI.
 *
 * Aplikasi dijalankan sungguhan lewat `php artisan serve`, terhadap basis
 * data SQLite KHUSUS E2E (`database/e2e.sqlite`), terpisah total dari
 * database pengembangan MySQL `capture` — pelajaran dari insiden pencemaran
 * data dev lewat tinker pada sesi Laporan Resmi: perintah yang mengulang
 * `migrate:fresh` tidak boleh pernah menyentuh database yang sungguhan
 * dipakai untuk kerja sehari-hari.
 *
 * Konfigurasinya (DB_*, SESSION_SECURE_COOKIE, dst.) hidup di `.env.e2e` di
 * akar proyek, BUKAN dikirim sebagai variabel lingkungan proses ke
 * `php artisan serve` — `Illuminate\Foundation\Console\ServeCommand` hanya
 * meneruskan segelintir variabel (`APP_ENV`, `PATH`, dan beberapa lainnya,
 * lihat `ServeCommand::$passthroughVariables`) ke proses server dalamnya;
 * variabel lain diam-diam DIABAIKAN dan server kembali membaca `.env`
 * sungguhan. Yang benar-benar diteruskan hanyalah `APP_ENV=e2e`, cukup
 * untuk membuat Laravel memuat `.env.e2e` di setiap proses — baik proses
 * `artisan` yang menyiapkan basis data maupun server yang melayani test.
 *
 * `globalSetup` menyiapkan basis data itu (lihat `tests/e2e/global-setup.js`)
 * sebelum `webServer` di bawah menyalakan server dan test mana pun berjalan.
 */
export const ALAMAT_DASAR = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000'
export const BERKAS_DB_E2E = path.join(akarProyek, 'database', 'e2e.sqlite')

/** Variabel lingkungan yang dipakai BERSAMA oleh setup basis data dan server. */
export const ENV_E2E = { APP_ENV: 'e2e' }

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.js',
  /*
   * TIDAK paralel, dan SATU worker — disengaja, bukan default yang lupa
   * diubah. Aplikasi ini punya pengaturan GLOBAL bersama satu baris
   * (Setting Absen, hari libur, dst.) yang beberapa journey ubah sebagai
   * bagian dari alurnya sendiri (mis. mematikan Verifikasi Wajah pada
   * `kiosk-dan-event.spec.js`). Dua test dari BERKAS BERBEDA yang kebetulan
   * berjalan bersamaan di worker terpisah akan salip-menyalip pada baris
   * pengaturan yang sama — server tunggal yang dipakai bersama membuat ini
   * lebih mudah terjadi di sini daripada pada test murni terisolasi.
   * Serialisasi satu worker menghindari kelas kegagalan ini seluruhnya;
   * jumlah test di suite ini kecil sehingga biayanya kecil.
   */
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'html',
  outputDir: 'tests/e2e/.hasil',

  use: {
    baseURL: ALAMAT_DASAR,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    /*
     * Layar absen kiosk selalu menyalakan pratinjau kamera (FR-TAP-05) —
     * bahkan ketika verifikasi wajah dimatikan, foto tetap diambil sebagai
     * bukti kehadiran. Tanpa kamera palsu ini, Chromium menolak izinnya dan
     * seluruh alur tap tidak pernah sampai ke layar hasil.
     */
    permissions: ['camera'],
    launchOptions: {
      args: [
        '--use-fake-device-for-media-stream',
        '--use-fake-ui-for-media-stream',
      ],
    },
  },

  projects: [
    {
      name: 'setup-auth',
      testMatch: /auth\.setup\.js/,
    },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['setup-auth'],
    },
  ],

  webServer: {
    command: 'php artisan serve --env=e2e --host=127.0.0.1 --port=8000',
    url: ALAMAT_DASAR,
    // Selalu proses baru, tidak pernah menumpang server lain yang kebetulan
    // menjawab di port ini (mis. Herd) — server semacam itu membaca `.env`
    // sungguhan, bukan `.env.e2e`, dan diam-diam mengarahkan seluruh test ke
    // database pengembangan.
    reuseExistingServer: false,
    timeout: 60_000,
    env: ENV_E2E,
  },
})
