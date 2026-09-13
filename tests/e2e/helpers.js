import { expect } from '@playwright/test'

/**
 * Baca soal CAPTCHA hitungan di layar masuk dan hitung jawabannya.
 *
 * Soalnya teks biasa ("3 + 5" atau "3 − 5", tanda kurang memakai U+2212
 * bukan tanda hubung biasa — lihat `CaptchaHitungService::buat()`), sengaja
 * dibuat begitu supaya terbaca pembaca layar; itu juga yang membuatnya dapat
 * dijawab program tanpa mengintip sesi server.
 */
export async function hitungJawabanCaptcha(page) {
  const soal = (await page.locator('p.tabular-nums').innerText()).replace(/=\s*$/, '').trim()
  const [, a, operator, b] = soal.match(/^(\d+)\s*([+−])\s*(\d+)$/) ?? []

  if (!a) throw new Error(`Soal captcha tidak dikenali: "${soal}"`)

  return operator === '+' ? Number(a) + Number(b) : Number(a) - Number(b)
}

/**
 * Alur masuk Panel Admin lengkap (FR-AUTH-01, FR-AUTH-03), dipakai baik oleh
 * `auth.setup.js` (masuk yang berhasil, disimpan sebagai storageState) maupun
 * `login.spec.js` (keadaan gagalnya).
 */
export async function isiFormMasuk(page, { email, password }) {
  await page.goto('/masuk')
  await page.getByLabel('Alamat Surel').fill(email)
  await page.getByLabel('Kata Sandi').fill(password)
  await page.locator('#jawaban-captcha').fill(String(await hitungJawabanCaptcha(page)))
  await page.getByRole('button', { name: 'Masuk' }).click()
}

/** Masuk dan pastikan benar-benar sampai ke Dashboard sebelum lanjut. */
export async function masuk(page, kredensial) {
  await isiFormMasuk(page, kredensial)
  await expect(page).toHaveURL(/\/admin\/dashboard/)
}

/**
 * Pilih satu opsi pada `Components/UI/Pilihan.vue` — pengganti `<select>`
 * native berbasis Headless UI Listbox, dipakai di hampir semua formulir
 * admin (unit kerja, filter, dst.). Dibuka lewat id tombolnya, opsinya
 * dipilih lewat namanya (role `option`, digambar Headless UI).
 */
export async function pilihDariDropdown(page, idTombol, namaOpsi) {
  await page.locator(`#${idTombol}`).click()
  await page.getByRole('option', { name: namaOpsi }).click()
}

/**
 * Daftarkan satu perangkat baru pada unit kerja yang diberikan (lewat
 * `page` admin yang sudah masuk) lalu aktifkan di konteks TERPISAH (kiosk
 * tidak berbagi sesi/cookie dengan admin) — mengembalikan `Page` kiosk yang
 * sudah aktif, siap dipakai memilih Absen Umum/Absen Event.
 *
 * Dipakai lebih dari satu spec (kiosk-dan-event, rekap) yang sama-sama butuh
 * satu titik absen aktif tanpa peduli detail kode aktivasinya sendiri.
 */
export async function daftarkanDanAktifkanKiosk(adminPage, context, namaUnitKerja) {
  const namaTitik = `E2E ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`

  await adminPage.goto('/admin/perangkat')
  await adminPage.getByRole('button', { name: 'Daftarkan Perangkat' }).click()
  await adminPage.getByLabel('Nama Titik Absen').fill(namaTitik)
  await pilihDariDropdown(adminPage, 'unit_form', namaUnitKerja)
  await adminPage.getByRole('button', { name: 'Simpan Perangkat' }).click()

  const kode = (await adminPage.locator('code').innerText()).trim()

  const kiosk = await context.newPage()
  await kiosk.goto('/kiosk/aktivasi')
  await kiosk.getByLabel('Kode Aktivasi').fill(kode)
  await kiosk.getByRole('button', { name: 'Aktifkan Perangkat' }).click()
  await expect(kiosk).toHaveURL(/\/$/)

  return kiosk
}

/**
 * Setting Absen yang dibutuhkan HAMPIR SETIAP test yang benar-benar men-tap
 * dari layar kiosk (bukan hanya membuka layarnya):
 *
 *   1. Matikan Verifikasi Wajah — lihat catatan cakupan di kepala berkas
 *      `kiosk-dan-event.spec.js`; {@link PEGAWAI_TANPA_WAJAH} akan ditolak
 *      server (WAJAH_BELUM_DIVERIFIKASI) selama ini menyala.
 *   2. Lebarkan Jendela Operasional Harian Absen Datang — Absen Umum
 *      menolak tap di luar jam ini (beda dari event kegiatan, yang tidak
 *      mengenal jendela sama sekali), dan test dijalankan pada jam
 *      berapa pun sepanjang hari, bukan hanya jam kerja.
 *
 * Keduanya satu formulir, satu penyimpanan.
 */
export async function siapkanUntukTapKiosk(adminPage) {
  await adminPage.goto('/admin/kelola-absen/setting')

  let berubah = false

  const wajah = adminPage.getByLabel('Verifikasi Wajah')
  if (await wajah.isChecked()) {
    await wajah.uncheck()
    berubah = true
  }

  const jamBuka = adminPage.getByLabel('Jam buka Absen Datang')
  if ((await jamBuka.inputValue()) !== '00:00') {
    await jamBuka.fill('00:00')
    berubah = true
  }

  const jamTutup = adminPage.getByLabel('Jam tutup Absen Datang')
  if ((await jamTutup.inputValue()) !== '23:59') {
    await jamTutup.fill('23:59')
    berubah = true
  }

  if (!berubah) return

  await adminPage.getByRole('button', { name: 'Simpan Setting' }).click()
  await expect(adminPage.getByText('Setting Absen tersimpan.')).toBeVisible()
}

/**
 * Buka paksa Absen Umum untuk satu unit kerja, mengabaikan jadwal jam MAUPUN
 * kalender hari kerja (S39: hari libur MENUTUP Absen Umum secara bawaan,
 * kecuali override tetap menang di atas semuanya — lihat
 * `KalenderKerjaService`). Tanpa ini, test yang kebetulan berjalan di luar
 * jam kerja atau pada akhir pekan/tanggal merah akan menemukan Absen Umum
 * tertutup walau jendela jam sudah dilebarkan lewat
 * {@link siapkanUntukTapKiosk}.
 */
export async function bukaPaksaAbsenUmum(adminPage, namaUnitKerja) {
  await adminPage.goto('/admin/kelola-absen/absen-umum')
  await pilihDariDropdown(adminPage, 'unit', namaUnitKerja)

  // Tombolnya digambar sekali PER JENIS (datang, pulang) walau overridenya
  // berlaku untuk keduanya sekaligus — cukup satu klik dari yang mana pun.
  adminPage.once('dialog', (dialogNative) => dialogNative.accept())
  await adminPage.getByRole('button', { name: 'Buka paksa' }).first().click()
}

/**
 * Pilih hari ini pada `Components/UI/TanggalIsian.vue` — pemetik tanggal
 * kustom (Reka UI) pengganti `<input type="date">`. Tombol pintas "Hari ini"
 * dipakai apa adanya, bukan mengeklik sel kalender: sama-sama sah, dan ini
 * tidak bergantung pada bulan/tahun kalender yang sedang terbuka.
 */
export async function pilihTanggalHariIni(page, idTombol) {
  await page.locator(`#${idTombol}`).click()
  await page.getByRole('button', { name: 'Hari ini' }).click()
}
