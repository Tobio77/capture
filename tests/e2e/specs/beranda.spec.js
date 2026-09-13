import { test, expect } from '@playwright/test'

/**
 * Halaman depan (S30) — terbuka tanpa autentikasi apa pun, tiga pilihan:
 * Absen Umum, Absen Event, Masuk Admin. Perangkat yang belum diaktifkan
 * (konteks bersih, tanpa cookie device_token — kondisi bawaan tiap test
 * baru) diarahkan ke layar aktivasi dari KEDUA pilihan absen.
 */
test.describe('Halaman depan', () => {
  test('memilih Absen Umum pada perangkat baru mengarah ke aktivasi', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('button', { name: 'Absen Umum' }).click()
    await expect(page).toHaveURL(/\/kiosk\/aktivasi/)
  })

  test('memilih Absen Event pada perangkat baru mengarah ke aktivasi', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('button', { name: /Absen Event/ }).click()
    await expect(page).toHaveURL(/\/kiosk\/aktivasi/)
  })

  test('tautan Masuk Admin mengarah ke layar masuk', async ({ page }) => {
    await page.goto('/')
    await page.getByRole('link', { name: 'Masuk Admin' }).click()
    await expect(page).toHaveURL(/\/masuk/)
  })
})
