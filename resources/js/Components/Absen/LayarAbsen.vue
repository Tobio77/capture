<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
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

  /*
   * Alamat endpoint titik absen. Layar yang sama melayani perangkat absen
   * (dipagari device token) dan layar absen umum di peramban admin (dipagari
   * sesi admin), sehingga jalurnya diserahkan pemanggil alih-alih dipatok.
   */
  endpoint: { type: Object, required: true },

  // Baris keterangan di bawah nama event: titik absen mana yang melayani.
  titik: { type: String, default: '' },

  // Ditampilkan sebagai judul ketika belum ada event yang dibuka.
  judul_kosong: { type: String, default: 'Tidak ada event aktif' },
  metode: { type: Object, required: true },
  ambang_kecocokan_wajah: { type: Number, required: true },
  kompresi: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
})

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
    const jawaban = await fetch(props.endpoint.presensi, { headers: { Accept: 'application/json' } })

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
    const jawaban = await fetch(props.endpoint.identifikasi, {
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

      jenis: jenisTap,
      metode: isi.data.metode,
    }

    /*
     * FR-TAP-05 (revisi S28a): jenis yang sudah tercatat ditolak. Server
     * memberitahukannya sejak identifikasi, sehingga tap berhenti di sini —
     * kamera tidak menyala hanya untuk berakhir ditolak.
     */
    const tercatat = isi.data.sudah_absen?.[jenisTap]

    if (tercatat) {
      sudahAbsen(`Sudah absen ${jenisTap} pukul ${tercatat}.`)

      return
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
 * Ketika admin mematikan metode wajah pada Setting Absen, yang dilewati hanya
 * langkah PENCOCOKANNYA — identitas sudah dipastikan kartu atau NIP yang
 * di-tap. Kamera tetap menyala dan fotonya tetap diambil serta disimpan
 * sebagai bukti kehadiran (revisi FR-SET-01, S28a).
 */
async function verifikasiWajah(data) {
  let skor = null

  if (props.metode.wajah) {
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

    skor = hasilVerifikasi.skor
  }

  // Foto hasil capture sudah disusutkan sesuai preset Setting Absen sebelum
  // dikirim, sehingga yang melintasi jaringan sudah berukuran akhir.
  await simpanAbsen(data, panel.value?.ambilFoto(props.kompresi), skor)
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

    /*
     * Kehadiran jenis ini sudah tercatat. Bukan kegagalan yang perlu diulang:
     * pegawai justru sudah aman, dan yang dibutuhkannya hanya kepastian pukul
     * berapa. Daftar presensi ikut disegarkan supaya namanya terlihat.
     */
    if (!isi.success && isi.code === 'SUDAH_ABSEN') {
      if (isi.data?.daftar_presensi) {
        presensi.value = isi.data.daftar_presensi
      }

      sudahAbsen(isi.message)

      return
    }

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
  const jawaban = await fetch(props.endpoint.simpan, {
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

/**
 * Kehadiran jenis ini sudah tercatat sebelumnya.
 *
 * Dipisahkan dari `gagalkan()` karena maknanya berbeda bagi orang yang berdiri
 * di depan layar: bukan "coba lagi", melainkan "Anda sudah aman". Warnanya pun
 * berbeda — biru keterangan, bukan amber peringatan.
 */
function sudahAbsen(teks) {
  pesan.value = teks
  tahap.value = 'sudah'
  pulihkan()
}

/** Kembali menunggu tap berikutnya setelah hasil sempat terbaca. */
function pulihkan() {
  jedaPulih = setTimeout(() => {
    tahap.value = entryDibuka.value ? 'menunggu_tap' : 'menunggu_event'
    panel.value?.rebutFokus()
  }, 4000)
}

</script>

<template>
  <!--
    Tema terang, bukan gelap. Titik absen berdiri di aula dan lorong yang
    terang benderang saat apel atau senam pagi; layar gelap di sana memantul
    dan sulit dibaca dari jarak berdiri.
  -->
  <div class="flex min-h-screen flex-col bg-kertas text-utama">
    <header class="border-b border-sidebar-garis bg-sidebar text-sidebar-teks">
      <div
        class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6"
      >
        <div class="flex flex-wrap items-center gap-3 sm:gap-5">
          <div class="min-w-0">
            <p class="truncate font-display text-lg font-semibold">
              {{ eventAktif?.nama ?? judul_kosong }}
            </p>
            <p v-if="titik" class="truncate text-sm text-sidebar-redup">{{ titik }}</p>
          </div>

          <span
            class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium transition-colors duration-300"
            :class="
              entryDibuka ? 'bg-emerald-500/20 text-emerald-200' : 'bg-white/10 text-sidebar-redup'
            "
          >
            <span
              class="h-2 w-2 rounded-full"
              :class="entryDibuka ? 'animate-pulse bg-emerald-400' : 'bg-sidebar-redup'"
            ></span>
            {{ entryDibuka ? 'Entry Dibuka' : 'Entry Ditutup' }}
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-3 sm:gap-4">
          <!-- NFR-05: absen yang tertahan jaringan tetap terlihat petugas. -->
          <span
            v-if="antrian.length > 0"
            class="inline-flex items-center gap-2 rounded-full bg-amber-500/20 px-3 py-1 text-xs font-medium text-amber-200"
            :title="`${antrian.length} absen tersimpan di perangkat dan menunggu jaringan pulih`"
          >
            <span class="h-2 w-2 animate-pulse rounded-full bg-amber-300"></span>
            {{ antrian.length }} menunggu terkirim
          </span>

          <p v-if="eventAktif" class="text-right text-xs text-sidebar-redup">
            Mulai {{ eventAktif.jam_mulai }} · toleransi {{ eventAktif.toleransi_menit }} menit
          </p>

          <!-- Aksi khas tiap titik absen: lepas perangkat, pilih unit, kembali. -->
          <slot name="aksi" />
        </div>
      </div>
    </header>

    <!--
      Kolom kiri memuat panel entry beserta pratinjau kameranya; sisanya milik
      Daftar e-Presensi, yang justru paling sering dibaca petugas selama apel
      berlangsung.
    -->
    <main
      class="mx-auto grid w-full max-w-[1600px] flex-1 gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,480px)_minmax(0,1fr)]"
    >
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
