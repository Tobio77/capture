<script setup>
import { computed } from 'vue'

/**
 * Lencana status dengan titik penanda.
 *
 * Warnanya mengikuti palet proyek: emerald untuk keadaan yang diinginkan,
 * amber untuk yang perlu perhatian, slate untuk yang netral atau selesai.
 */

const props = defineProps({
  warna: { type: String, default: 'slate' },
  titik: { type: Boolean, default: true },
  denyut: { type: Boolean, default: false },
})

const palet = {
  emerald: { kotak: 'bg-emerald-50 text-emerald-700', titik: 'bg-emerald-600' },
  amber: { kotak: 'bg-amber-50 text-amber-700', titik: 'bg-amber-600' },
  teal: { kotak: 'bg-teal-50 text-teal-700', titik: 'bg-teal-600' },
  navy: { kotak: 'bg-navy-50 text-navy-700', titik: 'bg-navy-600' },
  slate: { kotak: 'bg-slate-100 text-slate-600', titik: 'bg-slate-400' },
}

const gaya = computed(() => palet[props.warna] ?? palet.slate)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
    :class="gaya.kotak"
  >
    <span
      v-if="titik"
      class="h-1.5 w-1.5 rounded-full"
      :class="[gaya.titik, denyut && 'animate-pulse']"
    ></span>
    <slot />
  </span>
</template>
