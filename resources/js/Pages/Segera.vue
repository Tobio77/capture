<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'

defineProps({
  judul: { type: String, required: true },
  deskripsi: { type: String, default: '' },
})
</script>

<template>
  <AdminLayout :judul="judul">
    <div class="rounded-lg border border-dashed border-garis bg-permukaan p-10 text-center">
      <p class="font-display text-base font-semibold text-utama">Halaman sedang dikembangkan</p>
      <p class="mx-auto mt-2 max-w-lg text-sm text-sekunder">{{ deskripsi }}</p>
    </div>
  </AdminLayout>
</template>
