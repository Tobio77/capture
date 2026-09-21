import { test, expect } from '@playwright/test'
import { BERKAS_AUTH } from '../auth-state.js'
import { PEGAWAI_TANPA_WAJAH, UNIT_KERJA } from '../data.js'
import { pilihTanggalHariIni } from '../helpers.js'

/**
 * Jalur kiosk lengkap: admin membaca kode unit kerja → admin membuat event →
 * perangkat dihubungkan dengan kode itu → pegawai tap → daftar e-Presensi
 * bertambah → perangkat tercatat pada detail event → admin menutup event.
 *
 * Dijalankan sebagai SATU test happy-path berurutan (bukan dipecah per
 * langkah): setiap langkah butuh keadaan yang ditinggalkan langkah
 * sebelumnya (kode unit kerja, lalu event yang dibuka), dan memecahnya hanya
 * akan memindahkan fixture yang sama ke `beforeEach` tanpa menambah keyakinan
 * apa pun.
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

  test('perangkat yang dikenali lewat kode unit langsung melayani kegiatan', async ({
    page,
    context,
  }) => {
    /*
     * Delapan langkah dalam satu test, termasuk satu tap berkamera yang
     * menunggu foto dikompresi peramban. Batas 30 detik bawaan Playwright
     * cukup ketika berkas ini dijalankan sendirian, tetapi tidak ketika
     * seluruh suite berjalan berurutan pada satu proses `php artisan serve`
     * — server yang sama juga melayani permintaan spec sebelumnya.
     */
    test.slow()

    const namaEvent = `E2E Kegiatan ${Date.now()}`

    // 1. Admin membaca kode perangkat BLK Surabaya di Setting Unit Kerja.
    //    Sejak S49 kode inilah jalan masuk perangkat — tidak ada pendaftaran
    //    per mesin, dan tidak ada kode per event yang harus ditukarkan.
    await page.goto('/admin/kelola-absen/unit-kerja')

    const barisUnit = page.getByRole('row', { name: new RegExp(UNIT_KERJA.blkSurabaya.kode) })
    await expect(barisUnit).toBeVisible()

    const kodeUnit = (await barisUnit.getByRole('cell').nth(2).innerText()).trim()
    expect(kodeUnit).toMatch(/^[A-Z0-9]{4}-[A-Z0-9]{4}$/)

    // 2. Admin membuat event kegiatan yang berlangsung hari ini. Tidak ada
    //    pilihan cakupan: setiap event berlaku bagi seluruh unit kerja.
    await page.goto('/admin/kelola-absen/event')
    await page.getByRole('button', { name: 'Buat Event' }).click()
    await page.getByLabel('Nama Event').fill(namaEvent)
    await pilihTanggalHariIni(page, 'tanggal')
    await page.getByLabel('Jam Mulai').fill('00:00')
    await page.getByRole('button', { name: 'Simpan Event' }).click()

    await expect(page.getByRole('row', { name: new RegExp(namaEvent) })).toBeVisible()

    // 3. Matikan Verifikasi Wajah — lihat catatan cakupan di kepala berkas
    //    ini. Tanpa ini, tap Dewi (tanpa wajah terdaftar) akan ditolak server
    //    dengan WAJAH_BELUM_DIVERIFIKASI, sebab setting ini bawaannya menyala.
    await page.goto('/admin/kelola-absen/setting')
    await page.getByLabel('Verifikasi Wajah').uncheck()
    await page.getByRole('button', { name: 'Simpan Setting' }).click()
    await expect(page.getByText('Setting Absen tersimpan.')).toBeVisible()

    // 4. Perangkat (konteks peramban terpisah — sesi kiosk tidak berbagi
    //    cookie sesi admin) dihubungkan dengan kode unit dari langkah 1.
    const halamanKiosk = await context.newPage()
    await halamanKiosk.goto('/kiosk/aktivasi')
    await halamanKiosk.getByLabel('Kode Unit Kerja').fill(kodeUnit)
    await halamanKiosk.getByRole('button', { name: 'Hubungkan Perangkat' }).click()
    await expect(halamanKiosk).toHaveURL(/\/$/)

    // 5. Absen Event langsung terbuka — tidak ada langkah penukaran kode lagi.
    await halamanKiosk.getByRole('button', { name: /Absen Event/ }).click()
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

    // 7. Perangkatnya tercatat pada detail event beserta unit dan alamat IP —
    //    jawaban atas "mesin mana saja yang dipakai pada kegiatan ini".
    await page.goto('/admin/kelola-absen/event')
    await page
      .getByRole('row', { name: new RegExp(namaEvent) })
      .getByRole('button', { name: 'Detail' })
      .click()

    const dialog = page.getByRole('dialog')
    await expect(dialog.getByText(UNIT_KERJA.blkSurabaya.kode).first()).toBeVisible()
    // Dialog punya DUA tombol bernama "Tutup": ikon-X di kepala (aria-label)
    // dan tombol teks di kaki — kaki selalu terakhir dalam urutan DOM.
    await dialog.getByRole('button', { name: 'Tutup', exact: true }).last().click()

    // 8. Admin menutup event — konfirmasinya dialog `window.confirm()`
    //    bawaan peramban, bukan modal kustom (lihat `Event/Index.vue::tutup()`).
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

  test('kode unit kerja salah ditolak', async ({ context }) => {
    const halamanKiosk = await context.newPage()
    await halamanKiosk.goto('/kiosk/aktivasi')
    await halamanKiosk.getByLabel('Kode Unit Kerja').fill('ZZZZ-9999')
    await halamanKiosk.getByRole('button', { name: 'Hubungkan Perangkat' }).click()

    await expect(halamanKiosk).toHaveURL(/\/kiosk\/aktivasi/)
    await halamanKiosk.close()
  })
})
