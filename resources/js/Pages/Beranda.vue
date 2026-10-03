<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import TandaAbsen from '@/Components/UI/TandaAbsen.vue'
import SaklarTema from '@/Components/UI/SaklarTema.vue'
import { useJamServer } from '@/Composables/useJamServer'
import { useMiring } from '@/Composables/useMiring'

/**
 * Halaman depan titik absen — "Pelat Bengkel" (S32).
 *
 * Layar ini menempel di pintu masuk kantor dinas atau ruang praktik BLK, dan
 * dilihat orang yang sama setiap pagi. Setelah hari ketiga tidak ada lagi yang
 * membaca sambutan; yang dicari hanya dua hal — pukul berapa sekarang, dan
 * tombol mana yang ditekan. Karena itu jam tetap elemen terbesar, dan dua
 * pilihan absen tetap baris tekan selebar layar yang bertumpuk: layar sentuh
 * ini dioperasikan sambil berdiri, kerap dengan satu tangan memegang kartu
 * identitas, dan sasaran selebar layar jauh lebih mudah dikenai daripada dua
 * kartu yang berbagi lebar.
 *
 * Yang berubah pada S32 adalah susunannya. Versi sebelumnya menumpuk semuanya
 * di poros tengah — jam, tanggal, status, dua tombol — sehingga layarnya
 * benar dan tenang tetapi datar: tidak ada bidang, tidak ada kedalaman, dan
 * tidak ada apa pun yang menyatakan bahwa ini papan milik dinas
 * ketenagakerjaan alih-alih aplikasi jam mana pun.
 *
 * Sekarang ada tiga bidang. Pelat navy bergradasi menempati bagian atas dan
 * memuat identitas serta tanggal; tanahnya sage; dan kartu jam MELANGGAR
 * perbatasan keduanya, digeser dari poros tengah. Perbatasan yang dilanggar
 * itulah yang memberi kedalaman — bukan bayangan yang ditebalkan.
 *
 * Motifnya deret garis ukur, diambil dari irisan tiga kejuruan BLK: meteran
 * penjahit, mistar las, sigmat otomotif. Ketiganya satu primitif dengan
 * piringan jam, sehingga motif itu menyambungkan jam raksasa di tengah layar
 * dengan pekerjaan yang dilatih di gedung tempat layarnya menempel — tanpa
 * satu pun ikon tempelan.
 *
 * Gerak: satu koreografi masuk (lihat tema.css), lalu diam. Sesudahnya tidak
 * ada yang bergerak selain detik. Itulah yang membuatnya terbaca sebagai
 * papan yang hidup, bukan halaman yang gelisah.
 */

const props = defineProps({
  perangkat: { type: Object, default: null },

  /*
   * Kegiatan yang sedang dibuka, atau null. Satu objek, bukan daftar: sejak
   * S49 event berlaku bagi seluruh dinas, sehingga hanya boleh ada satu yang
   * aktif pada satu waktu (FR-EVT-06) — dan perangkat yang sudah dikenali
   * langsung melayaninya tanpa mengetik kode apa pun lagi.
   */
  event_aktif: { type: Object, default: null },

  // Sakelar fitur di Setting Absen; yang mati tampil terkunci.
  absen_umum_aktif: { type: Boolean, required: true },
  absen_event_aktif: { type: Boolean, default: true },
  mode_pendaftaran: { type: Boolean, required: true },
  waktu_server: { type: String, default: null },
  jam_masuk: { type: String, default: '07:30' },
  toleransi_menit: { type: Number, default: 15 },
})

const page = usePage()
const pengguna = computed(() => page.props.auth?.pengguna ?? null)
const sukses = computed(() => page.props.flash?.sukses)
const gagal = computed(() => page.props.flash?.gagal)

const { jam, detik, tanggalPanjang, sekarang } = useJamServer(props.waktu_server)

const perangkatAktif = computed(() => props.perangkat !== null)
// Kegiatan yang dibuka tidak berarti apa-apa bagi layar ini selama fitur
// Absen Event dinonaktifkan admin.
const adaEvent = computed(() => props.absen_event_aktif && props.event_aktif !== null)

