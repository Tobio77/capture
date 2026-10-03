import { reactive } from 'vue'

/**
 * Pusat notifikasi aplikasi — popup bergaya SweetAlert dan dialog konfirmasi.
 *
 * Keadaannya disimpan di tingkat modul, bukan per komponen: satu
 * {@see PusatNotifikasi} yang dipasang di akar aplikasi menggambar semuanya,
 * dan halaman mana pun cukup memanggil `notifikasi.sukses(...)` atau
 * `await konfirmasi(...)` tanpa perlu menaruh komponen apa pun di templatnya.
 *
 * Popup diantrekan, tidak ditumpuk: dua kabar yang tiba bersamaan dibaca
 * bergiliran, bukan saling menutupi di tengah layar.
 */

const keadaan = reactive({
  /** Popup yang sedang tampil, atau null. */
  popup: null,

  /** Popup yang menunggu giliran. */
  antrean: [],

  /** Dialog konfirmasi yang sedang terbuka, atau null. */
  konfirmasi: null,
})

let nomor = 0

/** Lama tampil bawaan per jenis, dalam milidetik. 0 berarti menunggu ditutup. */
const DURASI = {
  sukses: 2600,
  info: 4000,
  peringatan: 6000,
  gagal: 6500,
}

const JUDUL = {
  sukses: 'Berhasil',
  info: 'Informasi',
  peringatan: 'Perhatian',
  gagal: 'Gagal',
}

function tampilkan(jenis, teks, opsi = {}) {
  const popup = {
    id: ++nomor,
    jenis,
    judul: opsi.judul ?? JUDUL[jenis],
    teks,
    durasi: opsi.durasi ?? DURASI[jenis],
    tombol: opsi.tombol ?? 'Oke',
  }

  if (keadaan.popup === null) {
    keadaan.popup = popup
  } else {
    keadaan.antrean.push(popup)
  }

  return popup.id
}

/** Tutup popup yang sedang tampil dan majukan antrean. */
export function tutupPopup() {
  keadaan.popup = keadaan.antrean.shift() ?? null
}

export const notifikasi = {
  sukses: (teks, opsi) => tampilkan('sukses', teks, opsi),
  gagal: (teks, opsi) => tampilkan('gagal', teks, opsi),
  peringatan: (teks, opsi) => tampilkan('peringatan', teks, opsi),
  info: (teks, opsi) => tampilkan('info', teks, opsi),
}

/**
 * Pengganti `window.confirm()` yang beranimasi.
 *
 * @param {object} opsi
 * @param {string} opsi.judul       pertanyaan singkat, mis. "Hapus event ini?"
 * @param {string} [opsi.teks]      akibatnya, dalam satu-dua kalimat
 * @param {string} [opsi.tombolYa]  label tombol konfirmasi
 * @param {string} [opsi.tombolTidak]
 * @param {'bahaya'|'peringatan'|'info'} [opsi.nada]
 *        `bahaya` untuk tindakan yang menghapus atau mencabut: tombolnya
 *        merah dan fokus awal jatuh pada "Batal", sehingga Enter yang
 *        tertekan tanpa sengaja tidak menjalankannya.
 * @returns {Promise<boolean>}
 */
export function konfirmasi(opsi) {
  // Dialog yang masih terbuka dianggap dibatalkan — tidak boleh ada janji
  // yang menggantung selamanya.
  keadaan.konfirmasi?.selesai(false)

  return new Promise((resolve) => {
    keadaan.konfirmasi = {
      id: ++nomor,
      judul: opsi.judul,
      teks: opsi.teks ?? '',
      tombolYa: opsi.tombolYa ?? 'Ya, lanjutkan',
      tombolTidak: opsi.tombolTidak ?? 'Batal',
      nada: opsi.nada ?? 'peringatan',
      selesai(jawaban) {
        if (keadaan.konfirmasi?.id === this.id) keadaan.konfirmasi = null
        resolve(jawaban)
      },
    }
  })
}

export function useNotifikasi() {
  return { keadaan, notifikasi, konfirmasi, tutupPopup }
}
