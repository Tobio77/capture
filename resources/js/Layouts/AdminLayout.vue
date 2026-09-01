<script setup>
import { computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import IkonMenu from '@/Components/IkonMenu.vue'

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

const keluar = () => router.post('/keluar')
</script>

<template>
  <Head :title="judul" />

  <div class="min-h-screen bg-slate-50 lg:flex">
    <!-- Sidebar -->
    <!-- Sidebar tidak ikut tercetak; lembar cetak hanya memuat isinya (FR-REK-03). -->
    <aside class="flex flex-col bg-navy-700 text-navy-100 lg:min-h-screen lg:w-72 lg:shrink-0 print:hidden">
      <div class="border-b border-white/10 px-6 py-5">
        <p class="font-display text-lg font-semibold text-white">SI-ABSEN</p>
        <p class="mt-0.5 text-xs text-navy-200">Absensi Kegiatan Berbasis Event</p>
      </div>

      <nav class="flex-1 space-y-1 px-3 py-4">
        <template v-for="item in menu" :key="item.label">
          <!-- Menu induk dengan submenu -->
          <div v-if="item.anak" class="pt-2">
            <p class="flex items-center gap-2 px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-navy-300">
              <IkonMenu :nama="item.ikon" />
              {{ item.label }}
            </p>
            <Link
              v-for="anak in item.anak"
              :key="anak.rute"
              :href="anak.url"
              class="block rounded-md py-2 pl-10 pr-3 text-sm transition"
              :class="aktif(anak.rute)
                ? 'bg-teal-600 font-medium text-white'
                : 'text-navy-100 hover:bg-white/10 hover:text-white'"
            >
              {{ anak.label }}
            </Link>
          </div>

          <!-- Menu tunggal -->
          <Link
            v-else
            :href="item.url"
            class="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition"
            :class="aktif(item.rute)
              ? 'bg-teal-600 font-medium text-white'
              : 'text-navy-100 hover:bg-white/10 hover:text-white'"
          >
            <IkonMenu :nama="item.ikon" />
            {{ item.label }}
          </Link>
        </template>
      </nav>

      <!-- Indikator peran & cakupan unit kerja -->
      <div class="border-t border-white/10 px-6 py-4">
        <p class="truncate text-sm font-medium text-white">{{ pengguna.nama }}</p>
        <p class="mt-0.5 text-xs text-teal-300">{{ pengguna.role_label }}</p>
        <p class="mt-0.5 truncate text-xs text-navy-300" :title="cakupan">{{ cakupan }}</p>
        <button
          type="button"
          class="mt-3 w-full rounded-md border border-white/20 px-3 py-2 text-xs font-medium text-navy-100 transition hover:bg-white/10 hover:text-white"
          @click="keluar"
        >
          Keluar
        </button>
      </div>
    </aside>

    <!-- Konten -->
    <div class="flex-1">
      <main class="mx-auto max-w-6xl px-6 py-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 class="font-display text-2xl font-semibold text-navy-700">{{ judul }}</h1>
            <p v-if="deskripsi" class="mt-1 text-sm text-slate-600">{{ deskripsi }}</p>
          </div>
          <slot name="aksi" />
        </div>

        <div
          v-if="flash.sukses"
          class="mt-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
        >
          {{ flash.sukses }}
        </div>
        <div
          v-if="flash.gagal"
          class="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700"
        >
          {{ flash.gagal }}
        </div>

        <div class="mt-6">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>
