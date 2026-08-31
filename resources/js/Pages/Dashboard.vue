<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)

const cakupan = computed(() =>
  pengguna.value.lintas_unit
    ? 'seluruh unit kerja'
    : (pengguna.value.unit_kerja?.nama ?? 'tanpa unit kerja'),
)
</script>

<template>
  <AdminLayout
    judul="Dashboard"
    :deskripsi="`Ringkasan kehadiran untuk ${cakupan}.`"
  >
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex items-center gap-3">
        <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-600"></span>
        <h2 class="font-display text-lg font-semibold text-navy-700">
          Selamat datang, {{ pengguna.nama }}
        </h2>
      </div>
      <p class="mt-2 text-sm text-slate-600">
        Anda masuk sebagai <span class="font-medium text-navy-700">{{ pengguna.role_label }}</span>
        dengan cakupan {{ cakupan }}. Menu di sisi kiri menyesuaikan hak akses peran Anda.
      </p>
      <p class="mt-4 text-sm text-slate-500">
        Kartu statistik dan grafik tren kehadiran dikerjakan pada Sesi S19–S20.
      </p>
    </div>
  </AdminLayout>
</template>
