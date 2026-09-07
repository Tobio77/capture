import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Kemiringan 3D yang mengikuti posisi kursor (S36).
 *
 * Sudutnya ditulis ke dua properti kustom — `--miring-x` dan `--miring-y` —
 * dan CSS yang menerjemahkannya menjadi `rotateX`/`rotateY`. Pembagian itu
 * disengaja: JavaScript tidak pernah menyentuh `style.transform`, sehingga
 * kelas `.miring` tetap dapat dimatikan sepenuhnya dari CSS, termasuk oleh
 * aturan `prefers-reduced-motion`, tanpa skrip ini perlu tahu apa-apa.
 *
 * **Tidak berjalan pada layar sentuh.** Penunjuk kasar tidak punya kursor yang
 * melayang, sehingga efek ini mustahil terlihat di perangkat titik absen —
 * dan justru di sanalah anggaran gambarnya paling sempit. Memeriksanya di sini
 * membuat perangkat itu tidak membayar apa pun: tidak ada pendengar peristiwa
 * yang dipasang sama sekali.
 *
 * **Satu penulisan per bingkai.** Peristiwa `pointermove` datang jauh lebih
 * sering daripada bingkai gambar; menulis properti pada setiap peristiwa
 * berarti membuang pekerjaan yang tidak pernah sempat tergambar.
 */
export function useMiring({ maksimal = 6 } = {}) {
  const elemen = ref(null)

  let terjadwal = false
  let terakhir = null

  const halus = () =>
    window.matchMedia?.('(pointer: fine)').matches &&
    !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

  const tulis = () => {
    terjadwal = false

    if (!elemen.value || !terakhir) return

    const kotak = elemen.value.getBoundingClientRect()
    const x = (terakhir.clientX - kotak.left) / kotak.width - 0.5
    const y = (terakhir.clientY - kotak.top) / kotak.height - 0.5

    // Sumbu X dibalik: kursor di bagian atas kartu memiringkannya MENJAUH,
    // seperti benda nyata yang ditekan pada tepi itu.
    elemen.value.style.setProperty('--miring-x', `${(-y * maksimal).toFixed(2)}deg`)
    elemen.value.style.setProperty('--miring-y', `${(x * maksimal).toFixed(2)}deg`)
  }

  const gerak = (peristiwa) => {
    terakhir = peristiwa

    if (terjadwal) return

    terjadwal = true
    requestAnimationFrame(tulis)
  }

  const lepas = () => {
    elemen.value?.style.removeProperty('--miring-x')
    elemen.value?.style.removeProperty('--miring-y')
  }

  onMounted(() => {
    if (!elemen.value || !halus()) return

    elemen.value.classList.add('miring')
    elemen.value.addEventListener('pointermove', gerak)
    elemen.value.addEventListener('pointerleave', lepas)
  })

  onBeforeUnmount(() => {
    elemen.value?.removeEventListener('pointermove', gerak)
    elemen.value?.removeEventListener('pointerleave', lepas)
  })

  return elemen
}
