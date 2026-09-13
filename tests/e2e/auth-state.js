/**
 * Path storageState per peran, ditulis oleh `auth.setup.js` dan dibaca oleh
 * spec lain lewat `test.use({ storageState: BERKAS_AUTH.x })`.
 *
 * Sengaja berkas TERPISAH dari `auth.setup.js`: Playwright menolak spec
 * biasa yang meng-`import` langsung dari sebuah berkas yang cocok pola
 * `testMatch` (di sini `auth.setup.js`), sebab berkas begitu dianggap
 * "berkas test", bukan modul biasa.
 */
export const BERKAS_AUTH = {
  superadmin: 'tests/e2e/.auth/superadmin.json',
  adminDinas: 'tests/e2e/.auth/admin-dinas.json',
  adminUpt: 'tests/e2e/.auth/admin-upt.json',
}
