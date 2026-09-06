import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Menyingkap seksi ketika ia masuk ke pandangan (S34b).
 *
 * Ambangnya 0,12 sesuai dokumen UIUX SIMPEG v2: cukup rendah agar seksi
 * panjang tersingkap begitu tepi atasnya muncul, cukup tinggi agar ia tidak
 * tersingkap saat baru satu piksel terlihat.
 *
 * **Kelas `.ungkap` dipasang dari sini, bukan dari markup.** Bedanya penting:
 * kalau markup yang memasangnya lalu JavaScript gagal berjalan — peramban
 * lama, skrip diblokir jaringan dinas — seluruh isi halaman akan tetap
 * `opacity: 0` selamanya. Dengan dipasang dari sini, halaman tanpa JavaScript
 * tampil utuh apa adanya; yang hilang hanya animasinya.
 *
 * **Sekali singkap, tidak pernah disembunyikan lagi.** Elemen yang memudar
 * kembali saat digulir naik membuat halaman terasa gelisah, dan pada tabel
 * panjang ia berarti barisnya berkedip setiap kali orang menggulir mencari
 * sesuatu.
 */
export function useUngkap(pilih = '[data-ungkap]', { ambang = 0.12, jarak = 90 } = {}) {
  let pengamat = null
  let bersihkan = null

  onMounted(() => {
    const elemen = [...document.querySelectorAll(pilih)]

    if (elemen.length === 0) return

    /*
     * Pengguna yang meminta gerak minimal tidak dibuatkan pengamat sama
     * sekali: aturan CSS global memang sudah memangkas durasinya, tetapi
     * memasang `.ungkap` lalu mencabutnya seketika tetap menghasilkan satu
     * bingkai kosong yang terlihat sebagai kedipan.
     */
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return

    elemen.forEach((e, urutan) => {
      e.classList.add('ungkap')

      // Jeda bertahap antar-elemen sekelompok, dibatasi supaya elemen
      // kesepuluh tidak menunggu hampir satu detik.
      e.style.setProperty('--tunda', `${Math.min(urutan, 6) * jarak}ms`)
    })

    const singkap = (e) => {
      e.classList.add('tersingkap')
      pengamat?.unobserve(e)
    }

    pengamat = new IntersectionObserver(
      (masukan) => masukan.forEach((satu) => satu.isIntersecting && singkap(satu.target)),
      { threshold: ambang },
    )

    elemen.forEach((e) => pengamat.observe(e))

    /*
     * Penyapu cadangan untuk seksi yang TERLEWATI.
     *
     * IntersectionObserver hanya menyala ketika elemennya MELINTASI ambang.
     * Gulir yang melompat — tombol "ke akhir halaman", pemulihan posisi gulir
     * saat kembali dari halaman lain, tautan berjangkar — memindahkan seksi
     * dari bawah layar ke atas layar tanpa pernah melintasi apa pun, sehingga
     * pengamatnya tidak pernah dipanggil dan seksi itu tinggal pada
     * `opacity: 0` SELAMANYA.
     *
     * Terbukti nyata saat diperiksa di peramban, bukan dibayangkan: setelah
     * `scrollTo(0, scrollHeight)`, panel pertama tetap tak terlihat.
     *
     * Penyapunya dibatasi satu kali per bingkai dan berhenti sendiri begitu
     * semuanya tersingkap.
     */
    let terjadwal = false

    const sapu = () => {
      terjadwal = false

      const sisa = elemen.filter((e) => !e.classList.contains('tersingkap'))

      if (sisa.length === 0) {
        window.removeEventListener('scroll', jadwalkan)

        return
      }

      sisa.forEach((e) => e.getBoundingClientRect().top < 0 && singkap(e))
    }

    const jadwalkan = () => {
      if (terjadwal) return

      terjadwal = true
      requestAnimationFrame(sapu)
    }

    window.addEventListener('scroll', jadwalkan, { passive: true })
    bersihkan = () => window.removeEventListener('scroll', jadwalkan)
  })

  onBeforeUnmount(() => {
    pengamat?.disconnect()
    bersihkan?.()
  })
}