const namaPerangkat = computed(() => props.perangkat?.nama_titik ?? null)

/*
 * Unit kerja perangkat — keterangan kedua di bawah namanya, dan satu-satunya
 * yang benar-benar ditanyakan orang di depan layar ini ("mesin ini melayani
 * unit mana?").
 *
 * Penanda "Ad-hoc" yang dulu berdampingan di sini sudah tidak ada. Sejak S49
 * hampir setiap perangkat masuk lewat kode unit kerja dan karenanya bertanda
 * ad-hoc; penanda yang melekat pada semua orang berhenti membedakan apa pun.
 */
const unitPerangkat = computed(() => props.perangkat?.unit_kerja?.nama ?? null)

/*
 * Baris konteks di bawah tanggal. Angka jam sebesar itu perlu konsekuensi:
 * yang membacanya harus langsung tahu ia masih tepat waktu atau sudah lewat.
 * Ketika perangkat melayani sebuah kegiatan, jam kegiatan itulah yang berlaku
 * baginya — bukan jam masuk harian.
 */
const konteks = computed(() => {
  if (adaEvent.value) {
    return `${props.event_aktif.nama} · mulai ${props.event_aktif.jam_mulai}`
  }

  return `Jam masuk ${props.jam_masuk.replace(':', '.')} · toleransi ${props.toleransi_menit} menit`
})

/*
 * Batas tepat waktu hari ini: jam masuk ditambah toleransi (FR-TAP-07).
 * Selama perangkat melayani sebuah kegiatan, jam kegiatan itulah yang berlaku
 * baginya — bukan jam masuk harian.
 */
const batas = computed(() => {
  const [jamMulai, toleransi] = adaEvent.value
    ? [props.event_aktif.jam_mulai, props.event_aktif.toleransi_menit]
    : [props.jam_masuk, props.toleransi_menit]

  const [j, m] = jamMulai.split(':').map(Number)
  const waktu = new Date(sekarang.value)

  waktu.setHours(j, m + Number(toleransi), 0, 0)

  return waktu
})

const masihTepat = computed(() => sekarang.value <= batas.value)

const batasTertulis = computed(() =>
  batas.value.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
)

/*
 * Kepingnya kini berdiri di atas pelat navy, bukan di atas sage, sehingga
 * warnanya harus versi terang dari keluarga yang sama: emerald dan amber
 * pekat (#059669, #B45309) tidak terbaca di atas latar segelap itu. Yang
 * dipertahankan justru bagian yang berarti — keduanya tetap DATAR, tanpa
 * gradasi. Begitu warna semantik ikut dihias, ia berhenti menyatakan apa pun.
 */
/** Menit yang sudah lewat dari batas tepat waktu; negatif berarti belum. */
const menitLewat = computed(() =>
  Math.round((sekarang.value.getTime() - batas.value.getTime()) / 60000),
)

/**
 * Ambang "terlambat parah".
 *
 * Satu jam, bukan lima menit: terlambat sepuluh menit adalah kejadian sehari-
 * hari yang cukup ditandai amber, sementara terlambat lebih dari sejam adalah
 * hal lain — dan menandai keduanya sama membuat yang kedua tenggelam.
 */
const AMBANG_PARAH = 60

const status = computed(() => {
  if (masihTepat.value) {
    return {
      kelas: 'bg-emerald-400/15 text-emerald-200',
      titik: 'bg-emerald-300',
      teks: `Masih tepat waktu — batas ${batasTertulis.value}`,
    }
  }

  const lewat = menitLewat.value

  /*
   * ROSE untuk terlambat parah, amber untuk terlambat biasa. Dua tingkat
   * keseriusan yang memakai satu warna berarti keduanya berhenti berarti.
   */
  if (lewat >= AMBANG_PARAH) {
    const jam = Math.floor(lewat / 60)
    const menit = lewat % 60

    return {
      kelas: 'bg-rose-400/15 text-rose-200',
      titik: 'bg-rose-300',
      teks: `Lewat ${jam} jam ${menit} menit dari batas ${batasTertulis.value}`,
    }
  }

  return {
    kelas: 'bg-amber-400/15 text-amber-200',
    titik: 'bg-amber-300',
    teks: `Lewat ${lewat} menit dari batas ${batasTertulis.value} — tercatat terlambat`,
  }
})

