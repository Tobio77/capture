<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import PanelEntry from '@/Components/Kiosk/PanelEntry.vue'
import PanelPresensi from '@/Components/Kiosk/PanelPresensi.vue'
import { useVerifikasiWajah } from '@/Composables/useVerifikasiWajah'
import { useAntrianAbsen } from '@/Composables/useAntrianAbsen'

/**
 * Layar Utama Kiosk (UIUX §4.2) — header status event dan dua panel yang
 * selalu terlihat sepanjang sesi.
 *
 * Tahap yang dikenali: menunggu_event, menunggu_tap, memindai, berhasil,
 * gagal. Verifikasi wajah (S15) dan penyimpanan absen (S16) yang kelak
 * menggerakkan tahap memindai/berhasil/gagal.
 */

const props = defineProps({
  event: { type: Object, default: null },
  metode: { type: Object, required: true },
  ambang_kecocokan_wajah: { type: Number, required: true },
  kompresi: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
})

const page = usePage()
const kiosk = computed(() => page.props.kiosk)

const jenis = ref('datang')
const hasil = ref(null)
const presensi = ref(props.daftar_presensi)
const pesan = ref(null)
const panel = ref(null)

// Keadaan event disimpan lokal, bukan dibaca langsung dari prop, karena
// pembaruan berkala dapat mengubahnya di tengah sesi (FR-TAP-08, FR-EVT-04).
const eventAktif = ref(props.event)
const entryDibuka = computed(() => eventAktif.value !== null)
const tahap = ref(entryDibuka.value ? 'menunggu_tap' : 'menunggu_event')

const { siapkanModel, verifikasi } = useVerifikasiWajah()
const { antrian, antrikan, kirimUlang } = useAntrianAbsen()

let jedaPulih = null

/*
 * Jeda penarikan Daftar e-Presensi. 10 detik cukup terasa langsung bagi
 * pegawai yang menunggu namanya muncul, sementara satu kiosk hanya membebani
 * server enam permintaan per menit.
 */
const JEDA_TARIK_MS = 10000

let jedaTarik = null

onMounted(() => {
  /*
   * Model face-api berukuran ~6,8 MB dan butuh beberapa detik untuk dimuat.
   * Pemuatannya dimulai begitu layar terbuka — bukan saat tap pertama —
   * supaya pegawai pertama tidak menunggu lebih lama daripada yang
   * berikutnya (NFR-01: tap hingga hasil rata-rata di bawah 3 detik).
   */
  if (props.metode.wajah && entryDibuka.value) {
    siapkanModel().catch(() => {
      // Kegagalan dilaporkan saat tap, bukan sebagai peringatan yang
      // menghalangi layar sebelum ada yang mencoba absen.
    })
  }

  jedaTarik = setInterval(tarikPresensi, JEDA_TARIK_MS)
})

onBeforeUnmount(() => {
  clearInterval(jedaTarik)
  clearTimeout(jedaPulih)
})

/**
 * Tarik daftar terkini beserta keadaan event (FR-TAP-08).
 *
 * Dilewati selagi tap sedang diproses supaya hasil yang baru saja tampil
 * tidak tertimpa di tengah pembacaan pegawai.
 */
async function tarikPresensi() {
  if (tahap.value === 'memindai') return

  // Antrian luring dikosongkan lebih dulu agar daftar yang ditarik sesudahnya
  // sudah memuat absen yang baru saja tersusul.
  await kosongkanAntrian()

  try {
    const jawaban = await fetch('/kiosk/presensi', { headers: { Accept: 'application/json' } })

    if (!jawaban.ok) return

    const isi = await jawaban.json()

    presensi.value = isi.daftar_presensi
    eventAktif.value = isi.event

    // Entry yang ditutup admin langsung mengunci kolom tap, tanpa perlu
    // layar kiosk dimuat ulang.
    if (isi.event === null && tahap.value === 'menunggu_tap') {
      tahap.value = 'menunggu_event'
    }

    if (isi.event !== null && tahap.value === 'menunggu_event') {
      tahap.value = 'menunggu_tap'
    }
  } catch {
    // Jaringan sedang bermasalah; percobaan berikutnya menyusul sendiri.
  }
}

