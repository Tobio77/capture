import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'
import { PEGAWAI_TANPA_WAJAH, UNIT_KERJA } from '../data.js'
import {
  bukaPaksaAbsenUmum,
  daftarkanDanAktifkanKiosk,
  pilihTanggalHariIni,
  siapkanUntukTapKiosk,
} from '../helpers.js'

/**
 * Rekap Absen, kedua tab (FR-REK-01 s.d. FR-REK-03) — "Unduh Data" (CSV/
 * Excel) untuk tab kegiatan dan tab umum. Endpoint ekspornya sama persis
 * (`TabelDataExport`) dengan Laporan, sudah teruji format & isinya lewat
 * `RekapTest`/`AbsenUmumTest` PHP; yang dipastikan di sini hanya bahwa
 * tombolnya benar-benar mengunduh dari layar sungguhan.
 */
test.describe('Rekap Absen', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('tab umum: unduh CSV dan Excel', async ({ page, context }) => {
    await siapkanUntukTapKiosk(page)
    await bukaPaksaAbsenUmum(page)

    const kiosk = await daftarkanDanAktifkanKiosk(page, context, UNIT_KERJA.blkSurabaya.kode)
    await kiosk.getByRole('button', { name: 'Absen Umum' }).click()
    await expect(kiosk).toHaveURL(/\/kiosk\/umum/)

    await kiosk.getByLabel('Kartu atau NIP').fill(PEGAWAI_TANPA_WAJAH.nip)
    await kiosk.getByRole('button', { name: 'Absen Datang' }).click()
    await expect(kiosk.getByText('Absen berhasil dicatat')).toBeVisible({ timeout: 15_000 })
    await kiosk.close()

    // Sejak S49 sesi harian satu untuk seluruh dinas: tidak ada unit yang
    // perlu dipilih lebih dulu — barisnya langsung tampil.
    await page.goto('/admin/kelola-absen/rekap?tab=umum')
    await expect(page.getByText(PEGAWAI_TANPA_WAJAH.nama)).toBeVisible()

    // Tombol hapus per-baris (superadmin saja) bergantung pada properti
    // Inertia yang dibagikan lewat `auth.pengguna`, bukan `auth.user` —
    // salah kutip properti itu (bug nyata sebelumnya) membuat tombolnya
    // diam-diam TIDAK PERNAH tampil bagi siapa pun, tanpa error yang
    // kelihatan; otorisasi baksennya sendiri sudah teruji lewat
    // `AbsensiHapusTest` PHP.
    await expect(page.getByTitle('Hapus absensi datang')).toBeVisible()

    const [csv] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'CSV' }).click(),
    ])
    expect(csv.suggestedFilename()).toMatch(/\.csv$/)

    const [xlsx] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Excel' }).click(),
    ])
    expect(xlsx.suggestedFilename()).toMatch(/\.xlsx$/)
  })

  test('tab kegiatan: unduh CSV dan Excel', async ({ page }) => {
    const namaEvent = `E2E Rekap ${Date.now()}`

    await page.goto('/admin/kelola-absen/event')
    await page.getByRole('button', { name: 'Buat Event' }).click()
    await page.getByLabel('Nama Event').fill(namaEvent)
    await pilihTanggalHariIni(page, 'tanggal')
    await page.getByLabel('Jam Mulai').fill('00:00')
    await page.getByRole('button', { name: 'Simpan Event' }).click()
    await expect(page.getByRole('row', { name: new RegExp(namaEvent) })).toBeVisible()

    // Tidak ada tap pada event ini — tab kegiatan Rekap tetap dapat diunduh
    // walau kosong (baris nol bukan alasan menyembunyikan tombolnya).
    await page.goto('/admin/kelola-absen/rekap')
    await expect(page.getByRole('heading', { name: namaEvent })).toBeVisible()

    const [csv] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'CSV' }).click(),
    ])
    expect(csv.suggestedFilename()).toMatch(/\.csv$/)

    const [xlsx] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Excel' }).click(),
    ])
    expect(xlsx.suggestedFilename()).toMatch(/\.xlsx$/)
  })

  test('tab kegiatan: pemilih event dapat dicari', async ({ page }) => {
    // Bagian 4 (pemilih event yang lebih rapi): dropdown datar diganti
    // combobox dapat-dicari — yang diuji di sini murni bahwa mengetik
    // benar-benar menyaring opsi yang muncul, sesuatu yang cuma bisa
    // dipastikan lewat peramban sungguhan.
    //
    // Menumpangi event yang sudah ditinggalkan test sebelumnya, bukan
    // membuat event baru sendiri: FR-EVT-06 menolak dua event AKTIF yang
    // cakupannya beririsan, dan test "unduh CSV dan Excel" di atas sudah
    // meninggalkan satu event "semua unit" aktif — event baru apa pun yang
    // dibuat di sini pasti bentrok dengannya.
    await page.goto('/admin/kelola-absen/rekap')

    const nama = await page.getByRole('heading', { level: 2 }).first().textContent()

    const pemilih = page.getByRole('combobox', { name: 'Event' })
    await pemilih.click()

    // Kata kunci yang tidak cocok dengan event mana pun tidak menampilkan
    // opsi apa pun — bukan diam-diam menampilkan seluruh daftar lagi.
    await pemilih.fill('Tidak Pernah Ada Event Bernama Ini')
    await expect(page.getByText('Tidak ada event yang cocok.')).toBeVisible()

    // Kata kunci yang cocok menampilkan opsinya.
    await pemilih.fill(nama.slice(0, 8))
    await expect(page.getByRole('option', { name: new RegExp(nama) })).toBeVisible()

    await page.getByRole('option', { name: new RegExp(nama) }).click()
    await expect(page.getByRole('heading', { name: nama })).toBeVisible()
  })
})
