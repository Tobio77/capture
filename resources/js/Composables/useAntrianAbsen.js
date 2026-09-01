import { ref } from 'vue'

/**
 * Antrian absen luring (NFR-05).
 *
 * Ketika kiosk kehilangan koneksi di tengah apel, absen tidak boleh hilang
 * begitu saja: pegawai sudah berdiri di depan kamera dan wajahnya sudah
 * cocok. Kiriman yang gagal karena jaringan disimpan di `localStorage` — agar
 * bertahan walau layar kiosk dimuat ulang atau perangkat sempat mati — lalu
 * dikirim ulang sendiri begitu jaringan pulih.
 *
 * Pengiriman ulang aman diulang berapa kali pun: server memakai kunci unik
 * (event, pegawai, jenis), sehingga kiriman kembar memperbarui baris yang
 * sama alih-alih menambah baris baru (FR-TAP-05).
 */

const KUNCI = 'siabsen.antrian-absen'

/*
 * Batas panjang antrian. Satu absen berfoto menempati ~30–70 KB dalam bentuk
 * base64, sedangkan localStorage umumnya dibatasi ~5 MB; 60 entri menyisakan
 * ruang aman sekaligus menutup gangguan jaringan yang cukup panjang.
 */
const BATAS_ANTRIAN = 60

function baca() {
  try {
    const isi = JSON.parse(localStorage.getItem(KUNCI) ?? '[]')

    return Array.isArray(isi) ? isi : []
  } catch {
    // Isi yang rusak lebih baik dibuang daripada menghentikan layar kiosk.
    return []
  }
}

function tulis(daftar) {
  try {
    localStorage.setItem(KUNCI, JSON.stringify(daftar))
  } catch {
    // Kuota penyimpanan penuh; antrian di memori tetap berjalan.
  }
}

export function useAntrianAbsen() {
  const antrian = ref(baca())
  const sedangMengirim = ref(false)

  function simpan(daftar) {
    antrian.value = daftar
    tulis(daftar)
  }

  /** Masukkan satu absen ke antrian. */
  function antrikan(muatan) {
    if (antrian.value.length >= BATAS_ANTRIAN) {
      return false
    }

    simpan([...antrian.value, { ...muatan, diantrikan_pada: new Date().toISOString() }])

    return true
  }

  /**
   * Coba kirim ulang seluruh antrian.
   *
   * `kirim` mengembalikan salah satu dari:
   *   'berhasil' — hapus dari antrian
   *   'ditolak'  — server menolaknya karena alasan yang tidak akan berubah
   *                (event ditutup, pegawai nonaktif); hapus juga, karena
   *                mengulangnya selamanya tidak akan menolong
   *   'tunda'    — jaringan masih bermasalah; pertahankan dan coba lagi nanti
   */
  async function kirimUlang(kirim) {
    if (sedangMengirim.value || antrian.value.length === 0) {
      return { terkirim: 0, ditolak: 0 }
    }

    sedangMengirim.value = true

    const tersisa = []
    let terkirim = 0
    let ditolak = 0

    try {
      for (const muatan of antrian.value) {
        const hasil = await kirim(muatan)

        if (hasil === 'berhasil') {
          terkirim++
        } else if (hasil === 'ditolak') {
          ditolak++
        } else {
          // Jaringan masih putus: hentikan putaran, sisanya menunggu giliran
          // berikutnya agar urutan tap tetap terjaga.
          tersisa.push(muatan, ...antrian.value.slice(antrian.value.indexOf(muatan) + 1))
          break
        }
      }
    } finally {
      simpan(tersisa)
      sedangMengirim.value = false
    }

    return { terkirim, ditolak }
  }

  return { antrian, sedangMengirim, antrikan, kirimUlang, BATAS_ANTRIAN }
}
