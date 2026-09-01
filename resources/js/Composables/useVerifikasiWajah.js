import { ref } from 'vue'
import { useFaceApi } from '@/Composables/useFaceApi'

/**
 * Verifikasi wajah 1:1 di sisi klien (FR-TAP-04, SDD §3).
 *
 * Embedding hasil capture dibandingkan HANYA dengan embedding milik ID yang
 * di-tap — bukan pencarian ke seluruh pegawai. Server mengirimkan deskriptor
 * itu bersama jawaban identifikasi tap, sehingga tidak ada biometrik pegawai
 * lain yang perlu berada di browser kiosk.
 */

/*
 * Pemetaan jarak Euclidean face-api ke persentase kecocokan.
 *
 * face-api sendiri tidak mengenal "persen"; ia menghasilkan jarak, dan 0,6
 * adalah batas keputusan bawaannya. Setting Absen menyatakan ambang dalam
 * persen 70–99 (FR-SET-03), jadi keduanya perlu dijembatani.
 *
 * Skala ini dikalibrasi lurus: jarak 0,60 (batas face-api) jatuh tepat pada
 * 70% — ambang paling longgar yang dapat dipilih admin — dan jarak 0,20
 * (kecocokan sangat kuat) jatuh pada 99%. Ambang bawaan 85% dengan demikian
 * menuntut jarak <= ~0,393, yaitu lebih ketat daripada bawaan face-api.
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
   * Bandingkan wajah pada elemen video dengan embedding referensi.
   *
   * Mengembalikan { cocok, skor, jarak } bila wajah terdeteksi, atau
   * { galat } berisi pesan siap tampil.
   */
  async function verifikasi(sumber, embeddingReferensi, ambang) {
    if (!Array.isArray(embeddingReferensi) || embeddingReferensi.length !== 128) {
      return { galat: 'Wajah pegawai ini belum terdaftar. Hubungi admin unit kerja.' }
    }

    memverifikasi.value = true

    try {
      const hasil = await hitungEmbedding(sumber)

      if (hasil.galat) return { galat: hasil.galat }

      const jarak = jarakEuclidean(hasil.embedding, embeddingReferensi)
      const skor = persenKecocokan(jarak)

      return { cocok: skor >= ambang, skor, jarak, embedding: hasil.embedding }
    } catch {
      return { galat: 'Modul pengenalan wajah gagal dimuat. Muat ulang layar kiosk.' }
    } finally {
      memverifikasi.value = false
    }
  }

  return { memuatModel: memuat, siapkanModel: siapkan, memverifikasi, verifikasi }
}

function jarakEuclidean(a, b) {
  let jumlah = 0

  for (let i = 0; i < a.length; i++) {
    const selisih = a[i] - b[i]
    jumlah += selisih * selisih
  }

  return Math.sqrt(jumlah)
}
