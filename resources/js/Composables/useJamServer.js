import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Jam berjalan yang disetel ke jam SERVER (S31).
 *
 * Jam perangkat titik absen kerap meleset — sebagian tidak pernah disetel
 * sejak dibeli, sebagian kehilangan setelannya setiap kali listrik padam —
 * sementara jam yang tercatat pada absensi selalu jam server. Orang yang
 * membaca jam di layar harus melihat angka yang sama dengan yang kelak
 * tersimpan; kalau tidak, pertengkaran soal "saya absen jam berapa" tidak
 * pernah ada ujungnya.
 *
 * Yang disimpan adalah SELISIHNYA, bukan jamnya. Dengan begitu jam tetap
 * berdetak sendiri antar penarikan — tidak melompat mengikuti jaringan — dan
 * tetap menempel pada jam server setiap kali `setel()` dipanggil ulang.
 */

export function useJamServer(waktuServerAwal = null) {
  const selisih = ref(0)
  const sekarang = ref(new Date())

  let jeda = null

  /** Setel ulang dari stempel waktu server (ISO-8601 beserta offsetnya). */
  function setel(waktuServer) {
    if (!waktuServer) return

    const server = Date.parse(waktuServer)

    if (Number.isNaN(server)) return

    selisih.value = Date.now() - server
  }

  function detak() {
    sekarang.value = new Date(Date.now() - selisih.value)
  }

  setel(waktuServerAwal)
  detak()

  onMounted(() => {
    /*
     * Didetakkan tiap 250 ms, bukan tiap 1000 ms. Interval satu detik hampir
     * selalu melenceng dari pergantian detik yang sesungguhnya, sehingga
     * angka detik sesekali melompat dua sekaligus — terlihat jelas pada jam
     * sebesar layar kiosk.
     */
    jeda = setInterval(detak, 250)
  })

  onBeforeUnmount(() => clearInterval(jeda))

  const dua = (n) => String(n).padStart(2, '0')

  /** "07:24" — jam dan menit saja, supaya tidak bergoyang tiap detik. */
  const jam = computed(() => `${dua(sekarang.value.getHours())}:${dua(sekarang.value.getMinutes())}`)

  /** "31" — detik, ditempatkan terpisah dan lebih kecil. */
  const detik = computed(() => dua(sekarang.value.getSeconds()))

  /** "Jumat, 5 September 2026" */
  const tanggalPanjang = computed(() =>
    sekarang.value.toLocaleDateString('id-ID', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }),
  )

  /** Jam server saat ini dalam bentuk ISO, dikirim bersama tap. */
  const waktuIso = () => new Date(Date.now() - selisih.value).toISOString()

  /** Jam server saat ini sebagai HH.MM, untuk kartu hasil tap. */
  const jamSingkat = () =>
    new Date(Date.now() - selisih.value).toLocaleTimeString('id-ID', {
      hour: '2-digit',
      minute: '2-digit',
    })

  return { jam, detik, tanggalPanjang, sekarang, setel, waktuIso, jamSingkat }
}
