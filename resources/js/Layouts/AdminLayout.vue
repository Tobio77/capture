<script setup>
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'

defineProps({
  judul: { type: String, required: true },
  deskripsi: { type: String, default: '' },
})

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)
const menu = computed(() => page.props.menu)
const ruteSaatIni = computed(() => page.props.rute_saat_ini)
const flash = computed(() => page.props.flash)

const cakupan = computed(() =>
  pengguna.value.lintas_unit
    ? 'Seluruh unit kerja'
    : (pengguna.value.unit_kerja?.nama ?? 'Tanpa unit kerja'),
)

const aktif = (rute) => ruteSaatIni.value === rute

/* Sidebar menumpuk di layar sempit; di lg ke atas selalu terbuka. */
const menuTerbuka = ref(false)

const keluar = () => router.post('/keluar')
</script>

<template>
  <Head :title="judul" />

  <div class="min-h-screen bg-slate-50 lg:flex">
    <!-- Bilah atas khusus layar sempit -->
    <div
      class="flex items-center justify-between bg-navy-700 px-4 py-3 text-white lg:hidden print:hidden"
    >
      <p class="font-display text-base font-semibold">Capture</p>
      <button
        type="button"
        class="rounded-md p-2 transition hover:bg-white/10 active:scale-95"
        :aria-expanded="menuTerbuka"
        aria-label="Buka menu navigasi"
        @click="menuTerbuka = !menuTerbuka"
      >
        <Ikon :nama="menuTerbuka ? 'tutup' : 'dashboard'" ukuran="h-5 w-5" />
      </button>
    </div>

    <!-- Sidebar; tidak ikut tercetak — lembar cetak hanya memuat isinya (FR-REK-03). -->
    <aside
      class="flex-col bg-navy-700 text-navy-100 lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-72 lg:shrink-0 print:hidden"
      :class="menuTerbuka ? 'flex' : 'hidden'"
    >
      <div class="hidden border-b border-white/10 px-6 py-5 lg:block">
        <p class="flex items-center gap-2 font-display text-lg font-semibold text-white">
          <span class="rounded-md bg-teal-600 p-1.5">
            <Ikon nama="absen" ukuran="h-4 w-4" />
          </span>
          Capture
        </p>
        <p class="mt-1.5 text-xs text-navy-200">Absensi Kegiatan Berbasis Event</p>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <template v-for="item in menu" :key="item.label">
          <!-- Menu induk dengan submenu -->
          <div v-if="item.anak" class="pt-2">
            <p
              class="flex items-center gap-2 px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-navy-300"
            >
              <Ikon :nama="item.ikon" ukuran="h-4 w-4" />
              {{ item.label }}
            </p>
            <Link
              v-for="anak in item.anak"
              :key="anak.rute"
              :href="anak.url"
              class="relative block rounded-md py-2 pl-10 pr-3 text-sm transition-all duration-150"
              :class="
                aktif(anak.rute)
                  ? 'bg-teal-600 font-medium text-white shadow-sm'
                  : 'text-navy-100 hover:translate-x-0.5 hover:bg-white/10 hover:text-white'
              "
              @click="menuTerbuka = false"
            >
              <span
                v-if="aktif(anak.rute)"
                class="absolute left-3 top-1/2 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-white"
              ></span>
              {{ anak.label }}
            </Link>
          </div>

          <!-- Menu tunggal -->
          <Link
            v-else
            :href="item.url"
            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-all duration-150"
            :class="
              aktif(item.rute)
                ? 'bg-teal-600 font-medium text-white shadow-sm'
                : 'text-navy-100 hover:translate-x-0.5 hover:bg-white/10 hover:text-white'
            "
            @click="menuTerbuka = false"
          >
            <Ikon :nama="item.ikon" ukuran="h-5 w-5 shrink-0" />
            {{ item.label }}
          </Link>
        </template>
      </nav>

      <!-- Indikator peran & cakupan unit kerja -->
      <div class="border-t border-white/10 px-6 py-4">
        <div class="flex items-center gap-3">
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-600 font-display text-sm font-semibold text-white"
          >
            {{ pengguna.nama.charAt(0).toUpperCase() }}
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-white">{{ pengguna.nama }}</p>
            <p class="truncate text-xs text-teal-300">{{ pengguna.role_label }}</p>
          </div>
        </div>
        <p class="mt-2 truncate text-xs text-navy-300" :title="cakupan">{{ cakupan }}</p>
        <button
          type="button"
          class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-md border border-white/20 px-3 py-2 text-xs font-medium text-navy-100 transition hover:bg-white/10 hover:text-white active:scale-95"
          @click="keluar"
        >
          <Ikon nama="keluar" ukuran="h-4 w-4" /> Keluar
        </button>
      </div>
    </aside>

    <!-- Konten -->
    <div class="min-w-0 flex-1">
      <main class="mx-auto max-w-6xl px-6 py-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 class="font-display text-2xl font-semibold text-navy-700">{{ judul }}</h1>
            <p v-if="deskripsi" class="mt-1 text-sm text-slate-600">{{ deskripsi }}</p>
          </div>
          <slot name="aksi" />
        </div>

        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="-translate-y-2 opacity-0"
          enter-to-class="translate-y-0 opacity-100"
        >
          <div
            v-if="flash.sukses"
            class="mt-6 flex items-start gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 print:hidden"
          >
            <Ikon nama="cek" ukuran="h-4 w-4 shrink-0 mt-0.5" />
            <span>{{ flash.sukses }}</span>
          </div>
        </Transition>
        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="-translate-y-2 opacity-0"
          enter-to-class="translate-y-0 opacity-100"
        >
          <div
            v-if="flash.gagal"
            class="mt-6 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700 print:hidden"
          >
            <Ikon nama="peringatan" ukuran="h-4 w-4 shrink-0 mt-0.5" />
            <span>{{ flash.gagal }}</span>
          </div>
        </Transition>

        <div class="mt-6">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>
