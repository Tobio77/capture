<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import SaklarTema from '@/Components/UI/SaklarTema.vue'

/**
 * Halaman depan aplikasi (S30).
 *
 * Satu pintu masuk untuk tiga orang yang berbeda: pegawai yang hendak
 * mengabsen harian, petugas yang membuka titik absen sebuah kegiatan, dan
 * admin yang hendak masuk ke panel. Ketiganya sebelumnya harus tahu alamat
 * yang berbeda-beda.
 *
 * Dua pilihan absen dibuat besar dan sejajar karena layar ini dibaca dari
 * jarak berdiri, kerap di aula yang ramai. Masuk sebagai admin justru
 * dikecilkan ke sudut: ia jarang dipakai, dan bukan untuk orang yang sedang
 * mengantre.
 */

const props = defineProps({
  perangkat: { type: Object, default: null },
  event_diikuti: { type: Object, default: null },
  event_aktif: { type: Array, default: () => [] },
  absen_umum_aktif: { type: Boolean, required: true },
  aktivasi_tanpa_kode: { type: Boolean, required: true },
  panjang_kode: { type: Number, default: 8 },
})

const page = usePage()
const namaAplikasi = computed(() => page.props.app?.nama ?? 'Capture')
const pengguna = computed(() => page.props.auth?.pengguna ?? null)
const sukses = computed(() => page.props.flash?.sukses)
const gagal = computed(() => page.props.flash?.gagal)

const perangkatAktif = computed(() => props.perangkat !== null)
const sudahIkutEvent = computed(() => props.event_diikuti !== null)

/*
 * Langkah yang sedang terbuka: null (dua kartu sejajar) atau 'event' (daftar
 * event beserta kolom kode). Absen Umum tidak punya langkah kedua — ia
 * langsung menuju layarnya, sesuai aturan Mode Terbuka.
 */
const langkah = ref(null)

const formKode = useForm({ kode: '' })
const kolomKode = ref(null)

function pilihAbsenUmum() {
  if (!perangkatAktif.value) {
    router.get('/kiosk/aktivasi')

    return
  }

  router.get('/kiosk/umum')
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

  langkah.value = 'event'

  // Kolomnya baru ada setelah bagiannya tergambar.
  requestAnimationFrame(() => kolomKode.value?.focus())
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

const tanggalPanjang = (nilai) =>
  new Date(`${nilai}T00:00:00`).toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  })
</script>

