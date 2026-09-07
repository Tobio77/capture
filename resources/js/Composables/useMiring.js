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

/**
 * Kemiringan 3D untuk SEKELOMPOK kartu, dengan satu pendengar peristiwa.
 *
 * Memanggil {@see useMiring} sekali per kartu akan memasang dua pendengar
 * pada setiap kartu; pada Dashboard yang bisa memuat sebelas kartu tertaut,
 * itu dua puluh dua pendengar untuk satu efek hiasan. Di sini pendengarnya
 * satu, dipasang pada wadahnya, dan kartu yang sedang disentuh kursor
 * ditemukan lewat `closest()`.
 *
 * Sama seperti versi tunggalnya: tidak dipasang sama sekali pada penunjuk
 * kasar, sehingga perangkat layar sentuh tidak membayar apa pun.
 */
export function useMiringDaftar(pilih, { maksimal = 5 } = {}) {
  const wadah = ref(null)

  let terjadwal = false
  let terakhir = null
  let sasaran = null

  const halus = () =>
    window.matchMedia?.('(pointer: fine)').matches &&
    !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

  const tulis = () => {
    terjadwal = false

    if (!sasaran || !terakhir) return

    const kotak = sasaran.getBoundingClientRect()
    const x = (terakhir.clientX - kotak.left) / kotak.width - 0.5
    const y = (terakhir.clientY - kotak.top) / kotak.height - 0.5

    sasaran.style.setProperty('--miring-x', `${(-y * maksimal).toFixed(2)}deg`)
    sasaran.style.setProperty('--miring-y', `${(x * maksimal).toFixed(2)}deg`)
  }

  const gerak = (peristiwa) => {
    const kartu = peristiwa.target.closest?.(pilih)

    // Berpindah kartu: yang ditinggalkan dikembalikan ke posisi datarnya.
    if (sasaran && sasaran !== kartu) lepaskan(sasaran)

    sasaran = kartu
    terakhir = peristiwa

    if (!kartu || terjadwal) return

    terjadwal = true
    requestAnimationFrame(tulis)
  }

  const lepaskan = (elemen) => {
    elemen?.style.removeProperty('--miring-x')
    elemen?.style.removeProperty('--miring-y')
  }

  const keluar = () => {
    lepaskan(sasaran)
    sasaran = null
  }

  onMounted(() => {
    if (!wadah.value || !halus()) return

    wadah.value.querySelectorAll(pilih).forEach((e) => e.classList.add('miring'))
    wadah.value.addEventListener('pointermove', gerak)
    wadah.value.addEventListener('pointerleave', keluar)
  })

  onBeforeUnmount(() => {
    wadah.value?.removeEventListener('pointermove', gerak)
    wadah.value?.removeEventListener('pointerleave', keluar)
  })

  return wadah
}
