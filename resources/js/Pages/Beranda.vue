<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import SaklarTema from '@/Components/UI/SaklarTema.vue'
import { useJamServer } from '@/Composables/useJamServer'

/**
 * Halaman depan titik absen — "Papan Jam" (S31).
 *
 * Layar ini menempel di pintu masuk kantor dinas atau ruang praktik BLK, dan
 * dilihat orang yang sama setiap pagi. Setelah hari ketiga tidak ada lagi yang
 * membaca sambutan; yang dicari hanya dua hal — pukul berapa sekarang, dan
 * tombol mana yang ditekan.
 *
 * Karena itu jam menjadi elemen pertama dan terbesar, bukan judul. Susunan
 * sebelumnya (eyebrow "TITIK ABSEN", judul "Selamat datang", dua kartu ikon
 * bersebelahan) adalah susunan halaman pemasaran: ia memperkenalkan diri
 * kepada pengunjung baru, padahal di sini tidak pernah ada pengunjung baru.
 *
 * Dua pilihan absen menjadi baris tekan selebar layar yang bertumpuk, bukan
 * kartu bersebelahan. Alasannya fungsional: layar sentuh ini dioperasikan
 * sambil berdiri, kerap dengan satu tangan memegang kartu identitas, dan
 * sasaran selebar layar jauh lebih mudah dikenai daripada dua kartu yang
 * berbagi lebar.
 *
 * Satu-satunya yang bergerak di layar ini adalah detik pada jam. Itulah yang
 * membuatnya terbaca sebagai papan jam yang hidup, bukan halaman yang gelisah.
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
 * Keping status di bawah jam.
 *
 * Inilah yang membuat angka sebesar itu punya konsekuensi: orang yang
 * membacanya langsung tahu ia masih tepat waktu atau sudah lewat, tanpa
 * menghitung sendiri selisihnya terhadap jam masuk. Sekaligus satu-satunya
 * tempat emerald dan amber muncul di layar ini — warnanya menyampaikan
 * keterangan, bukan menghias.
 */
