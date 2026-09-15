import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'

/**
 * Setting Absen — jadwal jam dan ambang batas Laporan (FR-SET-01 s.d.
 * FR-SET-08 pada bagian yang relevan). Tersimpan berarti mengecek nilainya
 * BERTAHAN setelah halaman dimuat ulang, bukan cuma pesan sukses sesaat.
 */
test.describe('Setting Absen', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('jam masuk per hari dan ambang batas laporan tersimpan', async ({ page }) => {
    await page.goto('/admin/kelola-absen/setting')

    // Jam masuk kini per hari (Senin–Minggu), bukan satu angka untuk
    // seluruh pekan (S41) — Rabu diisi berbeda dari Senin untuk memastikan
    // baris yang benar-benar tersimpan, bukan cuma baris pertama.
    await page.getByLabel('Jam masuk Senin').fill('08:15')
    await page.getByLabel('Jam masuk Rabu').fill('06:45')
    await page.locator('#ambang-kehadiran').fill('72')
    await page.locator('#ambang-keterlambatan').fill('20')

    await page.getByRole('button', { name: 'Simpan Setting' }).click()
    await expect(page.getByText('Setting Absen tersimpan.')).toBeVisible()

    await page.reload()
    await expect(page.getByLabel('Jam masuk Senin')).toHaveValue('08:15')
    await expect(page.getByLabel('Jam masuk Rabu')).toHaveValue('06:45')
    await expect(page.locator('#ambang-kehadiran')).toHaveValue('72')
    await expect(page.locator('#ambang-keterlambatan')).toHaveValue('20')
  })

  test('ambang batas laporan menolak nilai di luar rentang', async ({ page }) => {
    await page.goto('/admin/kelola-absen/setting')

    // Slider HTML sendiri sudah menjepit nilai ke min/max-nya (`min`/`max`
    // pada elemen) — mengetik ekstrem lewat `.fill()` pada input range
    // otomatis dijepit oleh peramban, bukan ditolak server. Yang diuji di
    // sini karena itu adalah bahwa batasnya sungguhan terpasang di DOM
    // (FR-SET server-side sudah dicakup `SettingAbsenTest` PHP).
    const min = await page.locator('#ambang-kehadiran').getAttribute('min')
    const maks = await page.locator('#ambang-kehadiran').getAttribute('max')

    expect(Number(min)).toBeGreaterThanOrEqual(50)
    expect(Number(maks)).toBeLessThanOrEqual(100)
  })
})
