<script setup>
import { computed } from 'vue'
import Paginasi from '@/Components/UI/Paginasi.vue'
import PaginasiLokal from '@/Components/UI/PaginasiLokal.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'

/**
 * Rangka tabel bersama untuk seluruh daftar di panel admin.
 *
 * Setiap halaman daftar sebelumnya menuliskan sendiri rangka yang sama:
 * pembungkus bergulir, kepala tabel yang menempel, garis pemisah baris,
 * keadaan kosong, dan navigasi halaman di kakinya. Lima salinan dari satu
 * rangka berarti lima tempat yang harus diingat ketika salah satunya
 * diperbaiki — dan tempat yang terlupakan tidak akan terlihat sampai
 * seseorang membuka halaman itu.
 *
 * Yang tinggal di halaman hanyalah isi selnya, lewat slot `baris`. Rangkanya,
 * termasuk penomoran yang benar pada halaman kedua dan seterusnya, dipegang di
 * sini.
 *
 * Kakinya melayani dua macam paginasi karena datanya memang datang dari dua
 * arah: `paginator` untuk daftar yang dipotong di server (Kelola Pegawai,
 * Daftar Event), dan `total` untuk daftar yang sudah utuh di layar dan
 * disaring di peramban (Rekap Absen). Halaman tidak perlu tahu bedanya.
 */

const props = defineProps({
  /**
   * Kepala tabel. Satu entri per kolom:
   * `{ label, kelas?, cetak? }` — `cetak: false` menyembunyikannya saat cetak.
   *
   * @type {import('vue').PropType<Array<{label: string, kelas?: string, cetak?: boolean}>>}
   */
  kolom: { type: Array, required: true },

  /** Baris yang hendak digambar, sudah dalam urutan tampilnya. */
  baris: { type: Array, required: true },

  /** Nama field kunci, atau fungsi yang mengembalikan kuncinya. */
  kunci: { type: [String, Function], default: 'id' },

  /**
   * Kelas tambahan pada pembungkus bergulir — `tabel-aksi` untuk tabel yang
   * kolom aksinya menempel di tepi kanan saat digulir mendatar.
   */
  kelasGulir: { type: String, default: '' },

  /**
   * Kelas per baris, misalnya untuk meredupkan baris yang nonaktif.
   *
   * @type {import('vue').PropType<(isi: any) => unknown>}
   */
  kelasBaris: { type: Function, default: null },

  /** Paginator Laravel, bila potongannya dikerjakan server. */
  paginator: { type: Object, default: null },

  /** Jumlah seluruh baris hasil saringan, bila paginasinya di peramban. */
  total: { type: Number, default: null },

  /** Jumlah baris sebelum disaring, untuk keterangan "disaring dari". */
  totalAsli: { type: Number, default: null },

  perHalaman: { type: Number, default: 25 },

  /** Keadaan kosong bawaan; dapat diganti lewat slot `kosong`. */
  ikonKosong: { type: String, default: 'kosong' },
  judulKosong: { type: String, default: 'Belum ada data' },
  keteranganKosong: { type: String, default: '' },
})

const halaman = defineModel('halaman', { type: Number, default: 1 })

/**
 * Nomor urut baris pertama pada halaman ini.
 *
 * Penomoran yang mengulang dari 1 di setiap halaman adalah cacat yang paling
 * mudah lolos: halaman pertama selalu benar, dan halaman kedua jarang dibuka
 * saat memeriksa.
 */
const awal = computed(() => {
  if (props.paginator) return props.paginator.from ?? 1

  return (halaman.value - 1) * props.perHalaman + 1
})

const kunciBaris = (isi, urutan) =>
  typeof props.kunci === 'function' ? props.kunci(isi) : (isi[props.kunci] ?? urutan)
</script>

<template>
  <div class="overflow-hidden panel print:border-0 print:shadow-none">
    <div v-if="baris.length > 0" class="tabel-gulir gulir-halus" :class="kelasGulir">
      <table class="min-w-full divide-y divide-garis text-sm">
        <thead
          class="border-b border-garis bg-permukaan-2 text-xs uppercase tracking-wider text-redup"
        >
          <tr>
            <th
              v-for="(kepala, nomor) in kolom"
              :key="nomor"
              scope="col"
              class="px-4 py-3 text-left font-medium"
              :class="[kepala.kelas, kepala.cetak === false && 'print:hidden']"
            >
              {{ kepala.label }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-garis">
          <tr
            v-for="(isi, urutan) in baris"
            :key="kunciBaris(isi, urutan)"
            class="transition-colors hover:bg-permukaan-hover"
            :class="kelasBaris?.(isi)"
          >
            <slot name="baris" :isi="isi" :urutan="urutan" :nomor="awal + urutan" />
          </tr>
        </tbody>
      </table>
    </div>

    <!--
      Keadaan kosong menggantikan seluruh tabel, bukan mengisi satu selnya:
      kepala tabel yang berdiri di atas badan kosong terbaca sebagai tabel yang
      gagal memuat.
    -->
    <slot v-else name="kosong">
      <KeadaanKosong :ikon="ikonKosong" :judul="judulKosong" :keterangan="keteranganKosong" />
    </slot>

    <slot name="kaki">
      <Paginasi v-if="paginator" :data="paginator" />
      <PaginasiLokal
        v-else-if="total !== null"
        v-model:halaman="halaman"
        :total="total"
        :total-asli="totalAsli"
        :per-halaman="perHalaman"
        class="print:hidden"
      />
    </slot>
  </div>
</template>