/*
 * Kemiringan 3D pada dua baris pilihan. Satu pemanggilan per baris karena
 * masing-masing punya kotak sendiri; sudutnya dihitung relatif terhadap kotak
 * itu, bukan terhadap halaman.
 */
const pitaUmum = useMiring()
const pitaEvent = useMiring()

/*
 * Tiga keterangan bantuan di kaki halaman.
 *
 * Ditulis di sini, bukan di template, karena isinya bergantung keadaan: yang
 * ketiga menyebut unit kerja perangkat ini ketika ia sudah diaktifkan, dan
 * kalimat umum ketika belum.
 */
const bantuanSingkat = computed(() => [
  {
    ikon: 'kartu',
    nada: 'nada-teal',
    judul: 'Kartu tidak terbaca?',
    isi: 'Ketik NIP secara manual pada kolom yang sama, lalu tekan Enter.',
  },
  {
    ikon: 'wajah',
    nada: 'nada-langit',
    judul: 'Wajah tidak cocok?',
    isi: 'Perbaiki pencahayaan dan hadapkan wajah lurus ke kamera, lalu ulangi.',
  },
  {
    ikon: 'info',
    nada: 'nada-biru',
    judul: 'Masih gagal?',
    isi: unitPerangkat.value
      ? `Hubungi admin ${unitPerangkat.value} untuk dicatat manual.`
      : 'Hubungi admin unit kerja Anda untuk dicatat manual.',
  },
])

function pilihAbsenUmum() {
  router.get(perangkatAktif.value ? '/kiosk/umum' : '/kiosk/aktivasi')
}

/*
 * Tidak ada lagi langkah kedua di sini.
 *
 * Sampai S48, menekan Absen Event membuka panel berisi daftar kegiatan dan
 * kolom kode yang harus ditukarkan lebih dahulu. Kode kini menempel pada unit
 * kerja dan sudah diketikkan sekali di layar masuk perangkat, sehingga yang
 * tersisa hanyalah dua kemungkinan: ada kegiatan yang dibuka, atau tidak.
 */
function pilihAbsenEvent() {
  if (!perangkatAktif.value) {
    router.get('/kiosk/aktivasi')

    return
  }

  if (adaEvent.value) {
    router.get('/kiosk/event')
  }
}

function lepasPerangkat() {
  if (
    window.confirm(
      'Lepaskan perangkat ini dari titik absen? Perangkat harus dihubungkan ulang dengan kode unit kerja.',
    )
  ) {
    router.post('/kiosk/lepas')
  }
}
</script>

