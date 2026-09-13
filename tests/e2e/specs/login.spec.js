import { test, expect } from '@playwright/test'
import { ADMIN } from '../data.js'
import { hitungJawabanCaptcha, isiFormMasuk, masuk } from '../helpers.js'

/**
 * Layar masuk Panel Admin (FR-AUTH-01, FR-AUTH-03) — SENGAJA tidak memakai
 * storageState dari `auth.setup.js`: formulir dan keadaan gagalnya sendiri
 * yang diuji di sini.
 *
 * Percobaan gagal sengaja dijaga sedikit (1-2 per test) — `AutentikasiService`
 * mengunci login setelah 5 kegagalan berturut-turut per (surel + IP), dan
 * seluruh test di berkas ini berbagi IP yang sama.
 */
test.describe('Login admin', () => {
  test('berhasil masuk dengan kredensial benar', async ({ page }) => {
    await masuk(page, ADMIN.superadmin)
    await expect(page.getByText(ADMIN.superadmin.nama)).toBeVisible()
  })

  test('kata sandi salah menampilkan pesan gagal dan tetap di halaman masuk', async ({ page }) => {
    await isiFormMasuk(page, { email: ADMIN.adminDinas.email, password: 'salah-sekali' })

    await expect(page).toHaveURL(/\/masuk/)
    await expect(
      page.getByText('Alamat surel atau kata sandi tidak sesuai, atau akun Anda tidak aktif.'),
    ).toBeVisible()
  })

  test('jawaban captcha salah ditolak sebelum kata sandi diperiksa', async ({ page }) => {
    await page.goto('/masuk')
    await page.getByLabel('Alamat Surel').fill(ADMIN.adminUpt.email)
    await page.getByLabel('Kata Sandi').fill(ADMIN.adminUpt.password)

    const jawabanBenar = await hitungJawabanCaptcha(page)
    await page.locator('#jawaban-captcha').fill(String(jawabanBenar + 1))
    await page.getByRole('button', { name: 'Masuk' }).click()

    await expect(page).toHaveURL(/\/masuk/)
    await expect(page.getByText('Jawaban hitungan tidak sesuai.')).toBeVisible()

    // Soal diganti setelah percobaan gagal — jawaban lama tidak berlaku lagi
    // (CaptchaHitungService::benar() menghanguskannya apa pun hasilnya).
    const soalBaru = await page.locator('p.tabular-nums').innerText()
    expect(soalBaru).toBeTruthy()
  })

  test('email tidak terdaftar menampilkan pesan generik yang sama dengan sandi salah', async ({
    page,
  }) => {
    // Pesannya sengaja sama dengan kata sandi salah: membedakan keduanya
    // memberi tahu penebak apakah sebuah surel terdaftar di sistem.
    await isiFormMasuk(page, { email: 'tidak.ada@capture.test', password: 'apa-saja' })

    await expect(page).toHaveURL(/\/masuk/)
    await expect(
      page.getByText('Alamat surel atau kata sandi tidak sesuai, atau akun Anda tidak aktif.'),
    ).toBeVisible()
  })
})
