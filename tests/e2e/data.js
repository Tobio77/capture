/**
 * Data seeded yang dipakai test E2E — sengaja disalin dari
 * `database/seeders/*.php`, BUKAN dibaca ulang dari sana, supaya perubahan di
 * kedua sisi terlihat jelas sebagai diff. Bila seeder-nya berubah, sesuaikan
 * berkas ini juga.
 */

export const ADMIN = {
  superadmin: { email: 'superadmin@capture.test', password: 'password', nama: 'Superadmin SI-ABSEN' },
  adminDinas: { email: 'admin.dinas@capture.test', password: 'password', nama: 'Admin Dinas Disnakertrans' },
  adminUpt: { email: 'admin.blksby@capture.test', password: 'password', nama: 'Admin UPT BLK Surabaya' },
}

export const UNIT_KERJA = {
  dinas: { kode: 'DISNAKER', nama: 'Dinas Tenaga Kerja dan Transmigrasi Provinsi Jawa Timur' },
  blkSurabaya: { kode: 'BLK-SBY', nama: 'UPT Balai Latihan Kerja Surabaya' },
  blkMojokerto: { kode: 'BLK-MJK', nama: 'UPT Balai Latihan Kerja Mojokerto' },
}

/**
 * Pegawai TANPA wajah terdaftar (`wajah: false` pada `PegawaiSeeder`) —
 * dipilih sengaja untuk uji tap kiosk: absennya tercatat tanpa bergantung
 * pada pencocokan wajah sungguhan, yang rapuh diuji dengan kamera palsu (lihat
 * catatan cakupan di `tests/e2e/specs/kiosk-dan-event.spec.js`).
 */
export const PEGAWAI_TANPA_WAJAH = {
  nip: '199206302015022005',
  nama: 'Dewi Anggraini',
  unit: UNIT_KERJA.blkSurabaya,
}

export const PEGAWAI_UNIT_LAIN = {
  nip: '199401222016032007',
  nama: 'Rina Puspitasari',
  unit: UNIT_KERJA.blkMojokerto,
}
