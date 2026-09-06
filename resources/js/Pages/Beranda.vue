<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import TandaAbsen from '@/Components/UI/TandaAbsen.vue'
import SaklarTema from '@/Components/UI/SaklarTema.vue'
import { useJamServer } from '@/Composables/useJamServer'

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
  event_diikuti: { type: Object, default: null },
  event_aktif: { type: Array, default: () => [] },
  absen_umum_aktif: { type: Boolean, required: true },
  aktivasi_tanpa_kode: { type: Boolean, required: true },
  panjang_kode: { type: Number, default: 8 },
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
const sudahIkutEvent = computed(() => props.event_diikuti !== null)

/*
 * Keadaan perangkat, dipecah menjadi tiga keterangan yang masing-masing
 * berdiri sendiri.
 *
 * Sebelumnya ketiganya menempel jadi satu baris — "Perangkat Ad-hoc — Dinas
 * Tenaga Kerja dan Transmigrasi DISNAKER" — yang membaca seperti isi kolom
 * basis data, bukan kalimat. Nama sintetis perangkat ad-hoc memang sudah
 * memuat nama unitnya, sehingga menampilkan keduanya berarti mengulang;
 * yang tersisa untuk disampaikan hanyalah bahwa ia perangkat dadakan, dan
 * itu keping tersendiri.
 */
const perangkatAdHoc = computed(() => props.perangkat?.sumber === 'ad_hoc')

const namaPerangkat = computed(() =>
  perangkatAdHoc.value
    ? (props.perangkat.unit_kerja?.nama ?? 'Perangkat dadakan')
    : props.perangkat.nama_titik,
)

/* Perangkat terdaftar punya nama tempat; unitnya keterangan kedua yang nyata. */
const unitPerangkat = computed(() =>
  perangkatAdHoc.value ? null : (props.perangkat.unit_kerja?.nama ?? null),
)

/*
 * Baris konteks di bawah tanggal. Angka jam sebesar itu perlu konsekuensi:
 * yang membacanya harus langsung tahu ia masih tepat waktu atau sudah lewat.
 * Ketika perangkat melayani sebuah kegiatan, jam kegiatan itulah yang berlaku
 * baginya — bukan jam masuk harian.
 */
const konteks = computed(() => {
  if (sudahIkutEvent.value) {
    return `${props.event_diikuti.nama} · mulai ${props.event_diikuti.jam_mulai}`
  }

  return `Jam masuk ${props.jam_masuk.replace(':', '.')} · toleransi ${props.toleransi_menit} menit`
})

/*
 * Batas tepat waktu hari ini: jam masuk ditambah toleransi (FR-TAP-07).
 * Selama perangkat melayani sebuah kegiatan, jam kegiatan itulah yang berlaku
 * baginya — bukan jam masuk harian.
 */
