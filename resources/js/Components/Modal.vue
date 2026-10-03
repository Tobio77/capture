<script setup>
import { computed } from 'vue'
import Ikon from '@/Components/Ikon.vue'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/Components/shadcn/dialog'

/**
 * Dialog admin.
 *
 * Tampilannya tidak berubah dari versi sebelumnya — tirai navy, panel putih
 * bersudut, kepala bergaris, kaki untuk tombol aksi. Yang berganti adalah
 * mesin di baliknya: dari `Teleport` + `Transition` buatan sendiri menjadi
 * primitif Dialog milik Reka UI (lewat shadcn-vue).
 *
 * Alasannya bukan gaya, melainkan tiga hal yang tidak dipunyai versi lama:
 *
 *   1. JEBAKAN FOKUS. Sebelumnya Tab dari isian terakhir di dalam dialog
 *      melompat ke tautan sidebar di belakang tirai — pengguna papan ketik
 *      bisa mengisi formulir di balik lapisan yang menutupinya.
 *   2. PENGEMBALIAN FOKUS. Setelah dialog ditutup, fokus kembali ke tombol
 *      yang membukanya, bukan lompat ke awal halaman.
 *   3. DIALOG BERTUMPUK. Penangan Escape yang lama dipasang per instance ke
 *      `document`, sehingga satu tekan Escape menutup SEMUA dialog yang
 *      terbuka sekaligus. Reka UI hanya menutup yang paling atas.
 *
 * Kunci gulir halaman juga tidak lagi menimpa `document.body.style.overflow`
 * begitu saja — yang lama mengembalikannya ke string kosong saat menutup,
 * menghapus nilai apa pun yang mungkin sudah dipasang pihak lain.
 *
 * Isi yang terlalu tinggi kini bergulir DI DALAM panel (`max-h-[85vh]` pada
 * panel, `overflow-y-auto` pada badan), bukan menggulirkan seluruh tirai.
 * Kepala dan kaki karena itu tetap terlihat pada formulir yang panjang.
 */

const props = defineProps({
  terbuka: { type: Boolean, default: false },
  judul: { type: String, required: true },
  lebar: { type: String, default: 'max-w-lg' },

  /**
   * Kalimat penjelas di bawah judul.
   *
   * Bila kosong, judulnya dipakai ulang sebagai keterangan tersembunyi.
   * Reka UI menuntut setiap dialog punya keterangan yang dapat dibacakan
   * pembaca layar; tanpa itu ia memperingatkan di konsol dan dialognya
   * kehilangan `aria-describedby`.
   */
  keterangan: { type: String, default: '' },
})

/*
 * Lebar panel, dipasang pada breakpoint `sm:`.
 *
 * DialogContent shadcn membawa `sm:max-w-lg` bawaan. Lebar tanpa awalan
 * (`max-w-2xl`) tidak pernah dapat mengalahkannya, dan menimpanya dengan
 * `sm:max-w-none` justru membuat setiap dialog melebar sampai tepi layar di
 * desktop. Karena itu lebar dipetakan ke versi `sm:`-nya di sini. Kelasnya
 * ditulis utuh supaya terbaca pemindai Tailwind; di bawah `sm` panel tetap
 * selebar layar dikurangi tepi bawaan DialogContent.
 */
const LEBAR = {
  'max-w-md': 'sm:max-w-md',
  'max-w-lg': 'sm:max-w-lg',
  'max-w-xl': 'sm:max-w-xl',
  'max-w-2xl': 'sm:max-w-2xl',
  'max-w-3xl': 'sm:max-w-3xl',
  'max-w-4xl': 'sm:max-w-4xl',
}

const kelasLebar = computed(() => LEBAR[props.lebar] ?? 'sm:max-w-lg')

const emit = defineEmits(['tutup'])

/** Reka UI mengabarkan buka/tutup lewat satu kanal; kita hanya perlu tutupnya. */
const perubahanBuka = (terbuka) => {
  if (!terbuka) emit('tutup')
}
</script>

<template>
  <Dialog :open="terbuka" @update:open="perubahanBuka">
    <DialogContent
      :show-close-button="false"
      :class="['flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0', kelasLebar]"
    >
      <div class="flex items-start justify-between border-b border-garis px-6 py-4">
        <div class="min-w-0">
          <DialogTitle class="text-base">{{ judul }}</DialogTitle>

          <DialogDescription v-if="keterangan" class="mt-1 text-sm text-sekunder">
            {{ keterangan }}
          </DialogDescription>
          <DialogDescription v-else class="sr-only">{{ judul }}</DialogDescription>
        </div>

        <DialogClose
          class="-mr-1 shrink-0 rounded-md p-1.5 text-redup transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
          aria-label="Tutup"
        >
          <Ikon nama="tutup" ukuran="h-5 w-5" />
        </DialogClose>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-5">
        <slot />
      </div>

      <div v-if="$slots.aksi" class="flex justify-end gap-3 border-t border-garis px-6 py-4">
        <slot name="aksi" />
      </div>
    </DialogContent>
  </Dialog>
</template>
