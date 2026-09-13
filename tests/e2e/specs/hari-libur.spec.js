import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'
import { UNIT_KERJA } from '../data.js'
import { pilihDariDropdown, pilihTanggalHariIni } from '../helpers.js'

/**
 * Hari Libur (S39) — tambah/hapus, dan banner Absen Umum yang menyebut
 * kalender sebagai alasan tertutup. Dipakai tanggal HARI INI supaya efeknya
 * langsung terlihat di halaman Absen Umum tanpa memilih tanggal lain.
 *
 * Override manual TETAP MENANG di atas kalender (aturan resolusi S39,
 * teruji di sisi PHP oleh `JendelaAbsenUmumTest`) — test ini tidak
 * menyentuh override sama sekali, supaya banner kalendernya benar-benar
 * terlihat apa adanya.
 */
test.describe('Hari Libur', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('menambah hari libur menutup Absen Umum, menghapusnya membuka kembali', async ({
    page,
  }) => {
    const keterangan = `E2E Libur ${Date.now()}`

    await page.goto('/admin/kelola-absen/setting')
    await pilihTanggalHariIni(page, 'libur-tanggal')
    await page.getByLabel('Keterangan').fill(keterangan)
    await page.getByRole('button', { name: 'Tambah Hari Libur' }).click()
    await expect(page.getByText(keterangan)).toBeVisible()

    // Banner kalender di Absen Umum menyebut alasannya secara eksplisit,
    // bukan sekadar "tertutup" — lihat KalenderKerjaService::alasanLibur().
    await page.goto('/admin/kelola-absen/absen-umum')
    await pilihDariDropdown(page, 'unit', UNIT_KERJA.blkSurabaya.nama)
    // Muncul dua kali (baris Datang dan Pulang sama-sama membaca kalender
    // yang sama) — cukup satu yang benar-benar tampak.
    await expect(page.getByText(keterangan).first()).toBeVisible()

    // Hapus lagi — dialog konfirmasi native, bukan modal.
    await page.goto('/admin/kelola-absen/setting')
    page.once('dialog', (dialogNative) => dialogNative.accept())
    await page
      .locator('li', { has: page.getByText(keterangan) })
      .getByRole('button', { name: 'Hapus' })
      .click()
    await expect(page.getByText(keterangan)).not.toBeVisible()

    // Absen Umum kembali terbuka menurut jadwal biasa (tidak lagi
    // menyebut alasan kalender apa pun).
    await page.goto('/admin/kelola-absen/absen-umum')
    await pilihDariDropdown(page, 'unit', UNIT_KERJA.blkSurabaya.nama)
    await expect(page.getByText(keterangan)).toHaveCount(0)
  })
})
