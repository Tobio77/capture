import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'

/**
 * Maintenance & Backup — buat backup manual lalu pulihkan (FR-MTN-01,
 * FR-MTN-02). Superadmin saja.
 *
 * Yang dipastikan di sini murni interaksi peramban sungguhan yang tidak
 * bisa diuji lewat PHPUnit: tombol "Pulihkan Data" tetap NONAKTIF sampai
 * nama berkas diketik ulang PERSIS SAMA — pagar terakhir sebelum aksi yang
 * menulis ulang data lintas beberapa tabel sekaligus. Logika intinya sendiri
 * (gabung bukan ganti total, hierarki unit kerja, pivot event) sudah teruji
 * lewat `MaintenanceBackupTest` PHP.
 */
test.describe('Maintenance & Backup', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('buat backup manual lalu pulihkan dengan konfirmasi mengetik ulang', async ({ page }) => {
    await page.goto('/admin/setting/maintenance')

    // Bukan menunggu pesan flash (bisa hilang sendiri sebelum sempat
    // terperiksa bila server sedang sibuk) — baris yang muncul lalu
    // berstatus Selesai di bawah ini sudah cukup membuktikan permintaannya
    // benar-benar diproses.
    //
    // afterResponse() merakit backup PADA proses PHP yang sama SEBELUM
    // jawabannya benar-benar sampai ke peramban (lihat catatan pada
    // BuatBackupJob) — jawabannya sendiri baru tiba setelah backup selesai,
    // bukan seketika. Batas waktu digenerouskan di sini, bukan diandalkan
    // pada bawaan Playwright (5 detik), sebab merakit backup sungguhan
    // (bukan sekadar mengembalikan halaman) makin lambat begitu berjalan
    // jauh di tengah rangkaian E2E, bukan berdiri sendiri.
    await page.getByRole('button', { name: 'Buat Backup Sekarang' }).click()

    const baris = page.locator('li', { hasText: 'backup-absensi-' }).first()
    await expect(baris).toBeVisible({ timeout: 15_000 })
    await expect(baris.getByText('Selesai')).toBeVisible({ timeout: 15_000 })

    const namaBerkas = await baris.locator('p.text-sm.font-medium').first().textContent()

    await baris.getByRole('button', { name: 'Pulihkan' }).click()

    const dialog = page.getByRole('dialog', { name: 'Pulihkan Backup' })
    await expect(dialog).toBeVisible()

    const tombolPulihkan = dialog.getByRole('button', { name: 'Pulihkan Data' })
    const kolomKonfirmasi = dialog.getByLabel('Ketik ulang nama berkas untuk melanjutkan')

    // Kata kunci yang tidak cocok — tombol tetap nonaktif.
    await kolomKonfirmasi.fill('nama-berkas-yang-salah.zip')
    await expect(tombolPulihkan).toBeDisabled()

    // Nama berkas yang benar-benar cocok — tombol aktif, dan berhasil.
    // Dialognya menutup dirinya sendiri HANYA lewat onSuccess — dipilih
    // sebagai penanda keberhasilan alih-alih pesan flash yang bisa hilang
    // sendiri sebelum sempat terperiksa bila server sedang sibuk.
    await kolomKonfirmasi.fill(namaBerkas.trim())
    await expect(tombolPulihkan).toBeEnabled()
    await tombolPulihkan.click()

    await expect(dialog).not.toBeVisible({ timeout: 15_000 })
  })
})
