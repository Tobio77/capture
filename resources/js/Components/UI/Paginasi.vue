<script setup>
import { Link } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'

/**
 * Navigasi halaman untuk paginator Laravel.
 *
 * Menampilkan rentang baris yang sedang dilihat, bukan sekadar nomor halaman:
 * "31–60 dari 259" langsung menjawab pertanyaan yang biasanya muncul saat
 * membaca daftar panjang.
 */

defineProps({
  data: { type: Object, required: true },
})
</script>

<template>
  <div
    v-if="data.total > 0"
    class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 print:hidden"
  >
    <p class="text-xs text-slate-500">
      Menampilkan
      <span class="font-display tabular-nums text-slate-700">{{ data.from ?? 0 }}–{{ data.to ?? 0 }}</span>
      dari
      <span class="font-display tabular-nums text-slate-700">{{ data.total }}</span>
      baris
    </p>

    <div v-if="data.last_page > 1" class="flex flex-wrap items-center gap-1">
      <component
        :is="tautan.url ? Link : 'span'"
        v-for="(tautan, urutan) in data.links"
        :key="urutan"
        :href="tautan.url ?? undefined"
        preserve-scroll
        preserve-state
        class="inline-flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm transition"
        :class="[
          tautan.active
            ? 'bg-teal-600 font-semibold text-white shadow-sm'
            : 'text-slate-600 hover:bg-slate-100',
          !tautan.url && 'cursor-default text-slate-300 hover:bg-transparent',
        ]"
      >
        <Ikon v-if="urutan === 0" nama="kiri" ukuran="h-4 w-4" />
        <Ikon v-else-if="urutan === data.links.length - 1" nama="kanan" ukuran="h-4 w-4" />
        <span v-else v-html="tautan.label" />
      </component>
    </div>
  </div>
</template>
