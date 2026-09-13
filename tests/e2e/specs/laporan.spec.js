import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'

/**
 * Laporan Kehadiran — "Unduh Data" (CSV/Excel/PDF) dan "Generate Laporan"
 * (Word/Excel/PDF). Basis data E2E tidak punya data kehadiran sungguhan
 * (tidak ada event/tap yang di-seed — lihat `tests/e2e/global-setup.js`),
 * sehingga yang diuji di sini adalah MEKANISME unduhannya (format, nama
 * berkas, bahwa berkasnya benar-benar sampai) — bukan isi datanya, yang
 * sudah dicakup unit test `LaporanResmiServiceTest` dan `LaporanTest` di
 * sisi PHP.
 *
 * Dua tombol berlabel sama persis "Excel" ada di halaman ini — satu untuk
 * "Unduh Data", satu untuk "Generate Laporan" — dibedakan lewat urutan DOM
 * (`.first()`/`.last()`), bukan mengeklik keduanya sekaligus.
 */
test.describe('Laporan Kehadiran', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/laporan')
  })

  test('unduh data sebagai CSV', async ({ page }) => {
    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'CSV' }).click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.csv$/)
  })

  test('unduh data sebagai Excel', async ({ page }) => {
    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Excel' }).first().click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.xlsx$/)
  })

  // Tidak ada test "PDF (Unduh Data)" — tombol "Cetak" di grup itu memanggil
  // `window.print()` peramban (pengguna men-"Save as PDF" sendiri dari sana),
  // BUKAN mengunduh berkas dari endpoint `ekspor?format=pdf`. PDF sebagai
  // unduhan berkas sungguhan hanya ada di grup "Generate Laporan" di bawah.

  test('generate laporan resmi sebagai Word', async ({ page }) => {
    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Word' }).click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.docx$/)
  })

  test('generate laporan resmi sebagai Excel', async ({ page }) => {
    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Excel' }).last().click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.xlsx$/)
  })

  test('generate laporan resmi sebagai PDF', async ({ page }) => {
    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'PDF' }).click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.pdf$/)
  })
})
