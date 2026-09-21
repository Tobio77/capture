import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'

/**
 * Laporan Kehadiran — "Unduh Data" (CSV/Excel/PDF) dan "Generate Laporan"
 * (Word/Excel/PDF, lewat Riwayat Laporan). Basis data E2E tidak punya data
 * kehadiran sungguhan (tidak ada event/tap yang di-seed — lihat
 * `tests/e2e/global-setup.js`), sehingga yang diuji di sini adalah MEKANISME
 * unduhannya (format, nama berkas, bahwa berkasnya benar-benar sampai) —
 * bukan isi datanya, yang sudah dicakup unit test `LaporanResmiServiceTest`
 * dan `LaporanTest`/`RiwayatLaporanTest` di sisi PHP.
 *
 * Dua tombol berlabel sama persis "Excel" ada di halaman ini — satu untuk
 * "Unduh Data", satu untuk "Generate Laporan" — dibedakan lewat urutan DOM
 * (`.first()`/`.last()`), bukan mengeklik keduanya sekaligus.
 *
 * "Generate Laporan" tidak lagi langsung mengunduh (revisi antrian, lihat
 * BuatLaporanResmiJob): tombolnya mengantrekan baris Riwayat Laporan baru,
 * lalu unduhannya diambil belakangan dari panel Riwayat begitu statusnya
 * "Selesai". `afterResponse()` merakitnya pada proses PHP yang sama sesaat
 * setelah jawaban terkirim, jadi baris itu biasanya sudah selesai begitu
 * panel menyegarkan dirinya sendiri lewat polling — pengujian ini menunggu
 * status itu, bukan berasumsi ia langsung "selesai" saat baris muncul.
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

  test('pratinjau laporan resmi membuka pdf di tab baru tanpa mengantre', async ({ page, context }) => {
    // Bukan waitForLoadState(): tab PDF dibuka lewat viewer bawaan Chromium,
    // yang tidak pernah benar-benar mencapai "networkidle" dalam batas waktu
    // wajar. Menunggu jawaban HTTP-nya langsung lebih tepat dan lebih cepat —
    // dan didaftarkan di level context (bukan di tab barunya, yang baru
    // tersedia SESUDAH page dibuka, sehingga rawan luput dari jawaban yang
    // keburu selesai lebih dulu).
    const [jawaban] = await Promise.all([
      context.waitForEvent('response', (res) => res.url().includes('/admin/laporan/preview')),
      page.getByRole('button', { name: 'Pratinjau' }).click(),
    ])

    expect(jawaban.status()).toBe(200)
    expect(jawaban.headers()['content-type']).toContain('application/pdf')

    // Tidak mengantre apa pun — panel Riwayat tetap kosong.
    await expect(page.getByText('Belum ada riwayat')).toBeVisible()
  })

  /*
   * Ambang 20 detik untuk baris riwayatnya MUNCUL, bukan hanya selesai.
   *
   * Jobnya berjalan `afterResponse()` pada koneksi `sync`, sedangkan server
   * uji adalah `php artisan serve` — satu proses. Selama dokumen dirakit,
   * permintaan berikutnya (termasuk polling panel Riwayat yang memunculkan
   * barisnya) MENUNGGU giliran. Sejak lampiran rincian kehadiran ikut dirakit
   * (S50), perakitan itu melewati ambang 5 detik bawaan Playwright pada basis
   * data yang sudah berisi absensi dari spec sebelumnya.
   *
   * Bukan gejala produksi: di sana job dijalankan worker antrean tersendiri,
   * bukan proses yang juga melayani HTTP.
   */
  test('generate laporan resmi sebagai Word mengantre lalu selesai diunduh dari riwayat', async ({ page }) => {
    await page.getByRole('button', { name: 'Word' }).click()

    const baris = page.locator('li', { hasText: 'Word ·' }).first()
    await expect(baris).toBeVisible({ timeout: 20_000 })
    await expect(baris.getByText('Selesai')).toBeVisible({ timeout: 15_000 })

    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      baris.getByRole('link', { name: 'Unduh' }).click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.docx$/)
  })

  test('generate laporan resmi sebagai Excel mengantre lalu selesai diunduh dari riwayat', async ({ page }) => {
    await page.getByRole('button', { name: 'Excel' }).last().click()

    const baris = page.locator('li', { hasText: 'Excel ·' }).first()
    await expect(baris).toBeVisible({ timeout: 20_000 })
    await expect(baris.getByText('Selesai')).toBeVisible({ timeout: 15_000 })

    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      baris.getByRole('link', { name: 'Unduh' }).click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.xlsx$/)
  })

  test('generate laporan resmi sebagai PDF mengantre lalu selesai diunduh dari riwayat', async ({ page }) => {
    await page.getByRole('button', { name: 'PDF' }).click()

    const baris = page.locator('li', { hasText: 'PDF ·' }).first()
    await expect(baris).toBeVisible({ timeout: 20_000 })
    await expect(baris.getByText('Selesai')).toBeVisible({ timeout: 15_000 })

    const [unduhan] = await Promise.all([
      page.waitForEvent('download'),
      baris.getByRole('link', { name: 'Unduh' }).click(),
    ])

    expect(unduhan.suggestedFilename()).toMatch(/\.pdf$/)
  })

  test('riwayat laporan dapat dihapus', async ({ page }) => {
    // Bukan mengasumsikan panel jadi kosong sesudahnya: test lain di berkas
    // ini (Word/Excel/PDF) berbagi database E2E yang sama dan boleh saja
    // sudah meninggalkan baris lain di Riwayat — yang diuji di sini murni
    // baris "PDF" ini bertambah lalu berkurang lagi satu.
    //
    // jumlahAwal SENGAJA dibaca sebelum klik, dan pertambahannya ditunggu
    // lewat assertion yang mengulang sendiri (toHaveCount) — bukan `.count()`
    // sekali baca sesudah klik, yang berisiko membaca DOM sebelum jawaban
    // POST-nya benar-benar sampai (baris "PDF" LAMA yang sudah "Selesai"
    // membuat assertion status lolos seketika tanpa pernah menunggu baris
    // BARU muncul, sehingga hitungannya keliru terekam sebelum baris keempat
    // benar-benar dirender).
    const semuaPdf = page.locator('li', { hasText: 'PDF ·' })
    const jumlahAwal = await semuaPdf.count()

    await page.getByRole('button', { name: 'PDF' }).click()

    // Ambang 20 detik: lihat catatan pada test "Word" di atas — perakitan
    // dokumen menahan permintaan berikutnya pada server uji berproses tunggal.
    await expect(semuaPdf).toHaveCount(jumlahAwal + 1, { timeout: 20_000 })

    const baris = semuaPdf.first()
    await expect(baris.getByText('Selesai')).toBeVisible({ timeout: 15_000 })

    page.once('dialog', (dialog) => dialog.accept())
    await baris.getByRole('button', { name: 'Hapus' }).click()

    await expect(semuaPdf).toHaveCount(jumlahAwal)
  })
})
