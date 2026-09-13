import { test as setup } from '@playwright/test'
import { BERKAS_AUTH } from './auth-state.js'
import { ADMIN } from './data.js'
import { masuk } from './helpers.js'

/**
 * "Setup project" Playwright: masuk sekali per peran dan simpan storageState
 * di path {@link BERKAS_AUTH} — dipakai ulang oleh spec lain lewat
 * `test.use({ storageState: BERKAS_AUTH.x })`, supaya test yang TIDAK
 * menguji login sendiri tidak mengulang formulir masuk (dan CAPTCHA-nya) di
 * setiap test.
 *
 * `login.spec.js` sendiri TIDAK memakai storageState ini — ia menguji
 * formulirnya langsung, termasuk keadaan gagalnya.
 */
setup('masuk sebagai superadmin', async ({ page }) => {
  await masuk(page, ADMIN.superadmin)
  await page.context().storageState({ path: BERKAS_AUTH.superadmin })
})

setup('masuk sebagai admin dinas', async ({ page }) => {
  await masuk(page, ADMIN.adminDinas)
  await page.context().storageState({ path: BERKAS_AUTH.adminDinas })
})

setup('masuk sebagai admin upt', async ({ page }) => {
  await masuk(page, ADMIN.adminUpt)
  await page.context().storageState({ path: BERKAS_AUTH.adminUpt })
})
