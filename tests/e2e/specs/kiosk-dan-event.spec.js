import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'
import { PEGAWAI_TANPA_WAJAH, UNIT_KERJA } from '../data.js'
import { pilihDariDropdown, pilihTanggalHariIni } from '../helpers.js'

/**
 * Jalur kiosk lengkap: admin mendaftarkan perangkat → perangkat diaktifkan →
 * admin membuat event kegiatan → perangkat bergabung lewat kode unit kerja →
 * pegawai tap → daftar e-Presensi bertambah → admin menutup event.
 *
 * Dijalankan sebagai SATU test happy-path berurutan (bukan dipecah per
 * langkah): setiap langkah butuh keadaan yang ditinggalkan langkah
 * sebelumnya (kode aktivasi, lalu kode unit kerja), dan memecahnya hanya
 * akan memindahkan fixture yang sama ke `beforeEach` tanpa menambah
 * keyakinan apa pun.
 *
 * **Cakupan verifikasi wajah**: pegawai yang di-tap sengaja
 * {@link PEGAWAI_TANPA_WAJAH} — TIDAK punya wajah terdaftar. Absennya
 * karena itu tidak pernah melalui pencocokan wajah sungguhan (server hanya
 * mencocokkan bila `wajah_terdaftar` true), sehingga kamera palsu Chromium
 * (lihat `playwright.config.js`) cukup menyediakan SATU bingkai video agar
 * foto bukti kehadiran dapat diambil — tidak perlu wajah sungguhan di
 * dalamnya. Pencocokan wajah 1:1 sungguhan sendiri SENGAJA di luar cakupan
 * suite ini (lihat catatan cakupan di `tests/e2e/README.md`): mustahil
 * diuji andal tanpa kamera dan model pengenalan wajah nyata.
 */
