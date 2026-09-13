import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'
import { PEGAWAI_TANPA_WAJAH, PEGAWAI_UNIT_LAIN } from '../data.js'

test.describe('Kelola Pegawai', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('pencarian menyaring menurut nama', async ({ page }) => {
    await page.goto('/admin/pegawai')
    await expect(page.getByText(PEGAWAI_UNIT_LAIN.nama)).toBeVisible()

    await page.getByPlaceholder('Nama atau NIP…').fill(PEGAWAI_TANPA_WAJAH.nama)
    await page.getByPlaceholder('Nama atau NIP…').press('Enter')

    await expect(page.getByText(PEGAWAI_TANPA_WAJAH.nama)).toBeVisible()
    await expect(page.getByText(PEGAWAI_UNIT_LAIN.nama)).not.toBeVisible()
  })

  test('pencarian menyaring menurut NIP', async ({ page }) => {
    await page.goto('/admin/pegawai')
    await page.getByPlaceholder('Nama atau NIP…').fill(PEGAWAI_TANPA_WAJAH.nip)
    await page.getByPlaceholder('Nama atau NIP…').press('Enter')

    await expect(page.getByText(PEGAWAI_TANPA_WAJAH.nama)).toBeVisible()
    await expect(page.getByText(PEGAWAI_UNIT_LAIN.nama)).not.toBeVisible()
  })
})

test.describe('Kelola User/Role — cakupan peran', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('admin UPT ditolak mengakses Kelola User/Role', async ({ browser }) => {
    const context = await browser.newContext({ storageState: BERKAS_AUTH.adminUpt })
    const page = await context.newPage()

    const jawaban = await page.goto('/admin/pengguna')
    expect(jawaban?.status()).toBe(403)

    await context.close()
  })

  test('superadmin dapat membuka Kelola User/Role', async ({ page }) => {
    await page.goto('/admin/pengguna')
    await expect(page).toHaveURL(/\/admin\/pengguna/)
    await expect(page.locator('body')).not.toContainText('Anda tidak memiliki akses')
  })
})