const status = computed(() =>
  masihTepat.value
    ? { nada: 'nada-emerald', teks: `Masih tepat waktu — batas ${batasTertulis.value}` }
    : { nada: 'nada-amber', teks: `Lewat batas ${batasTertulis.value} — tercatat terlambat` },
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
      Strip identitas. Sengaja setipis dan sedatar mungkin: ia menjawab
      pertanyaan yang hanya ditanyakan sekali ("mesin ini melayani unit mana?")
      dan tidak boleh bersaing dengan jam.
    -->
    <header class="border-b border-garis">
      <div class="mx-auto flex w-full max-w-4xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
        <!--
          Identitas lembaga. Tanpa ini layar ini bisa jadi aplikasi jam mana
          pun — dan papan yang menempel di pintu masuk kantor dinas justru
          harus menyebut kantornya.
        -->
        <p class="flex min-w-0 items-center gap-2.5">
          <span class="ubin-merek h-7 w-7 shrink-0">
            <Ikon nama="absen" ukuran="h-3.5 w-3.5" />
          </span>
          <span class="min-w-0 leading-tight">
            <span class="block truncate font-display text-sm font-semibold">Capture</span>
            <span class="block truncate text-[0.6875rem] text-redup">
              Disnakertrans Provinsi Jawa Timur
            </span>
          </span>
        </p>

        <div class="flex min-w-0 items-center gap-3">
          <p class="flex min-w-0 items-center gap-2 text-xs text-sekunder">
            <span v-if="perangkatAktif" class="relative flex h-2 w-2 shrink-0">
              <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-berhasil opacity-60"></span>
              <span class="relative inline-flex h-2 w-2 rounded-full bg-berhasil"></span>
            </span>
            <span v-else class="h-2 w-2 shrink-0 rounded-full bg-redup"></span>

            <span class="truncate font-medium">
              {{ perangkatAktif ? perangkat.nama_titik : 'Perangkat belum diaktifkan' }}
            </span>
            <span v-if="perangkatAktif && perangkat.unit_kerja" class="truncate text-redup">
              {{ perangkat.unit_kerja.kode }}
            </span>
          </p>

          <SaklarTema />
        </div>
      </div>
    </header>

    <main class="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center px-5 py-8">
      <p
        v-if="sukses"
        class="mb-6 rounded-xl bg-berhasil-lembut px-4 py-3 text-sm text-berhasil-teks"
      >
        {{ sukses }}
      </p>

      <p
        v-if="gagal"
        class="mb-6 rounded-xl bg-peringatan-lembut px-4 py-3 text-sm text-peringatan-teks"
      >
        {{ gagal }}
      </p>

      <!--
        Jam. Menit dan detik dipisah supaya angka menit tidak bergoyang setiap
        detik: yang berdenyut hanya dua digit kecil di sampingnya.
      -->
      <section class="text-center">
        <!--
          Detik disejajarkan ke GARIS DASAR angka jam, bukan ke puncaknya:
          diletakkan di atas ia terbaca sebagai pangkat, bukan sebagai satuan
          waktu yang lebih kecil.
        -->
        <p class="flex items-baseline justify-center gap-2.5 font-display tabular-nums">
          <span
            class="font-bold leading-[0.85] tracking-[-0.045em]"
            style="font-size: clamp(4.5rem, 13vw, 8rem)"
          >
            {{ jam }}
          </span>
          <span
            class="font-medium leading-none text-redup"
            style="font-size: clamp(1.15rem, 3vw, 1.9rem)"
          >
            {{ detik }}
          </span>
        </p>

        <p
          class="mt-1 font-display font-medium text-sekunder"
          style="font-size: clamp(1rem, 2.2vw, 1.375rem)"
        >
          {{ tanggalPanjang }}
        </p>

        <!--
          Keping status. Satu-satunya tempat emerald dan amber muncul di layar
          ini, dan keduanya menyampaikan keterangan yang tidak dapat dibaca
          dari jam saja: apakah orang yang berdiri di sini masih tepat waktu.
        -->
        <p
          class="keping mt-4 px-3.5 py-1.5 text-[0.8125rem]"
          :class="status.nada"
        >
          <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: 'var(--nada-kuat)' }"></span>
          {{ status.teks }}
        </p>

        <p class="mt-3 text-sm text-sekunder">{{ konteks }}</p>
      </section>

      <!--
        Dua pilihan. Baris selebar layar, bertumpuk, tinggi minimum 96px —
        ukuran tombol papan, bukan kartu bacaan.
      -->
      <div class="mt-10 flex flex-col gap-3">
        <button
          type="button"
          class="tautan-aksi group flex min-h-[6rem] w-full items-center gap-4 rounded-2xl bg-aksen px-6 py-5 text-left text-white transition-[background-color,transform] duration-150 hover:bg-aksen-kuat active:scale-[0.995]"
          @click="pilihAbsenUmum"
        >
          <span class="min-w-0 flex-1">
            <span class="block font-display text-2xl font-semibold">Absen Umum</span>
            <span class="mt-1 block text-sm text-white/75">
              {{ absen_umum_aktif ? 'Datang & pulang harian' : 'Sedang dimatikan admin' }}
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
          class="tautan-aksi group flex min-h-[6rem] w-full items-center gap-4 rounded-2xl border-2 border-garis-kuat bg-permukaan px-6 py-5 text-left transition-[border-color,transform] duration-150 hover:border-aksen active:scale-[0.995]"
          :class="langkah === 'event' && 'border-aksen'"
          @click="pilihAbsenEvent"
        >
          <span class="min-w-0 flex-1">
            <span class="block font-display text-2xl font-semibold">Absen Event</span>
            <span class="mt-1 flex items-center gap-1.5 text-sm text-sekunder">
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
            ukuran="h-7 w-7 shrink-0 text-sekunder"
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
        class="mx-auto flex w-full max-w-4xl flex-wrap items-center justify-between gap-3 px-5 py-3 text-xs"
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
