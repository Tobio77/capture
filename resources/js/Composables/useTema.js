import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Tema tampilan: Terang, Gelap, atau mengikuti Sistem.
 *
 * Pilihan disimpan di localStorage perangkat, bukan di akun: satu admin dapat
 * memakai laptop terang di kantor dan tablet gelap di lapangan tanpa keduanya
 * saling menimpa.
 *
 * **Bawaannya Terang, bukan Sistem** (S30). Sebelumnya aplikasi mengikuti OS,
 * dan akibatnya perangkat yang OS-nya gelap membuka layar titik absen dalam
 * mode gelap — padahal titik absen berdiri di aula dan lorong yang terang
 * benderang, tempat layar gelap memantul dan sulit dibaca dari jarak berdiri.
 * Pengguna yang memang menghendaki gelap tetap dapat memilihnya, termasuk
 * memilih "Sistem" secara sadar.
 *
 * Bawaan ini harus sama di DUA tempat: di sini, dan pada skrip anti-kedip di
 * `resources/views/app.blade.php` yang berjalan sebelum CSS dimuat. Bila
 * keduanya berbeda, halaman sempat tergambar dengan tema yang salah.
 */

export const KUNCI_TEMA = 'capture.tema'

/** Mode yang berlaku sebelum pengguna memilih apa pun. */
export const TEMA_BAWAAN = 'terang'

export const MODE = [
  { nilai: 'terang', label: 'Terang', ikon: 'matahari' },
  { nilai: 'gelap', label: 'Gelap', ikon: 'bulan' },
  { nilai: 'sistem', label: 'Sistem', ikon: 'perangkat' },
]

/** Mode tersimpan, atau bawaan bila belum pernah dipilih. */
export function temaTersimpan() {
  try {
    const nilai = window.localStorage.getItem(KUNCI_TEMA)

    return ['terang', 'gelap', 'sistem'].includes(nilai) ? nilai : TEMA_BAWAAN
  } catch {
    // Peramban yang memblokir penyimpanan lokal tetap harus menampilkan
    // aplikasi; ia sekadar kehilangan ingatan pilihannya.
    return TEMA_BAWAAN
  }
}

/**
 * Tuliskan mode ke <html>.
 *
 * "sistem" berarti menghapus atributnya sama sekali, sehingga media query
 * `prefers-color-scheme` di tema.css yang mengambil alih.
 */
export function terapkanTema(mode) {
  const akar = document.documentElement

  if (mode === 'sistem') {
    akar.removeAttribute('data-tema')
  } else {
    akar.setAttribute('data-tema', mode)
  }
}

export function useTema() {
  const mode = ref(temaTersimpan())

  const kueriGelap = window.matchMedia?.('(prefers-color-scheme: dark)') ?? null

  // Tema yang benar-benar terlihat, sesudah "sistem" diselesaikan.
  const efektif = computed(() =>
    mode.value === 'sistem' ? (kueriGelap?.matches ? 'gelap' : 'terang') : mode.value,
  )

  const gelap = computed(() => efektif.value === 'gelap')

  function pilih(baru) {
    mode.value = baru
    terapkanTema(baru)

    try {
      window.localStorage.setItem(KUNCI_TEMA, baru)
    } catch {
      // Lihat catatan pada temaTersimpan().
    }
  }

  /** Putar Terang → Gelap → Sistem, untuk tombol tunggal. */
  function putar() {
    const urutan = ['terang', 'gelap', 'sistem']

    pilih(urutan[(urutan.indexOf(mode.value) + 1) % urutan.length])
  }

  // OS berganti tema selagi aplikasi terbuka: hanya berpengaruh pada mode
  // "sistem", tetapi pendengarnya tetap dipasang agar `efektif` ikut segar.
  const ikutiSistem = () => {
    if (mode.value === 'sistem') terapkanTema('sistem')
  }

  onMounted(() => {
    terapkanTema(mode.value)
    kueriGelap?.addEventListener('change', ikutiSistem)
  })

  onBeforeUnmount(() => kueriGelap?.removeEventListener('change', ikutiSistem))

  return { mode, efektif, gelap, pilih, putar, MODE }
}