/**
 * Tap dari kolom ID — UID kartu maupun NIP yang diketik, keduanya dikirim
 * apa adanya karena hanya server yang tahu bedanya.
 *
 * Untuk sementara berhenti pada pengenalan identitas: verifikasi wajah
 * menyusul pada S15 dan penyimpanan absennya pada S16.
 */
async function tangkapTap({ id_card, jenis: jenisTap }) {
  clearTimeout(jedaPulih)

  tahap.value = 'memindai'
  hasil.value = null
  pesan.value = null

  try {
    const jawaban = await fetch('/kiosk/tap/identifikasi', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
      },
      body: JSON.stringify({ id_card }),
    })

    const isi = await jawaban.json()

    if (!isi.success) {
      gagalkan(isi.message ?? 'Tap tidak dapat diproses.')

      return
    }

    hasil.value = {
      nip: isi.data.nip,
      nama: isi.data.nama,
      unit_kerja: isi.data.unit_kerja_nama,
      jam: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),

      // Dibawa untuk S16: jenis yang dipilih pegawai dan asal masukannya.
      jenis: jenisTap,
      metode: isi.data.metode,
    }

    await verifikasiWajah(isi.data)
  } catch {
    gagalkan('Perangkat tidak dapat menghubungi server.')
  }
}

/**
 * Verifikasi wajah 1:1 terhadap embedding referensi pegawai yang di-tap
 * (FR-TAP-04, FR-TAP-06).
 *
 * Dilewati bila admin mematikan metode wajah pada Setting Absen — identitas
 * sudah dipastikan oleh kartu atau NIP yang di-tap.
 */
async function verifikasiWajah(data) {
  if (!props.metode.wajah) {
    tahap.value = 'berhasil'
    pulihkan()

    return
  }

  const hasilVerifikasi = await verifikasi(
    panel.value?.elemenVideo(),
    data.embedding_wajah,
    props.ambang_kecocokan_wajah,
  )

  if (hasilVerifikasi.galat) {
    gagalkan(hasilVerifikasi.galat)

    return
  }

  hasil.value = { ...hasil.value, skor: hasilVerifikasi.skor }

  if (!hasilVerifikasi.cocok) {
    // FR-TAP-06: kehadiran tidak dicatat, pegawai dipersilakan mengulang tap.
    gagalkan(
      `Wajah tidak cocok (${hasilVerifikasi.skor}%, ambang ${props.ambang_kecocokan_wajah}%). Silakan ulangi tap.`,
    )

    return
  }

  // Foto hasil capture sudah disusutkan sesuai preset Setting Absen sebelum
  // dikirim, sehingga yang melintasi jaringan sudah berukuran akhir.
  await simpanAbsen(data, panel.value?.ambilFoto(props.kompresi), hasilVerifikasi.skor)
}

/**
 * Kirim hasil absen ke server (FR-TAP-05).
 *
 * Server memeriksa ulang seluruh syaratnya — event, pegawai, dan ambang skor —
 * sehingga jawaban gagal di sini tetap berarti kehadiran tidak dicatat.
 */
async function simpanAbsen(data, foto, skor) {
  const muatan = {
    id_card: data.nip,
    jenis: hasil.value.jenis,
    metode: hasil.value.metode,
    skor,
    foto,

    // Waktu tap sesungguhnya ikut dikirim, supaya absen yang tertahan
    // antrian luring tetap tercatat pada jamnya (NFR-05).
    waktu_tap: new Date().toISOString(),
  }

  try {
    const isi = await kirimMuatan(muatan)

    if (!isi.success) {
      gagalkan(isi.message ?? 'Absen gagal disimpan.')

      return
    }

    presensi.value = isi.data.daftar_presensi
    hasil.value = { ...hasil.value, jam: isi.data.waktu, ketepatan: isi.data.status_ketepatan }

    tahap.value = 'berhasil'
    pulihkan()
  } catch {
    /*
     * NFR-05: jaringan putus di tengah apel tidak boleh menghanguskan absen
     * yang wajahnya sudah cocok. Simpan di antrian lokal dan kirim ulang
     * sendiri begitu jaringan pulih.
     */
    if (antrikan(muatan)) {
      hasil.value = { ...hasil.value, tertunda: true }
      tahap.value = 'berhasil'
      pesan.value = null
      pulihkan()

      return
    }

    gagalkan('Antrian luring penuh. Hubungi admin sebelum melanjutkan absen.')
  }
}