<template>
  <Head title="Titik Absen" />

  <!--
    SATU WADAH untuk seluruh halaman.

    Sebelumnya pelat navy melebar dari tepi ke tepi sementara isinya duduk di
    dalam wadah selebar 5xl — dua sistem tepi yang berbeda pada satu layar,
    dan kartu di bawahnya karena itu tampak tidak berhubungan dengan bidang di
    atasnya. Kini semuanya berbagi wadah, jarak tepi, dan lengkung sudut yang
    sama.
  -->
  <div class="flex min-h-screen flex-col bg-kertas text-utama">
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col px-4 py-4 sm:px-6 sm:py-5">
      <!--
        PELAT NAVY — kini sebuah objek yang berdiri di atas sage, bukan pita
        yang memotong layar. Sudutnya membulat sebesar kartu di bawahnya.
      -->
      <div
        class="pelat-navy tahap tahap-pelat relative overflow-hidden rounded-3xl"
        style="--lama: 380ms"
      >
        <!--
          Tiga bola cahaya yang mengapung pelan, bukan puluhan partikel.

          Seluruhnya CSS: yang beranimasi hanya `transform`, pada elemen yang
          buramnya tetap — sehingga peramban merasterkannya sekali lalu tinggal
          memindahkannya di GPU. Tidak ada pustaka partikel, tidak ada kanvas,
          tidak ada yang memicu tata letak dihitung ulang.
        -->
        <div class="bola bola-1" aria-hidden="true"></div>
        <div class="bola bola-2" aria-hidden="true"></div>
        <div class="bola bola-3" aria-hidden="true"></div>

        <div class="relative px-5 pb-7 pt-4 sm:px-7 sm:pb-8">
          <!--
            Strip identitas. Sengaja setipis mungkin: ia menjawab pertanyaan
            yang hanya ditanyakan sekali ("mesin ini melayani unit mana?") dan
            tidak boleh bersaing dengan jam.
          -->
          <header
            class="tahap tahap-redup flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-white/10 pb-3"
            style="--tunda: 180ms"
          >
            <p class="flex min-w-0 items-center gap-2.5">
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/15 text-sidebar-teks"
              >
                <Ikon nama="absen" ukuran="h-3.5 w-3.5" />
              </span>
              <span class="min-w-0 leading-tight">
                <span class="block truncate font-display text-sm font-semibold text-sidebar-teks">
                  Capture
                </span>
                <span class="block truncate text-[0.6875rem] text-sidebar-redup">
                  Disnakertrans Provinsi Jawa Timur
                </span>
              </span>
            </p>

            <div class="flex min-w-0 items-center gap-3">
              <p class="flex min-w-0 items-center gap-2 text-xs text-sidebar-redup">
                <span v-if="perangkatAktif" class="relative flex h-2 w-2 shrink-0">
                  <span
                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-60"
                  ></span>
                  <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-300"></span>
                </span>
                <span v-else class="h-2 w-2 shrink-0 rounded-full bg-sidebar-redup"></span>

                <span v-if="!perangkatAktif" class="truncate font-medium">
                  Perangkat belum dihubungkan
                </span>

                <span v-else class="min-w-0 leading-tight">
                  <span class="block truncate font-medium text-sidebar-teks">
                    {{ namaPerangkat }}
                  </span>
                  <span v-if="unitPerangkat" class="block truncate text-[0.6875rem]">
                    {{ unitPerangkat }}
                  </span>
                </span>
              </p>

              <SaklarTema />
            </div>
          </header>

          <!--
            HERO sebagai satu blok.

            Sebelumnya tanggal dan status duduk di sudut kanan atas sementara
            jam berada di kiri bawah — dua sudut berlawanan, dan mata harus
            menyeberangi kanvas kosong untuk menghubungkan keduanya. Kini
            keduanya bersebelahan: tanggal MASUK ke dalam kartu jam, dan status
            berdiri tepat di sampingnya sebagai vonis atas angka itu.
          -->
          <div class="mt-6 flex flex-col gap-5 sm:mt-7 md:flex-row md:items-stretch md:gap-7">
            <!--
              Kartu jam. Kaca di atas navy — di sinilah buramnya paling
              terbaca, karena yang di belakangnya bergradasi dan bergerak.
            -->
            <div
              class="kartu-jam tahap tahap-kartu relative overflow-hidden px-6 py-6 sm:px-8"
              style="--tunda: 300ms; --lama: 560ms"
            >
              <div class="skala-tegak absolute bottom-6 left-0 top-6 w-4" aria-hidden="true"></div>

              <p class="flex items-center gap-4 pl-7 font-display tabular-nums">
                <span
                  class="font-bold leading-[0.85] tracking-[-0.05em]"
                  style="font-size: clamp(4.5rem, 13vw, 8.5rem)"
                >
                  {{ jam }}
                </span>
                <!--
                  Detik memakai tinta PENUH, bukan `text-redup`. Kartu ini
                  kaca, dan latar di belakangnya berbeda jauh antar-sisinya;
                  teks redup hanya mencapai 3,2:1 di bagian tergelapnya. Yang
                  membedakannya dari jam adalah ukuran dan bobot, dua hal yang
                  tidak bergantung pada apa pun di belakangnya.
                -->
                <span class="font-medium leading-none" style="font-size: clamp(1.25rem, 3vw, 2rem)">
                  {{ detik }}
                </span>
              </p>

              <p
                class="mt-3 border-t border-garis pl-7 pt-3 font-display text-base font-medium text-utama sm:text-lg"
              >
                {{ tanggalPanjang }}
              </p>
            </div>

            <!--
              Kolom pendamping. Ia yang membuat kartu jam tidak berdiri
              sendirian di kanvas navy yang luas, dan isinya memang milik jam
              itu: sampai pukul berapa masih dihitung tepat waktu.
            -->
            <div class="flex min-w-0 flex-1 flex-col justify-center gap-3">
              <p
                class="tahap tahap-redup inline-flex w-fit items-center gap-2 rounded-full px-3.5 py-1.5 text-[0.8125rem] font-medium"
                :class="status.kelas"
                style="--tunda: 780ms"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="status.titik"></span>
                {{ status.teks }}
              </p>

              <p class="tahap tahap-redup text-sm text-sidebar-redup" style="--tunda: 820ms">
                {{ konteks }}
              </p>

              <p
                v-if="sukses"
                class="rounded-xl bg-emerald-400/15 px-3.5 py-2.5 text-sm text-emerald-200"
              >
                {{ sukses }}
              </p>

              <p v-if="gagal" class="rounded-xl bg-rose-400/15 px-3.5 py-2.5 text-sm text-rose-200">
                {{ gagal }}
              </p>
            </div>
          </div>
        </div>

        <!--
          Deret garis ukur di tepi bawah pelat: motif utama halaman ini,
          diambil dari irisan tiga kejuruan BLK — meteran penjahit, mistar las,
          sigmat otomotif — yang ternyata satu primitif dengan piringan jam.
        -->
        <div
          class="skala-ukur skala-terang tahap tahap-skala absolute inset-x-0 bottom-0 h-3"
          style="--tunda: 200ms; --lama: 560ms"
          aria-hidden="true"
        ></div>
      </div>

      <!--
        Dua pilihan. Tetap selebar wadah dan bertumpuk — ergonomi layar sentuh
        yang dioperasikan sambil berdiri tidak diutak-atik. Yang dibedakan
        BOBOTNYA: yang utama lebih tinggi dan bergradasi, yang kedua lebih
        pendek dan bergaris.
      -->
      <div class="mt-4 flex flex-col gap-3 sm:mt-5">
        <button
          ref="pitaUmum"
          type="button"
          :disabled="!absen_umum_aktif"
          class="pita-utama kilau tautan-aksi tahap tahap-pita group flex min-h-[6.5rem] w-full items-center gap-5 px-6 py-5 text-left active:scale-[0.995] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 sm:px-8"
          style="--tunda: 520ms"
          @click="pilihAbsenUmum"
        >
          <TandaAbsen jenis="umum" ukuran="h-12 w-12 shrink-0 opacity-95" />

          <span class="min-w-0 flex-1">
            <span class="block font-display text-2xl font-semibold">Absen Umum</span>
            <span class="mt-0.5 flex items-center gap-1.5 text-sm text-white">
              <Ikon v-if="!absen_umum_aktif" nama="kunci" ukuran="h-3.5 w-3.5 shrink-0" />
              {{ absen_umum_aktif ? 'Datang dan pulang harian' : 'Sedang dinonaktifkan admin' }}
            </span>
          </span>

          <Ikon
            nama="kanan"
            ukuran="h-7 w-7 shrink-0"
            class="transition-transform duration-200 group-hover:translate-x-1"
          />
        </button>

        <!--
          Absen Event DIMATIKAN ketika tidak ada kegiatan yang dibuka, bukan
          disembunyikan. Petugas yang mencarinya harus menemukan jawabannya di
          tempat ia mencari — tombol yang hilang hanya membuatnya mengira
          perangkatnya rusak.
        -->
        <button
          ref="pitaEvent"
          type="button"
          :disabled="!absen_event_aktif || (perangkatAktif && !adaEvent)"
          class="pita-kedua kilau tautan-aksi tahap tahap-pita group flex min-h-[5.5rem] w-full items-center gap-5 px-6 py-4 text-left active:scale-[0.995] disabled:cursor-not-allowed disabled:opacity-60 disabled:active:scale-100 sm:px-8"
          style="--tunda: 620ms"
          @click="pilihAbsenEvent"
        >
          <TandaAbsen jenis="event" ukuran="h-11 w-11 shrink-0 text-aksen-teks" />

          <span class="min-w-0 flex-1">
            <span class="block font-display text-xl font-semibold">Absen Event</span>
            <span class="mt-0.5 flex items-center gap-1.5 text-sm text-sekunder">
              <Ikon v-if="!adaEvent" nama="kunci" ukuran="h-3.5 w-3.5 shrink-0" />
              <span class="truncate">
                {{
                  !absen_event_aktif
                    ? 'Sedang dinonaktifkan admin'
                    : adaEvent
                      ? `${event_aktif.nama} · mulai ${event_aktif.jam_mulai}`
                      : 'Belum ada kegiatan yang dibuka'
                }}
              </span>
            </span>
          </span>

          <Ikon
            nama="kanan"
            ukuran="h-6 w-6 shrink-0 text-sekunder"
            class="transition-transform duration-200 group-hover:translate-x-1"
          />
        </button>
      </div>

      <!--
        BANTUAN SINGKAT, mengisi ruang yang sebelumnya kosong tanpa alasan.

        Bukan pengisi: ketiganya menjawab pertanyaan yang benar-benar muncul di
        depan layar ini — kartu yang tidak terbaca, wajah yang tidak cocok, dan
        siapa yang harus dihubungi ketika keduanya gagal. Petugas yang tahu
        jawabannya tidak perlu meninggalkan antrean untuk mencari orang.
      -->
      <div class="mt-4 grid grid-cols-1 gap-3 sm:mt-5 sm:grid-cols-2 lg:grid-cols-3">
        <p
          v-for="(bantuan, urutan) in bantuanSingkat"
          :key="bantuan.judul"
          class="tahap tahap-redup flex items-start gap-2.5 rounded-xl border border-garis bg-permukaan px-3.5 py-3"
          :style="{ '--tunda': `${880 + urutan * 60}ms` }"
        >
          <span class="ubin-ikon ubin-gradasi h-8 w-8 shrink-0" :class="bantuan.nada">
            <Ikon :nama="bantuan.ikon" ukuran="h-4 w-4" />
          </span>
          <span class="min-w-0 leading-snug">
            <span class="block text-sm font-medium text-utama">{{ bantuan.judul }}</span>
            <span class="mt-0.5 block text-xs text-sekunder">{{ bantuan.isi }}</span>
          </span>
        </p>
      </div>

      <!-- Kaki: aksi yang jarang dipakai, dan memang tidak untuk yang mengantre. -->
      <footer class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-3 text-xs sm:mt-5">
        <p v-if="!perangkatAktif" class="text-redup">
          {{
            mode_pendaftaran
              ? 'Memilih salah satu di atas akan meminta kode aktivasi dari admin.'
              : 'Memilih salah satu di atas akan meminta kode unit kerja lebih dahulu.'
          }}
        </p>

        <button
          v-else
          type="button"
          class="rounded-lg px-2 py-1 text-redup transition-colors duration-150 hover:bg-permukaan-hover hover:text-sekunder"
          @click="lepasPerangkat"
        >
          Lepas perangkat
        </button>

        <Link
          :href="pengguna ? '/admin/dashboard' : '/masuk'"
          class="tautan-aksi rounded-lg px-2 py-1 font-medium text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
        >
          {{ pengguna ? 'Panel Admin' : 'Masuk Admin' }}
        </Link>
      </footer>
    </div>
  </div>
</template>
