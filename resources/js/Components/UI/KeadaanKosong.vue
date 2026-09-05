<script setup>
import Ikon from '@/Components/Ikon.vue'

/**
 * Keadaan kosong dengan ikon dan penjelasan.
 *
 * Membedakan "belum ada data" dari "tidak ada yang cocok dengan filter"
 * penting: keduanya terlihat sama di tabel kosong, tetapi tindakan yang
 * diharapkan dari pengguna berbeda.
 */

defineProps({
  ikon: { type: String, default: 'kosong' },
  judul: { type: String, required: true },
  keterangan: { type: String, default: '' },

  // Nada warna ubin ikonnya: teal, emerald, amber, biru, atau langit.
  nada: { type: String, default: 'biru' },
})
</script>

<template>
  <!--
    Keadaan kosong bukan kegagalan, jadi ubinnya berwarna seperti ubin ikon
    lain di aplikasi — bukan bulatan abu-abu yang membuat halaman terbaca
    seolah rusak. Warnanya tetap pucat supaya tidak bersaing dengan isi
    sungguhan ketika datanya kelak terisi.
  -->
  <div class="masuk flex flex-col items-center px-6 py-12 text-center">
    <span class="ubin-ikon h-14 w-14" :class="`nada-${nada}`">
      <Ikon :nama="ikon" ukuran="h-7 w-7" />
    </span>

    <p class="mt-4 font-display text-base font-semibold text-utama">{{ judul }}</p>
    <p v-if="keterangan" class="mt-1.5 max-w-sm text-sm text-redup">{{ keterangan }}</p>

    <div class="mt-5"><slot /></div>
  </div>
</template>
