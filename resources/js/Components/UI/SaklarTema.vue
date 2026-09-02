<script setup>
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue'
import Ikon from '@/Components/Ikon.vue'
import { useTema } from '@/Composables/useTema'

/**
 * Pemilih tema: Terang, Gelap, atau mengikuti Sistem.
 *
 * Tiga pilihan eksplisit, bukan sakelar dua keadaan, karena "ikuti sistem"
 * adalah pilihan tersendiri yang tidak dapat diwakili sakelar: ia bukan
 * terang maupun gelap, melainkan janji untuk berubah sendiri.
 */

defineProps({
  // Sidebar memakai latar navy pada kedua tema, sehingga tombolnya perlu
  // varian terang; halaman biasa memakai varian permukaan.
  varian: { type: String, default: 'permukaan' },
})

const { mode, efektif, pilih, MODE } = useTema()

const ikonSekarang = () =>
  mode.value === 'sistem' ? 'perangkat' : efektif.value === 'gelap' ? 'bulan' : 'matahari'
</script>

<template>
  <Listbox :model-value="mode" as="div" class="relative" @update:model-value="pilih">
    <ListboxButton
      class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-2 text-xs font-medium transition-colors duration-150"
      :class="
        varian === 'sidebar'
          ? 'border-sidebar-garis text-sidebar-redup hover:bg-white/10 hover:text-sidebar-teks'
          : 'border-garis bg-permukaan text-sekunder hover:bg-permukaan-hover hover:text-utama'
      "
      :aria-label="`Tema tampilan: ${MODE.find((m) => m.nilai === mode)?.label}`"
    >
      <Ikon :nama="ikonSekarang()" ukuran="h-4 w-4" />
      <span class="hidden sm:inline">{{ MODE.find((m) => m.nilai === mode)?.label }}</span>
    </ListboxButton>

    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="-translate-y-1 scale-95 opacity-0"
      enter-to-class="translate-y-0 scale-100 opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="translate-y-0 scale-100 opacity-100"
      leave-to-class="-translate-y-1 scale-95 opacity-0"
    >
      <ListboxOptions
        class="absolute right-0 z-50 mt-2 w-40 origin-top-right overflow-hidden rounded-lg border border-garis bg-permukaan p-1 shadow-lg focus:outline-none"
      >
        <ListboxOption
          v-for="item in MODE"
          v-slot="{ active, selected }"
          :key="item.nilai"
          :value="item.nilai"
          as="template"
        >
          <li
            class="flex cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-sm transition-colors duration-100"
            :class="[
              active ? 'bg-aksen-lembut text-aksen-teks' : 'text-sekunder',
              selected && 'font-medium',
            ]"
          >
            <Ikon :nama="item.ikon" ukuran="h-4 w-4" />
            <span class="flex-1">{{ item.label }}</span>
            <Ikon v-if="selected" nama="cek" ukuran="h-3.5 w-3.5" />
          </li>
        </ListboxOption>
      </ListboxOptions>
    </Transition>
  </Listbox>
</template>
