<script setup>
import { computed } from 'vue'
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/vue'
import Ikon from '@/Components/Ikon.vue'

/**
 * Dropdown pengganti `<select>` native.
 *
 * `<select>` tidak dapat ditata: peramban menggambar daftar opsinya sendiri,
 * sehingga pada mode gelap ia muncul sebagai kotak putih asing di tengah
 * halaman. Komponen ini menggambar daftarnya sendiri dengan token tema yang
 * sama seperti sisa aplikasi, sambil mempertahankan perilaku papan ketik
 * bawaan `<select>` — panah untuk berpindah, ketik untuk melompat, Esc untuk
 * menutup — lewat Headless UI.
 *
 * Bentuk opsi: `{ nilai, label, keterangan? }`.
 */

const props = defineProps({
  opsi: { type: Array, required: true },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Pilih…' },
  id: { type: String, default: undefined },

  // Lebar daftar mengikuti tombol; matikan bila labelnya panjang dan tombolnya
  // sempit, misalnya penyaring yang berdampingan di satu baris.
  lebarPenuh: { type: Boolean, default: true },
})

const model = defineModel({ type: [String, Number, null], default: '' })

const terpilih = computed(() => props.opsi.find((o) => o.nilai === model.value) ?? null)
</script>

<template>
  <div>
    <label
      v-if="label"
      :for="id"
      class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup"
    >
      {{ label }}
    </label>

    <Listbox v-slot="{ open }" v-model="model" as="div" class="relative">
      <ListboxButton
        :id="id"
        class="flex w-full items-center justify-between gap-2 rounded-lg border bg-permukaan px-3 py-2 text-left text-sm text-utama transition-colors duration-150 focus:outline-none"
        :class="
          open
            ? 'border-aksen ring-1 ring-aksen'
            : 'border-garis hover:border-garis-kuat focus:border-aksen focus:ring-1 focus:ring-aksen'
        "
      >
        <span class="truncate" :class="terpilih ? 'text-utama' : 'text-redup'">
          {{ terpilih?.label ?? placeholder }}
        </span>

        <!-- Panah berputar setengah lingkaran saat daftar terbuka. -->
        <Ikon
          nama="bawah"
          ukuran="h-4 w-4"
          class="shrink-0 text-redup transition-transform duration-200"
          :class="open && 'rotate-180'"
        />
      </ListboxButton>

      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="-translate-y-1 scale-[0.98] opacity-0"
        enter-to-class="translate-y-0 scale-100 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="translate-y-0 scale-100 opacity-100"
        leave-to-class="-translate-y-1 scale-[0.98] opacity-0"
      >
        <ListboxOptions
          class="gulir-halus absolute z-50 mt-2 max-h-72 origin-top overflow-auto rounded-lg border border-garis bg-permukaan p-1 shadow-lg focus:outline-none"
          :class="lebarPenuh ? 'w-full' : 'min-w-full'"
        >
          <ListboxOption
            v-for="item in opsi"
            v-slot="{ active, selected }"
            :key="String(item.nilai)"
            :value="item.nilai"
            as="template"
          >
            <li
              class="flex cursor-pointer items-start gap-2 rounded-md px-3 py-2 text-sm transition-colors duration-100"
              :class="[
                active ? 'bg-aksen-lembut text-aksen-teks' : 'text-sekunder',
                selected && 'font-medium',
              ]"
            >
              <span class="min-w-0 flex-1">
                <span class="block truncate">{{ item.label }}</span>
                <span v-if="item.keterangan" class="mt-0.5 block truncate text-xs text-redup">
                  {{ item.keterangan }}
                </span>
              </span>
              <Ikon v-if="selected" nama="cek" ukuran="h-4 w-4 mt-0.5" />
            </li>
          </ListboxOption>

          <li v-if="opsi.length === 0" class="px-3 py-2 text-sm text-redup">Tidak ada pilihan.</li>
        </ListboxOptions>
      </Transition>
    </Listbox>
  </div>
</template>