const batas = computed(() => {
  const [jamMulai, toleransi] = sudahIkutEvent.value
    ? [props.event_diikuti.jam_mulai, props.event_diikuti.toleransi_menit]
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
const status = computed(() =>
  masihTepat.value
    ? {
        kelas: 'bg-emerald-400/15 text-emerald-200',
        titik: 'bg-emerald-300',
        teks: `Masih tepat waktu — batas ${batasTertulis.value}`,
      }
    : {
        kelas: 'bg-amber-400/15 text-amber-200',
        titik: 'bg-amber-300',
        teks: `Lewat batas ${batasTertulis.value} — tercatat terlambat`,
      },
)

const langkah = ref(null)

const formKode = useForm({ kode: '' })
const kolomKode = ref(null)

function pilihAbsenUmum() {
  router.get(perangkatAktif.value ? '/kiosk/umum' : '/kiosk/aktivasi')
}

function pilihAbsenEvent() {
  if (!perangkatAktif.value) {
    router.get('/kiosk/aktivasi')

    return
  }

  if (sudahIkutEvent.value) {
    router.get('/kiosk/event')

    return
  }

  langkah.value = langkah.value === 'event' ? null : 'event'

  if (langkah.value === 'event') {
    requestAnimationFrame(() => kolomKode.value?.focus())
  }
}

function gabung() {
  formKode.post('/kiosk/event/gabung', {
    preserveScroll: true,
    onError: () => {
      formKode.reset('kode')
      kolomKode.value?.focus()
    },
  })
}

function lepasPerangkat() {
  if (
    window.confirm(
      'Lepaskan perangkat ini dari titik absen? Perangkat harus diaktifkan ulang dengan kode baru.',
    )
  ) {
    router.post('/kiosk/lepas')
  }
}

const tanggalRingkas = (nilai) =>
  new Date(`${nilai}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })
</script>

<template>
  <Head title="Titik Absen" />

  <div class="flex min-h-screen flex-col bg-kertas text-utama">
    <!--
      PELAT NAVY.

      Tingginya tidak dipatok angka: ia setinggi isinya, dan kartu jam ditarik
      naik menimpa tepinya dengan margin negatif. Dengan begitu perbatasan
      navy–sage selalu jatuh di tempat yang sama relatif terhadap kartu jam,
      berapa pun tinggi layarnya — patokan yang tidak dapat diberikan oleh
      tinggi tetap dalam satuan vh.
    -->
    <div class="pelat-navy tahap tahap-pelat relative" style="--lama: 380ms">
      <div class="mx-auto w-full max-w-5xl px-6">
        <!--
          Strip identitas. Sengaja setipis mungkin: ia menjawab pertanyaan yang
          hanya ditanyakan sekali ("mesin ini melayani unit mana?") dan tidak
          boleh bersaing dengan jam.
        -->
        <header
          class="tahap tahap-redup flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-4"
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
            <!--
              Perangkat dadakan ditandai terpisah dan berwarna lain dari titik
              hijau "tersambung": ia bukan keadaan sehat yang berjalan normal,
              melainkan pengingat bahwa Mode Terbuka sedang menyala dan
              perangkat ini belum pernah ditinjau siapa pun.
            -->
            <span
              v-if="perangkatAdHoc"
              class="shrink-0 rounded-full bg-amber-400/20 px-2.5 py-1 text-[0.6875rem] font-semibold text-amber-200"
            >
              Ad-hoc
            </span>

            <p class="flex min-w-0 items-center gap-2 text-xs text-sidebar-redup">
              <span v-if="perangkatAktif" class="relative flex h-2 w-2 shrink-0">
                <span
                  class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-60"
                ></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-300"></span>
              </span>
              <span v-else class="h-2 w-2 shrink-0 rounded-full bg-sidebar-redup"></span>

              <span v-if="!perangkatAktif" class="truncate font-medium">
                Perangkat belum diaktifkan
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
          Tanggal dan status dirapatkan ke KANAN pelat, bukan ditumpuk di bawah
          jam. Bersama kartu jam yang digeser ke kiri, keduanya membentuk alur
          baca menyerong — susunan yang tidak mungkin muncul dari satu poros
          tengah, dan itulah bedanya dengan versi sebelumnya.
        -->
        <div class="flex justify-end pb-28 pt-7 text-right sm:pb-32">
          <div class="max-w-sm">
            <p
              class="tahap tahap-redup font-display text-xl font-medium leading-tight text-sidebar-teks sm:text-2xl"
              style="--tunda: 260ms"
            >
              {{ tanggalPanjang }}
            </p>

            <!--
              Keping status. Satu-satunya tempat emerald dan amber muncul di
              layar ini, dan keduanya menyampaikan keterangan yang tidak dapat
              dibaca dari jam saja: apakah orang yang berdiri di sini masih
              tepat waktu. Warnanya tetap datar — begitu warna semantik ikut
              digradasi, ia berhenti berarti apa-apa.

              Ia muncul PALING AKHIR dalam koreografi masuk, karena ia vonis,
              dan vonis datang setelah jamnya terbaca.
            -->
            <p
              class="tahap tahap-redup mt-3 inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-[0.8125rem] font-medium"
              :class="status.kelas"
              style="--tunda: 780ms"
            >
              <span class="h-1.5 w-1.5 rounded-full" :class="status.titik"></span>
              {{ status.teks }}
            </p>

            <p class="tahap tahap-redup mt-2 text-sm text-sidebar-redup" style="--tunda: 820ms">
              {{ konteks }}
            </p>
          </div>
        </div>
      </div>

      <!--
        Deret garis ukur di tepi pelat: motif utama halaman ini, diambil dari
        irisan tiga kejuruan BLK — meteran penjahit, mistar las, sigmat
        otomotif — yang ternyata satu primitif dengan piringan jam.

        Ia tergambar kiri ke kanan sebagai tahap kedua koreografi, seperti
        meteran yang ditarik keluar.
      -->
      <div
        class="skala-ukur skala-terang tahap tahap-skala absolute inset-x-0 bottom-0 h-3"
        style="--tunda: 200ms; --lama: 560ms"
        aria-hidden="true"
      ></div>
    </div>

    <main class="mx-auto -mt-16 w-full max-w-5xl flex-1 px-6 pb-10 sm:-mt-20">
      <!--
        KARTU JAM. Melanggar perbatasan navy–sage, dan digeser dari poros
        tengah. Perbatasan yang dilanggar itulah yang memberi kedalaman —
        bukan bayangan yang ditebalkan.
      -->
      <div
        class="kartu-jam tahap tahap-kartu relative inline-block overflow-hidden px-7 py-6 sm:px-9"
        style="--tunda: 300ms; --lama: 560ms"
      >
        <!-- Penanda kedua motifnya, tegak, di sisi kartu. -->
        <div class="skala-tegak absolute bottom-6 left-0 top-6 w-4" aria-hidden="true"></div>

        <p class="flex items-center gap-4 pl-8 font-display tabular-nums">
          <span
            class="font-bold leading-[0.85] tracking-[-0.05em]"
            style="font-size: clamp(4.25rem, 12vw, 7.5rem)"
          >
            {{ jam }}
          </span>
          <span
            class="font-medium leading-none text-redup"
            style="font-size: clamp(1.1rem, 2.8vw, 1.75rem)"
          >
            {{ detik }}
          </span>
        </p>
      </div>

      <p
        v-if="sukses"
        class="mt-5 rounded-xl bg-berhasil-lembut px-4 py-3 text-sm text-berhasil-teks"
      >
        {{ sukses }}
      </p>

      <p
        v-if="gagal"
        class="mt-5 rounded-xl bg-peringatan-lembut px-4 py-3 text-sm text-peringatan-teks"
      >
        {{ gagal }}
      </p>

      <!--
        Dua pilihan. Tetap selebar layar dan bertumpuk — ergonomi layar sentuh
        yang dioperasikan sambil berdiri tidak diutak-atik. Yang dibedakan
        BOBOTNYA: yang utama lebih tinggi dan bergradasi, yang kedua lebih
        pendek dan bergaris. Dua kotak identik bersebelahan justru pola yang
        sudah dibuang.
      -->
      <div class="mt-8 flex flex-col gap-3.5">
        <button
          type="button"
          class="pita-utama tautan-aksi tahap tahap-pita group flex min-h-[7rem] w-full items-center gap-5 px-6 py-5 text-left transition-transform duration-150 active:scale-[0.995] sm:px-8"
          style="--tunda: 520ms"
          @click="pilihAbsenUmum"
        >
          <TandaAbsen jenis="umum" ukuran="h-12 w-12 shrink-0 opacity-95" />

          <span class="min-w-0 flex-1">
            <span class="block font-display text-2xl font-semibold">Absen Umum</span>
            <!--
              Putih penuh, bukan putih 75%. Meredupkan teks kecil di atas latar
              berwarna menjatuhkan kontrasnya dari 5,47 ke 3,83 — di bawah
              ambang WCAG AA — dan itu tidak terlihat oleh siapa pun sampai
              rasionya benar-benar dihitung.
            -->
            <span class="mt-0.5 block text-sm text-white">
              {{ absen_umum_aktif ? 'Datang dan pulang harian' : 'Sedang dimatikan admin' }}
            </span>
          </span>

          <Ikon
            nama="kanan"
            ukuran="h-7 w-7 shrink-0"
            class="transition-transform duration-200 group-hover:translate-x-1"
          />
        </button>

        <button
          type="button"
          class="pita-kedua tautan-aksi tahap tahap-pita group flex min-h-[6rem] w-full items-center gap-5 px-6 py-4 text-left transition-transform duration-150 active:scale-[0.995] sm:px-8"
          :class="langkah === 'event' && 'border-aksen'"
          style="--tunda: 620ms"
          @click="pilihAbsenEvent"
        >
          <TandaAbsen jenis="event" ukuran="h-11 w-11 shrink-0 text-aksen-teks" />

          <span class="min-w-0 flex-1">
            <span class="block font-display text-xl font-semibold">Absen Event</span>
            <span class="mt-0.5 flex items-center gap-1.5 text-sm text-sekunder">
              <Ikon v-if="!sudahIkutEvent" nama="kunci" ukuran="h-3.5 w-3.5 shrink-0" />
              <span class="truncate">
                {{
                  sudahIkutEvent
                    ? `Melayani ${event_diikuti.nama}`
                    : 'Perlu kode unit kerja dari admin penyelenggara'
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

      <!-- Langkah kedua: daftar event yang dibuka, lalu kode unit kerja. -->
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
      >
        <section v-if="langkah === 'event'" class="panel mt-3 p-5">
          <ul v-if="event_aktif.length" class="flex flex-col gap-1.5">
            <li
              v-for="event in event_aktif"
              :key="event.id"
              class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 rounded-lg bg-permukaan-2 px-3.5 py-2.5"
            >
              <span class="min-w-0 truncate font-medium">{{ event.nama }}</span>
              <span class="font-display text-xs tabular-nums text-redup">
                {{ tanggalRingkas(event.tanggal) }} · {{ event.jam_mulai }} ·
                {{ event.cakupan_label }}
              </span>
            </li>
          </ul>

          <p v-else class="rounded-lg bg-permukaan-2 px-4 py-5 text-center text-sm text-redup">
            Belum ada event yang dibuka. Absen Umum tetap dapat dipakai.
          </p>

          <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="gabung">
            <div class="min-w-[13rem] flex-1">
              <label
                for="kode-unit"
                class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
              >
                Kode unit kerja
              </label>

              <input
                id="kode-unit"
                ref="kolomKode"
                v-model="formKode.kode"
                type="text"
                autocomplete="off"
                spellcheck="false"
                :maxlength="panjang_kode + 2"
                placeholder="7K4M-92XQ"
                class="kolom-isian py-3 text-center font-display text-lg font-semibold uppercase tracking-[0.2em] placeholder:tracking-normal"
              />
            </div>

            <button
              type="submit"
              :disabled="formKode.processing || formKode.kode.length === 0"
              class="tombol tombol-utama py-3"
            >
              {{ formKode.processing ? 'Menggabungkan…' : 'Gabung ke Event' }}
            </button>
          </form>

          <p v-if="formKode.errors.kode" class="mt-2 text-sm text-peringatan-teks">
            {{ formKode.errors.kode }}
          </p>
        </section>
      </Transition>
    </main>

    <!-- Kaki: aksi yang jarang dipakai, dan memang tidak untuk yang mengantre. -->
    <footer class="border-t border-garis">
      <div
        class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-3 text-xs"
      >
        <p v-if="!perangkatAktif" class="text-redup">
          {{
            aktivasi_tanpa_kode
              ? 'Memilih salah satu di atas akan meminta unit kerjanya lebih dahulu.'
              : 'Memilih salah satu di atas akan meminta kode aktivasi dari admin.'
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
      </div>
    </footer>
  </div>
</template>
