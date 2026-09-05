import { onBeforeUnmount, ref, unref, watch } from 'vue'

/**
 * Angka yang berjalan naik dari nol ke nilainya (S30c).
 *
 * Dipakai kartu statistik dashboard. Alasannya bukan sekadar gerak: angka yang
 * berjalan memaksa mata BERHENTI pada angkanya, dan pada layar yang memuat
 * empat kartu sekaligus itulah yang membedakan ringkasan yang terbaca dari
 * deretan angka yang terlewat.
 *
 * Tiga hal yang membuatnya tidak mengganggu:
 *
 * 1. Hanya berjalan sekali saat nilainya berubah, bukan berulang.
 * 2. Pelambatannya `easeOutExpo` — cepat di awal, nyaris berhenti di akhir —
 *    sehingga angka akhirnya terbaca jauh sebelum animasinya benar-benar
 *    selesai. Pengguna tidak pernah menunggu untuk tahu angkanya.
 * 3. Pengguna yang meminta gerak minimal di OS-nya langsung menerima nilai
 *    akhirnya. Aturan global `prefers-reduced-motion` di tema.css hanya
 *    memangkas animasi CSS; animasi berbasis JavaScript harus memeriksanya
 *    sendiri.
 */

const DURASI_MS = 900

/** Cepat di awal, nyaris berhenti di akhir. */
function pelambatan(t) {
  return t === 1 ? 1 : 1 - Math.pow(2, -10 * t)
}

function gerakMinimal() {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
}

/**
 * @param  sumber        angka atau ref berisi angka
 * @param  desimal       jumlah angka di belakang koma (persentase memakai 1)
 * @param  tunda         jeda sebelum mulai, untuk menyelaraskan dengan
 *                       kemunculan bertahap kartunya
 */
export function useAngkaBerjalan(sumber, { desimal = 0, tunda = 0 } = {}) {
  const tampil = ref(0)

  let rangka = null
  let jedaMulai = null

  const bulatkan = (nilai) => Number(nilai.toFixed(desimal))

  function berhenti() {
    if (rangka !== null) cancelAnimationFrame(rangka)
    if (jedaMulai !== null) clearTimeout(jedaMulai)

    rangka = null
    jedaMulai = null
  }

  function jalankan(tujuan) {
    berhenti()

    const akhir = Number(tujuan) || 0

    if (gerakMinimal()) {
      tampil.value = bulatkan(akhir)

      return
    }

    const awal = tampil.value

    jedaMulai = setTimeout(() => {
      const mulai = performance.now()

      const langkah = (sekarang) => {
        const bagian = Math.min(1, (sekarang - mulai) / DURASI_MS)

        tampil.value = bulatkan(awal + (akhir - awal) * pelambatan(bagian))

        if (bagian < 1) rangka = requestAnimationFrame(langkah)
      }

      rangka = requestAnimationFrame(langkah)
    }, tunda)
  }

  watch(() => unref(sumber), jalankan, { immediate: true })

  onBeforeUnmount(berhenti)

  return tampil
}