/** Satu perjalanan ke endpoint simpan absen. Melempar bila jaringan gagal. */
async function kirimMuatan(muatan) {
  const jawaban = await fetch('/kiosk/absen', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
    },
    body: JSON.stringify(muatan),
  })

  // 5xx berarti server sedang bermasalah, bukan kiriman yang keliru —
  // diperlakukan sebagai kegagalan jaringan supaya masuk antrian.
  if (jawaban.status >= 500) {
    throw new Error('server')
  }

  return jawaban.json()
}

/**
 * Kirim ulang antrian luring. Dipanggil pada setiap penarikan berkala,
 * sehingga pemulihannya terjadi sendiri tanpa campur tangan petugas.
 */
async function kosongkanAntrian() {
  await kirimUlang(async (muatan) => {
    try {
      const isi = await kirimMuatan(muatan)

      // Ditolak karena alasan yang tidak akan berubah bila diulang.
      return isi.success ? 'berhasil' : 'ditolak'
    } catch {
      return 'tunda'
    }
  })
}

function gagalkan(teks) {
  pesan.value = teks
  tahap.value = 'gagal'
  pulihkan()
}

/** Kembali menunggu tap berikutnya setelah hasil sempat terbaca. */
function pulihkan() {
  jedaPulih = setTimeout(() => {
    tahap.value = entryDibuka.value ? 'menunggu_tap' : 'menunggu_event'
    panel.value?.rebutFokus()
  }, 4000)
}

const lepas = () => {
  if (window.confirm('Lepaskan perangkat ini dari titik absen? Perangkat harus diaktifkan ulang dengan kode baru.')) {
    router.post('/kiosk/lepas')
  }
}
</script>

<template>
  <Head title="Layar Perangkat Absen" />

  <div class="flex min-h-screen flex-col bg-slate-900 text-slate-100">
    <header class="border-b border-white/10 bg-navy-700">
      <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-4 px-6 py-4">
        <div class="flex items-center gap-5">
          <div>
            <p class="font-display text-lg font-semibold text-white">
              {{ eventAktif?.nama ?? 'Tidak ada event aktif' }}
            </p>
            <p class="text-sm text-navy-200">
              {{ kiosk.nama_titik }} · {{ kiosk.unit_kerja?.nama }}
            </p>
          </div>

          <span
            class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
            :class="entryDibuka ? 'bg-emerald-600/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400'"
          >
            <span class="h-2 w-2 rounded-full" :class="entryDibuka ? 'bg-emerald-500' : 'bg-slate-500'"></span>
            {{ entryDibuka ? 'Entry Dibuka' : 'Entry Ditutup' }}
          </span>
        </div>

        <div class="flex items-center gap-4">
          <!-- NFR-05: absen yang tertahan jaringan tetap terlihat petugas. -->
          <span
            v-if="antrian.length > 0"
            class="inline-flex items-center gap-2 rounded-full bg-amber-500/15 px-3 py-1 text-xs font-medium text-amber-300"
            :title="`${antrian.length} absen tersimpan di perangkat dan menunggu jaringan pulih`"
          >
            <span class="h-2 w-2 animate-pulse rounded-full bg-amber-400"></span>
            {{ antrian.length }} menunggu terkirim
          </span>

          <p v-if="eventAktif" class="text-right text-xs text-navy-200">
            Mulai {{ eventAktif.jam_mulai }} · toleransi {{ eventAktif.toleransi_menit }} menit
          </p>
          <button
            type="button"
            class="rounded-md border border-white/20 px-3 py-1.5 text-xs font-medium text-navy-100 transition hover:bg-white/10 hover:text-white"
            @click="lepas"
          >
            Lepas Perangkat
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto grid w-full max-w-[1600px] flex-1 gap-5 px-6 py-6 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
      <PanelEntry
        ref="panel"
        v-model:jenis="jenis"
        :tahap="tahap"
        :pesan="pesan"
        :hasil="hasil"
        :metode="metode"
        :aktif="entryDibuka"
        @tap="tangkapTap"
      />

      <PanelPresensi :daftar="presensi" :event="eventAktif" />
    </main>
  </div>
</template>
