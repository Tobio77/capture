import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'

/**
 * Submenu sidebar yang dapat dilipat (S50).
 *
 * Diuji lewat peramban sungguhan, bukan unit test: yang dijaga di sini adalah
 * hal-hal yang hanya ada di peramban — atribut `inert` pada isi yang terlipat,
 * dan keadaan lipatan yang bertahan antar perpindahan halaman lewat
 * localStorage. AdminLayout dibuat ulang pada setiap navigasi (Inertia di
 * proyek ini tidak memakai persistent layout), sehingga "bertahan" itu sendiri
 * adalah perilaku yang mudah hilang tanpa disadari.
 */
test.describe('Sidebar: submenu yang dapat dilipat', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  const SUBMENU = '#submenu-Kelola-Absen'

  test('terlipat menyembunyikan isinya dari Tab, terbuka mengembalikannya', async ({ page }) => {
    // Halaman di LUAR kelompok: tidak ada yang memaksanya terbuka.
    await page.goto('/admin/laporan')

    const kepala = page.getByRole('button', { name: /Kelola Absen/ })

    await expect(kepala).toHaveAttribute('aria-expanded', 'false')

    /*
     * Isinya tetap ada di DOM supaya lipatannya dapat dianimasikan, jadi yang
     * diperiksa bukan keberadaannya melainkan `inert` — satu-satunya hal yang
     * benar-benar menahan fokus keyboard dan pembaca layar masuk ke sana.
     */
    await expect(page.locator(SUBMENU)).toHaveAttribute('inert', '')

    await kepala.click()

    await expect(kepala).toHaveAttribute('aria-expanded', 'true')
    await expect(page.locator(SUBMENU)).not.toHaveAttribute('inert', '')
    await expect(page.getByRole('link', { name: 'Daftar Event' })).toBeVisible()
  })

  test('kelompok yang memuat halaman berjalan terbuka sendiri', async ({ page }) => {
    // Sidebar yang tidak menunjukkan di mana penggunanya berada berhenti
    // menjadi navigasi.
    await page.goto('/admin/kelola-absen/rekap')

    await expect(page.getByRole('button', { name: /Kelola Absen/ })).toHaveAttribute(
      'aria-expanded',
      'true',
    )
    await expect(page.locator(SUBMENU)).not.toHaveAttribute('inert', '')
  })

  test('keadaan lipatan bertahan setelah berpindah halaman', async ({ page }) => {
    await page.goto('/admin/laporan')
    await page.getByRole('button', { name: /Kelola Absen/ }).click()
    await expect(page.locator(SUBMENU)).not.toHaveAttribute('inert', '')

    // Halaman lain yang juga di luar kelompok: tanpa penyimpanan, kelompoknya
    // akan menutup sendiri di sini.
    await page.goto('/admin/pegawai')

    await expect(page.getByRole('button', { name: /Kelola Absen/ })).toHaveAttribute(
      'aria-expanded',
      'true',
    )
  })
})
