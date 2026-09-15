<script setup>
import { computed, ref } from 'vue'
import {
  Combobox,
  ComboboxButton,
  ComboboxInput,
  ComboboxLabel,
  ComboboxOption,
  ComboboxOptions,
} from '@headlessui/vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Pemilih event dapat-dicari — pengganti `Pilihan.vue` (dropdown datar)
 * khusus untuk daftar event, yang bisa memanjang sampai puluhan/ratusan
 * baris seiring waktu tanpa satu pun cara menyaringnya (Bagian 4, revisi
 * pemilih event).
 *
 * Beda dari `Pilihan.vue`: tombolnya SENDIRI adalah kolom ketik (Headless UI
 * `Combobox`, bukan `Listbox`) — mengetik menyaring daftar seketika di
 * peramban, tanpa perjalanan ke server, sebab seluruh opsi memang sudah
 * dikirim sekali di awal.
 *
 * Bentuk opsi: `{ nilai, label, keterangan? }` — sama persis dengan
 * `Pilihan.vue`, supaya kedua komponen dapat menerima sumber data yang sama.
 */

const props = defineProps({
  opsi: { type: Array, required: true },
  placeholder: { type: String, default: 'Cari event…' },
  id: { type: String, default: undefined },

  /*
   * Dirender lewat `ComboboxLabel` (disembunyikan visual, `sr-only`), BUKAN
   * `<label for>` biasa di luar komponen ini: `ComboboxInput` Headless UI
   * menyetel `aria-labelledby` sendiri, dan tanpa `ComboboxLabel` ia jatuh
   * ke id `ComboboxButton` — tombol panah tanpa teks — sehingga nama
   * aksesibilitasnya kosong diam-diam, `aria-label` manual sekalipun kalah
   * dari `aria-labelledby` yang sudah terpasang.
   */
  label: { type: String, default: undefined },
})

const model = defineModel({ type: [String, Number, null], default: '' })

const kueri = ref('')

const terpilih = computed(() => props.opsi.find((o) => o.nilai === model.value) ?? null)

const tersaring = computed(() => {
  const kunci = kueri.value.trim().toLowerCase()

  if (kunci === '') return props.opsi

  return props.opsi.filter((o) => o.label.toLowerCase().includes(kunci))
})

// Ditutup dengan label yang sedang terpilih, bukan kata kunci pencarian yang
// baru saja diketik — sama seperti `<select>` native, isiannya kembali
// menyatakan PILIHAN, bukan riwayat pengetikan.
function tutup() {
  kueri.value = ''
}
</script>

<template>
  <Combobox v-slot="{ open }" v-model="model" as="div" class="relative" @update:model-value="tutup">
    <ComboboxLabel v-if="label" class="sr-only">{{ label }}</ComboboxLabel>

    <div class="relative">
      <ComboboxInput
        :id="id"
        class="w-full rounded-lg border bg-permukaan py-2 pl-3 pr-9 text-sm text-utama transition-colors duration-150 focus:outline-none"
        :class="
          open
            ? 'border-aksen ring-1 ring-aksen'
            : 'border-garis hover:border-garis-kuat focus:border-aksen focus:ring-1 focus:ring-aksen'
        "
        :placeholder="placeholder"
        :display-value="() => terpilih?.label ?? ''"
        @change="kueri = $event.target.value"
      />

      <ComboboxButton class="absolute inset-y-0 right-0 flex items-center pr-3">
        <Ikon
          nama="bawah"
          ukuran="h-4 w-4"
          class="shrink-0 text-redup transition-transform duration-200"
          :class="open && 'rotate-180'"
        />
      </ComboboxButton>
    </div>

    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="-translate-y-1 scale-[0.98] opacity-0"
      enter-to-class="translate-y-0 scale-100 opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="translate-y-0 scale-100 opacity-100"
      leave-to-class="-translate-y-1 scale-[0.98] opacity-0"
    >
      <ComboboxOptions
        class="gulir-halus absolute z-50 mt-2 max-h-72 w-full origin-top overflow-auto rounded-lg border border-garis bg-permukaan p-1 shadow-lg focus:outline-none"
      >
        <ComboboxOption
          v-for="item in tersaring"
          v-slot="{ active, selected }"
          :key="String(item.nilai)"
          :value="item.nilai"
          as="template"
        >
          <li
            class="flex cursor-pointer items-start gap-2 rounded-md px-3 py-2 text-sm transition-colors duration-100"
            :class="[active ? 'bg-aksen-lembut text-aksen-teks' : 'text-sekunder', selected && 'font-medium']"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate">{{ item.label }}</span>
              <span v-if="item.keterangan" class="mt-0.5 block truncate text-xs text-redup">
                {{ item.keterangan }}
              </span>
            </span>
            <Ikon v-if="selected" nama="cek" ukuran="h-4 w-4 mt-0.5" />
          </li>
        </ComboboxOption>

        <li v-if="tersaring.length === 0" class="px-3 py-2 text-sm text-redup">
          Tidak ada event yang cocok.
        </li>
      </ComboboxOptions>
    </Transition>
  </Combobox>
</template>
