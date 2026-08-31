<script setup>
import { computed } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'

const page = usePage()
const kiosk = computed(() => page.props.kiosk)
const flash = computed(() => page.props.flash)

const diaktifkan = computed(() => {
  if (!kiosk.value.diaktifkan_pada) return '—'
  return new Date(kiosk.value.diaktifkan_pada).toLocaleString('id-ID', {
    dateStyle: 'long',
    timeStyle: 'short',
  })
})

const lepas = () => {
  if (window.confirm('Lepaskan perangkat ini dari titik absen? Perangkat harus diaktifkan ulang dengan kode baru.')) {
    router.post('/kiosk/lepas')
  }
}
</script>

<template>
  <Head title="Layar Kiosk" />

  <div class="min-h-screen bg-slate-900 text-slate-100">
    <header class="border-b border-white/10 bg-navy-700">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-4">
        <div>
          <p class="font-display text-lg font-semibold text-white">{{ kiosk.nama_titik }}</p>
          <p class="text-sm text-navy-200">{{ kiosk.unit_kerja?.nama }}</p>
        </div>
        <div class="flex items-center gap-4">
          <span class="inline-flex items-center gap-2 rounded-full bg-emerald-600/15 px-3 py-1 text-xs font-medium text-emerald-400">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            Perangkat Aktif
          </span>
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

    <main class="mx-auto max-w-6xl px-6 py-10">
      <div
        v-if="flash.sukses"
        class="mb-6 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300"
      >
        {{ flash.sukses }}
      </div>

      <div class="rounded-lg border border-white/10 bg-white/5 p-6">
        <h1 class="font-display text-xl font-semibold text-white">Perangkat siap digunakan</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-300">
          Perangkat ini sudah memegang token aksesnya sendiri dan tidak akan meminta kode aktivasi lagi.
          Panel Capture Foto &amp; Entry Absen dan Daftar e-Presensi dibangun pada Sesi S13.
        </p>

        <dl class="mt-6 grid gap-6 sm:grid-cols-3">
          <div>
            <dt class="text-xs uppercase tracking-wider text-slate-400">Unit Kerja</dt>
            <dd class="mt-1 font-display text-sm text-white">{{ kiosk.unit_kerja?.nama }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-slate-400">Alamat IP Tercatat</dt>
            <dd class="mt-1 font-display text-sm text-white">{{ kiosk.ip_terakhir ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs uppercase tracking-wider text-slate-400">Diaktifkan Pada</dt>
            <dd class="mt-1 font-display text-sm text-white">{{ diaktifkan }}</dd>
          </div>
        </dl>
      </div>
    </main>
  </div>
</template>