test.describe('Kiosk: perangkat, event, dan tap', () => {
  test.use({ storageState: BERKAS_AUTH.superadmin })

  test('perangkat aktif dapat bergabung ke event dan mencatat kehadiran', async ({
    page,
    context,
  }) => {
    const namaTitik = `E2E Titik Absen ${Date.now()}`
    const namaEvent = `E2E Kegiatan ${Date.now()}`

    // 1. Admin mendaftarkan perangkat baru.
    await page.goto('/admin/perangkat')
    await page.getByRole('button', { name: 'Daftarkan Perangkat' }).click()
    await page.getByLabel('Nama Titik Absen').fill(namaTitik)
    await pilihDariDropdown(page, 'unit_form', UNIT_KERJA.blkSurabaya.nama)
    await page.getByRole('button', { name: 'Simpan Perangkat' }).click()

    const kodeAktivasi = await page.locator('code').innerText()
    expect(kodeAktivasi.trim()).toMatch(/^[A-Z0-9-]+$/)

    // 2. Admin membuat event kegiatan berlaku untuk seluruh unit (termasuk
    // BLK Surabaya, tempat perangkat di atas dipasang), berlangsung hari ini.
    await page.goto('/admin/kelola-absen/event')
    await page.getByRole('button', { name: 'Buat Event' }).click()
    await page.getByLabel('Nama Event').fill(namaEvent)
    await pilihTanggalHariIni(page, 'tanggal')
    await page.getByLabel('Jam Mulai').fill('00:00')
    await page.getByLabel('Semua unit').check()
    await page.getByRole('button', { name: 'Simpan Event' }).click()

    // Buka detail event untuk membaca kode unit kerja BLK Surabaya.
    const barisEvent = page.getByRole('row', { name: new RegExp(namaEvent) })
    await expect(barisEvent).toBeVisible()
    await barisEvent.getByRole('button', { name: 'Detail' }).click()

    const dialog = page.getByRole('dialog')
    const barisKode = dialog.locator('li', {
      has: page.getByText(UNIT_KERJA.blkSurabaya.kode, { exact: true }),
    })
    const kodeUnit = await barisKode.locator('p').first().innerText()
    // Dialog punya DUA tombol bernama "Tutup": ikon-X di kepala (aria-label)
    // dan tombol teks di kaki — kaki selalu terakhir dalam urutan DOM.
    await dialog.getByRole('button', { name: 'Tutup', exact: true }).last().click()

    // 3. Matikan Verifikasi Wajah — lihat catatan cakupan di kepala berkas
    // ini. Tanpa ini, tap Dewi (tanpa wajah terdaftar) akan ditolak server
    // dengan WAJAH_BELUM_DIVERIFIKASI, sebab setting ini bawaannya menyala.
    await page.goto('/admin/kelola-absen/setting')
    await page.getByLabel('Verifikasi Wajah').uncheck()
    await page.getByRole('button', { name: 'Simpan Setting' }).click()
    await expect(page.getByText('Setting Absen tersimpan.')).toBeVisible()

    // 4. Perangkat (konteks peramban terpisah — sesi kiosk tidak berbagi
    // cookie sesi admin) diaktifkan dengan kode dari langkah 1.
    const halamanKiosk = await context.newPage()
    await halamanKiosk.goto('/kiosk/aktivasi')
    await halamanKiosk.getByLabel('Kode Aktivasi').fill(kodeAktivasi.trim())
    await halamanKiosk.getByRole('button', { name: 'Aktifkan Perangkat' }).click()
    await expect(halamanKiosk).toHaveURL(/\/$/)

    // 5. Perangkat bergabung ke event lewat kode unit kerja.
    await halamanKiosk.getByRole('button', { name: /Absen Event/ }).click()
    await halamanKiosk.getByLabel('Kode unit kerja').fill(kodeUnit.trim())
    await halamanKiosk.getByRole('button', { name: 'Gabung ke Event' }).click()
    await expect(halamanKiosk).toHaveURL(/\/kiosk\/event/)
    await expect(halamanKiosk.getByText(namaEvent)).toBeVisible()

    // 6. Tap NIP pegawai tanpa wajah terdaftar — lihat catatan cakupan di atas.
    await halamanKiosk.getByLabel('Kartu atau NIP').fill(PEGAWAI_TANPA_WAJAH.nip)
    await halamanKiosk.getByRole('button', { name: 'Absen Datang' }).click()

    await expect(halamanKiosk.getByText('Absen berhasil dicatat')).toBeVisible({ timeout: 15_000 })
    // Nama muncul dua kali sekaligus: panel hasil tap, dan baris baru pada
    // daftar e-Presensi di sebelahnya — keduanya sama-sama membuktikan absen
    // tercatat, jadi cukup pastikan setidaknya satu benar-benar tampak.
    await expect(halamanKiosk.getByText(PEGAWAI_TANPA_WAJAH.nama).first()).toBeVisible()

    // 7. Admin menutup event — konfirmasinya dialog `window.confirm()`
    // bawaan peramban, bukan modal kustom (lihat `Event/Index.vue::tutup()`).
    // Perangkat yang masih di layarnya melihat entry tertutup pada tarikan
    // berikutnya (di luar cakupan test ini — cukup pastikan aksi tutupnya
    // sendiri berhasil dari sisi admin).
    await page.goto('/admin/kelola-absen/event')
    page.once('dialog', (dialogNative) => dialogNative.accept())
    await page
      .getByRole('row', { name: new RegExp(namaEvent) })
      .getByRole('button', { name: 'Tutup' })
      .click()
    await expect(
      page.getByRole('row', { name: new RegExp(namaEvent) }).getByText('Ditutup'),
    ).toBeVisible()

    await halamanKiosk.close()
  })

  test('kode aktivasi salah ditolak', async ({ context }) => {
    const halamanKiosk = await context.newPage()
    await halamanKiosk.goto('/kiosk/aktivasi')
    await halamanKiosk.getByLabel('Kode Aktivasi').fill('SALAH-SALAH')
    await halamanKiosk.getByRole('button', { name: 'Aktifkan Perangkat' }).click()

    await expect(halamanKiosk).toHaveURL(/\/kiosk\/aktivasi/)
    await halamanKiosk.close()
  })
})
