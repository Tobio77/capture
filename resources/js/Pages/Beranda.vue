<script setup>
import { Head } from '@inertiajs/vue3'

const fase = [
  { kode: 'Fase 1', nama: 'Fondasi & Autentikasi', sesi: 'S01 – S04' },
  { kode: 'Fase 2', nama: 'Master Data', sesi: 'S05 – S09' },
  { kode: 'Fase 3', nama: 'Manajemen Event', sesi: 'S10 – S12' },
  { kode: 'Fase 4', nama: 'Modul Kiosk & Absensi', sesi: 'S13 – S18' },
  { kode: 'Fase 5', nama: 'Dashboard & Rekap', sesi: 'S19 – S22' },
  { kode: 'Fase 6', nama: 'Laporan & Pengguna', sesi: 'S23 – S25' },
  { kode: 'Fase 7', nama: 'Pengujian & Deployment', sesi: 'S26 – S29' },
]
</script>

<template>
  <Head title="Beranda" />

  <div class="min-h-screen bg-slate-50">
    <header class="bg-navy-700 text-white">
      <div class="mx-auto max-w-4xl px-6 py-10">
        <p class="text-sm font-medium tracking-wide text-teal-300">
          Dinas Tenaga Kerja dan Transmigrasi Provinsi Jawa Timur
        </p>
        <h1 class="mt-2 font-display text-3xl font-semibold">SI-ABSEN</h1>
        <p class="mt-1 text-navy-100">Sistem Informasi Absensi Kegiatan Berbasis Event</p>
      </div>
    </header>

    <main class="mx-auto max-w-4xl px-6 py-10">
      <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-center gap-3">
          <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-600"></span>
          <h2 class="font-display text-lg font-semibold text-navy-700">
            Fondasi proyek siap
          </h2>
        </div>
        <p class="mt-2 text-sm text-slate-600">
          Laravel 13 + Inertia.js + Vue 3 + Tailwind CSS 4 telah terpasang dan berjalan.
          Sesi S01 selesai; pengerjaan dilanjutkan ke Sesi S02 (migration inti).
        </p>
      </div>

      <div class="mt-8 rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-6 py-4">
          <h2 class="font-display text-base font-semibold text-navy-700">Rencana Fase Pengerjaan</h2>
        </div>
        <table class="w-full text-left text-sm">
          <thead class="text-slate-500">
            <tr>
              <th class="px-6 py-3 font-medium">Fase</th>
              <th class="px-6 py-3 font-medium">Fokus Utama</th>
              <th class="px-6 py-3 font-medium">Sesi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in fase" :key="item.kode">
              <td class="px-6 py-3 font-display font-medium text-navy-700">{{ item.kode }}</td>
              <td class="px-6 py-3 text-slate-700">{{ item.nama }}</td>
              <td class="px-6 py-3 font-display text-slate-500">{{ item.sesi }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</template>