<template>
  <Head title="Beranda" />

  <div class="latar-pastel flex min-h-screen flex-col bg-kertas text-utama">
    <!--
      Bilah atas sengaja ringan: bukan sidebar navy seperti Panel Admin, karena
      halaman ini dilihat pegawai, bukan pengelola.
    -->
    <header class="border-b border-garis/70 bg-permukaan/70 backdrop-blur-sm">
      <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div class="flex items-center gap-2.5">
          <span class="inline-flex rounded-xl bg-aksen-lembut p-2 text-aksen-teks">
            <Ikon nama="absen" ukuran="h-5 w-5" />
          </span>
          <div class="leading-tight">
            <p class="font-display text-base font-semibold">{{ namaAplikasi }}</p>
            <p class="text-xs text-redup">Absensi Disnakertrans Jawa Timur</p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <SaklarTema />

          <Link
            v-if="pengguna"
            href="/admin/dashboard"
            class="tautan-aksi inline-flex items-center gap-1.5 rounded-xl border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
          >
            <Ikon nama="dashboard" ukuran="h-4 w-4" /> Panel Admin
          </Link>

          <Link
            v-else
            href="/masuk"
            class="tautan-aksi inline-flex items-center gap-1.5 rounded-xl border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-sekunder transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
          >
            <Ikon nama="kunci" ukuran="h-4 w-4" /> Masuk Admin
          </Link>
        </div>
      </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-5 py-10 sm:py-14">
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

      <!-- Sambutan -->
      <section class="text-center">
        <h1 class="font-display text-3xl font-semibold sm:text-4xl">Selamat datang</h1>
        <p class="mx-auto mt-3 max-w-xl text-sm text-sekunder sm:text-base">
          Silakan pilih jenis absensi. Kehadiran Anda tercatat beserta foto sebagai bukti.
        </p>

        <p
          v-if="perangkatAktif"
          class="mt-4 inline-flex items-center gap-2 rounded-full bg-permukaan px-3.5 py-1.5 text-xs text-sekunder bayang"
        >
          <span class="h-1.5 w-1.5 rounded-full bg-berhasil"></span>
          {{ perangkat.nama_titik }}
          <span v-if="perangkat.unit_kerja" class="text-redup">
            · {{ perangkat.unit_kerja.nama }}
          </span>
        </p>
      </section>

      <!-- Dua pilihan besar -->
      <div class="mt-9 grid gap-5 sm:grid-cols-2">
        <button
          type="button"
          class="kartu-naik group flex flex-col items-start rounded-2xl border border-garis bg-permukaan p-6 text-left bayang hover:border-aksen/40"
          @click="pilihAbsenUmum"
        >
          <span class="inline-flex rounded-2xl bg-aksen-lembut p-3.5 text-aksen-teks transition-transform duration-200 group-hover:scale-105">
            <Ikon nama="jam" ukuran="h-7 w-7" />
          </span>

          <h2 class="mt-5 font-display text-xl font-semibold">Absen Umum</h2>
          <p class="mt-1.5 flex-1 text-sm text-sekunder">
            Absensi harian datang dan pulang. Selalu tersedia, tanpa kode dan tanpa kegiatan.
          </p>

          <span
            v-if="!absen_umum_aktif"
            class="mt-3 rounded-lg bg-peringatan-lembut px-2.5 py-1 text-xs text-peringatan-teks"
          >
            Sedang dimatikan admin
          </span>

          <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-aksen-teks">
            Mulai absen
            <Ikon
              nama="kanan"
              ukuran="h-4 w-4"
              class="transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </span>
        </button>

        <button
          type="button"
          class="kartu-naik group flex flex-col items-start rounded-2xl border border-garis bg-permukaan p-6 text-left bayang hover:border-aksen/40"
          :class="langkah === 'event' && 'border-aksen/40'"
          @click="pilihAbsenEvent"
        >
          <span class="inline-flex rounded-2xl bg-info-lembut p-3.5 text-info-teks transition-transform duration-200 group-hover:scale-105">
            <Ikon nama="kalender" ukuran="h-7 w-7" />
          </span>

          <h2 class="mt-5 font-display text-xl font-semibold">Absen Event</h2>

          <p v-if="sudahIkutEvent" class="mt-1.5 flex-1 text-sm text-sekunder">
            Perangkat ini melayani
            <span class="font-medium text-utama">{{ event_diikuti.nama }}</span>
            — mulai {{ event_diikuti.jam_mulai }}.
          </p>
          <p v-else class="mt-1.5 flex-1 text-sm text-sekunder">
            Absensi kegiatan: apel, rapat, atau pelatihan. Perlu kode unit kerja dari admin
            penyelenggara.
          </p>

          <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-aksen-teks">
            {{ sudahIkutEvent ? 'Mulai absen' : 'Pilih event' }}
            <Ikon
              nama="kanan"
              ukuran="h-4 w-4"
              class="transition-transform duration-200 group-hover:translate-x-0.5"
            />
          </span>
        </button>
      </div>

      <!-- Langkah kedua Absen Event: daftar event aktif lalu kode unit kerja -->
      <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
      >
        <section
          v-if="langkah === 'event'"
          class="mt-5 rounded-2xl border border-garis bg-permukaan p-6 bayang"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-display text-base font-semibold">Event yang sedang dibuka</h3>
              <p class="mt-1 text-sm text-sekunder">
                Masukkan kode unit kerja dari admin penyelenggara untuk melayani salah satunya.
              </p>
            </div>

            <button
              type="button"
              class="rounded-lg p-2 text-redup transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
              aria-label="Tutup pilihan event"
              @click="langkah = null"
            >
              <Ikon nama="tutup" ukuran="h-4 w-4" />
            </button>
          </div>

          <ul v-if="event_aktif.length" class="mt-5 space-y-2">
            <li
              v-for="event in event_aktif"
              :key="event.id"
              class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-permukaan-2 px-4 py-3"
            >
              <div class="min-w-0">
                <p class="truncate font-medium text-utama">{{ event.nama }}</p>
                <p class="mt-0.5 text-xs text-redup">
                  {{ tanggalPanjang(event.tanggal) }} · mulai {{ event.jam_mulai }}
                </p>
              </div>

              <span class="rounded-full bg-info-lembut px-2.5 py-1 font-display text-xs text-info-teks">
                {{ event.cakupan_label }}
              </span>
            </li>
          </ul>

          <p v-else class="mt-5 rounded-xl bg-permukaan-2 px-4 py-6 text-center text-sm text-redup">
            Belum ada event yang dibuka. Absen Umum tetap dapat dipakai.
          </p>

          <form class="mt-5 flex flex-wrap items-end gap-3" @submit.prevent="gabung">
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
                class="w-full rounded-xl border border-garis bg-permukaan-2 px-4 py-3 text-center font-display text-lg font-semibold uppercase tracking-[0.2em] text-utama transition-colors duration-150 placeholder:tracking-normal placeholder:text-redup focus:border-aksen focus:bg-permukaan focus:outline-none focus:ring-1 focus:ring-aksen"
              />
            </div>

            <button
              type="submit"
              :disabled="formKode.processing || formKode.kode.length === 0"
              class="rounded-xl bg-aksen px-5 py-3 text-sm font-semibold text-white transition-colors duration-150 hover:bg-aksen-kuat disabled:cursor-not-allowed disabled:opacity-50 active:scale-[0.98]"
            >
              {{ formKode.processing ? 'Menggabungkan…' : 'Gabung ke Event' }}
            </button>
          </form>

          <p v-if="formKode.errors.kode" class="mt-2 text-sm text-peringatan-teks">
            {{ formKode.errors.kode }}
          </p>
        </section>
      </Transition>

      <!-- Keterangan perangkat yang belum diaktifkan -->
      <p v-if="!perangkatAktif" class="mt-8 text-center text-sm text-redup">
        {{
          aktivasi_tanpa_kode
            ? 'Perangkat ini belum diaktifkan. Memilih salah satu di atas akan meminta unit kerjanya lebih dahulu.'
            : 'Perangkat ini belum diaktifkan. Memilih salah satu di atas akan meminta kode aktivasi dari admin.'
        }}
      </p>

      <button
        v-else
        type="button"
        class="mx-auto mt-8 block rounded-lg px-3 py-2 text-xs font-medium text-redup transition-colors duration-150 hover:bg-permukaan-hover hover:text-sekunder"
        @click="lepasPerangkat"
      >
        Lepas perangkat dari titik absen ini
      </button>
    </main>
  </div>
</template>
