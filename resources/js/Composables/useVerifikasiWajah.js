import { ref } from 'vue'
import { useFaceApi } from '@/Composables/useFaceApi'

/**
 * Penangkapan deskriptor wajah untuk verifikasi 1:1 (FR-TAP-04, SDD §3).
 *
 * **Pencocokannya tidak lagi di sini.** Sampai audit keamanan pra-deploy,
 * layar kiosk menerima embedding referensi pegawai yang di-tap, mencocokkan
 * sendiri, lalu mengirimkan skornya — dan server hanya memeriksa ulang angka
 * itu terhadap ambang Setting Absen.
 *
 * Pemeriksaan semacam itu tidak pernah dapat dipercaya. Yang dibandingkan
 * server adalah angka yang dipilih pengirim dengan angka miliknya sendiri,
 * bukan wajah dengan wajah; siapa pun yang dapat mengirim satu permintaan
 * cukup menuliskan skor 100. Dan karena vektor referensinya sudah berada di
 * peramban, memindahkan perhitungan ke server saja tidak cukup — vektor itu
 * tinggal dipantulkan kembali sebagai "hasil capture" untuk memperoleh jarak
 * nol.
 *
 * Karena itu embedding referensi kini tidak pernah meninggalkan server.
 * Peramban hanya menghitung deskriptor wajah yang sedang berdiri di depan
 * kamera dan mengirimkannya; yang memutuskan cocok atau tidak adalah
 * `FotoReferensiWajahService::cocokkan()`.
 *
 * Deteksi wajah tetap berjalan di klien — itu tidak berubah dan memang
 * arsitektur yang dipilih (SDD §3). Yang pindah hanyalah keputusannya.
 */

/*
 * Pemetaan jarak Euclidean face-api ke persentase kecocokan.
 *
 * KEMBAR dengan FotoReferensiWajahService::persenKecocokan() di PHP. Yang di
 * sini dipakai menggambar angka di layar; yang di sana dipakai memutuskan.
 * Bila skala ini diubah, UBAH KEDUANYA — server dan peramban yang tidak
 * sepakat akan menampilkan satu angka lalu menolak dengan angka lain, dan
 * petugas tidak akan pernah memahami penolakannya.
 *
 * face-api sendiri tidak mengenal "persen"; ia menghasilkan jarak, dan 0,6
 * adalah batas keputusan bawaannya. Setting Absen menyatakan ambang dalam
 * persen 70–99 (FR-SET-03), jadi keduanya perlu dijembatani.
 *
 * Skala ini dikalibrasi lurus: jarak 0,60 jatuh tepat pada 70% — ambang paling
 * longgar yang dapat dipilih admin — dan jarak 0,20 jatuh pada 99%. Ambang
 * bawaan 85% dengan demikian menuntut jarak <= ~0,393, lebih ketat daripada
 * bawaan face-api.
 *
 * Angkanya persentase kalibrasi, BUKAN probabilitas.
 */
const JARAK_TERBAIK = 0.2
const JARAK_BATAS = 0.6
const PERSEN_TERBAIK = 99
const PERSEN_BATAS = 70

export function persenKecocokan(jarak) {
  const kemiringan = (PERSEN_TERBAIK - PERSEN_BATAS) / (JARAK_BATAS - JARAK_TERBAIK)
  const persen = PERSEN_TERBAIK - (jarak - JARAK_TERBAIK) * kemiringan

  return Math.max(0, Math.min(100, Math.round(persen * 100) / 100))
}

export function useVerifikasiWajah() {
  const { memuat, siapkan, hitungEmbedding } = useFaceApi()
  const memverifikasi = ref(false)

  /**
   * Hitung deskriptor wajah pada elemen video.
   *
   * Mengembalikan { embedding } bila tepat satu wajah terdeteksi, atau
   * { galat } berisi pesan siap tampil.
   */
  async function tangkapEmbedding(sumber) {
    memverifikasi.value = true

    try {
      const hasil = await hitungEmbedding(sumber)

      return hasil.galat ? { galat: hasil.galat } : { embedding: hasil.embedding }
    } catch {
      return { galat: 'Modul pengenalan wajah gagal dimuat. Muat ulang layar kiosk.' }
    } finally {
      memverifikasi.value = false
    }
  }

  return { memuatModel: memuat, siapkanModel: siapkan, memverifikasi, tangkapEmbedding }
}
