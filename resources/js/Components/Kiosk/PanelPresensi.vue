<script setup>
import { computed, ref, watch } from 'vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import PaginasiLokal from '@/Components/UI/PaginasiLokal.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'

/**
 * Panel Daftar e-Presensi (UIUX §4.2.2).
 *
 * Satu baris per pegawai: tap "Pulang" memperbarui baris yang sama, bukan
 * menambah baris baru.
 *
 * Baris yang baru masuk ditandai sebentar. Daftar ini bertambah sendiri
 * setiap sepuluh detik, termasuk dari perangkat lain di ruangan yang sama;
 * tanpa penanda, petugas harus membandingkan daftar dengan ingatannya.
 */

const props = defineProps({
  daftar: { type: Array, required: true },
  event: { type: Object, default: null },
})

/*
 * Ringkasan dihitung di sini, bukan diminta ke server: seluruh barisnya sudah
 * ada di layar, dan satu perjalanan tambahan hanya untuk menjumlahkan tiga
 * angka akan memperlambat penarikan berkala tanpa menambah apa pun.
 */
const tepat = computed(
  () => props.daftar.filter((baris) => baris.status_ketepatan === 'tepat').length,
)

const terlambat = computed(
  () => props.daftar.filter((baris) => baris.status_ketepatan === 'terlambat').length,
)

const sudahPulang = computed(() => props.daftar.filter((baris) => baris.jam_pulang).length)

/*
 * Pencarian dan paginasi dilakukan di sisi klien: seluruh barisnya sudah
 * ditarik utuh setiap sepuluh detik dan berada di layar, sehingga menyaringnya
 * tidak perlu perjalanan ke server — dan pada layar titik absen, perjalanan
 * yang tidak perlu itu terasa sebagai jeda tepat ketika antrean sedang panjang.
 */
const PER_HALAMAN = 8

const cari = ref('')
const halaman = ref(1)

const tersaring = computed(() => {
  const kunci = cari.value.trim().toLowerCase()

  if (kunci === '') return props.daftar

  return props.daftar.filter(
    (baris) => baris.nama.toLowerCase().includes(kunci) || String(baris.nip).includes(kunci),
  )
})

const barisTampil = computed(() =>
  tersaring.value.slice((halaman.value - 1) * PER_HALAMAN, halaman.value * PER_HALAMAN),
)

/* Menyaring dari halaman lima lalu menyisakan dua baris akan tampil kosong. */
watch(cari, () => (halaman.value = 1))

/** Id pegawai yang barusan muncul, dilupakan lagi setelah sorotannya lewat. */
const baru = ref(new Set())

let dikenal = new Set(props.daftar.map((baris) => baris.pegawai_id))

watch(
  () => props.daftar,
  (daftar) => {
    const masuk = daftar.map((baris) => baris.pegawai_id).filter((id) => !dikenal.has(id))

    dikenal = new Set(daftar.map((baris) => baris.pegawai_id))

    if (masuk.length === 0) return

    baru.value = new Set([...baru.value, ...masuk])

    /*
     * Lompat ke halaman yang MEMUAT entri barunya.
     *
     * Orang yang barusan menempelkan kartunya berdiri di depan layar menunggu
     * namanya muncul, dan daftar ini urut menurut waktu kedatangan — sehingga
     * yang baru justru berada di halaman terakhir, bukan halaman pertama.
     * Kembali ke halaman satu di sini akan menampilkan orang yang datang pada
     * jam tujuh pagi, tepat pada saat orang yang baru saja tap mencari
     * namanya.
     *
     * Urutan kedatangannya sengaja tidak dibalik: nomor urut pada daftar ini
     * berarti urutan hadir, dan itu yang sama dengan yang tercetak di rekap.
     */
    const barisBaru = tersaring.value.findIndex((isi) => masuk.includes(isi.pegawai_id))

    if (barisBaru >= 0) halaman.value = Math.floor(barisBaru / PER_HALAMAN) + 1

    setTimeout(() => {
      const sisa = new Set(baru.value)

      masuk.forEach((id) => sisa.delete(id))
      baru.value = sisa
    }, 2600)
  },
)
</script>

<template>
  <!--
    Selagi kosong, panel menyusut mengikuti isinya (`self-start`) alih-alih
    meregang setinggi baris grid. Panel setinggi layar yang isinya satu ikon
    adalah ruang kosong yang tak terkendali; begitu ada baris, ia kembali
    meregang supaya daftarnya punya ruang gulir.
  -->
  <section class="flex flex-col overflow-hidden panel" :class="daftar.length === 0 && 'self-start'">
    <!--
      Ringkasan di kepala panel. Selama berjam-jam pertama daftar ini kosong
      atau berisi beberapa baris saja, dan angka yang dicari petugas —
      "sudah berapa yang masuk, berapa yang terlambat" — sebelumnya harus
      dihitung sendiri dari barisnya.
    -->
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-garis px-5 py-4">
      <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-redup">
        Daftar e-Presensi
      </h2>

      <dl class="flex items-center gap-x-5 text-sm">
        <div class="flex items-baseline gap-1.5">
          <dd class="font-display font-semibold tabular-nums text-utama">
            {{ daftar.length }}
          </dd>
          <dt class="text-xs text-redup">hadir</dt>
        </div>

        <div v-if="tepat > 0" class="flex items-baseline gap-1.5">
          <dd class="font-display font-semibold tabular-nums text-berhasil-teks">
            {{ tepat }}
          </dd>
          <dt class="text-xs text-redup">tepat</dt>
        </div>

        <div v-if="terlambat > 0" class="flex items-baseline gap-1.5">
          <dd class="font-display font-semibold tabular-nums text-peringatan-teks">
            {{ terlambat }}
          </dd>
          <dt class="text-xs text-redup">terlambat</dt>
        </div>

        <div v-if="sudahPulang > 0" class="flex items-baseline gap-1.5">
          <dd class="font-display font-semibold tabular-nums text-sekunder">
            {{ sudahPulang }}
          </dd>
          <dt class="text-xs text-redup">pulang</dt>
        </div>
      </dl>

      <!--
        Kolom cari baru muncul setelah daftarnya melampaui satu halaman.
        Menyediakan kotak pencarian di atas tiga baris hanya menambah satu
        elemen yang tidak akan pernah dipakai.
      -->
      <KolomCari
        v-if="daftar.length > PER_HALAMAN"
        v-model="cari"
        :jeda="0"
        placeholder="Cari nama atau NIP…"
        class="w-full min-w-[9rem] sm:ml-auto sm:w-56"
      />
    </div>

    <!--
      Keadaan kosong menggantikan seluruh tabel, bukan mengisi satu selnya.
      Kepala tabel yang berdiri di atas badan kosong terbaca sebagai tabel yang
      gagal memuat — padahal pada jam-jam pertama ini keadaan yang wajar.
    -->
    <!--
      Tingginya dibatasi, tidak dibiarkan meregang mengisi sisa panel: ikon
      kecil yang mengambang di tengah kanvas kosong setinggi layar terbaca
      sebagai kegagalan memuat, bukan sebagai "memang belum ada".
    -->
    <KeadaanKosong
      v-if="daftar.length === 0"
      class="py-10"
      :ikon="event ? 'jam' : 'kosong'"
      :nada="event ? 'teal' : 'biru'"
      :judul="event ? 'Belum ada yang absen' : 'Belum ada entry yang dibuka'"
      :keterangan="
        event
          ? 'Daftar bertambah sendiri setiap tap berhasil, termasuk dari titik absen lain pada event yang sama.'
          : 'Tidak ada event yang sedang dibuka untuk unit kerja ini.'
      "
    />

    <!--
      Hasil pencarian kosong dibedakan dari daftar yang memang belum terisi:
      keduanya menampilkan tabel tanpa baris, tetapi yang satu diperbaiki
      dengan menghapus kata kuncinya dan yang satu dengan menunggu.
    -->
    <KeadaanKosong
      v-else-if="tersaring.length === 0"
      class="py-10"
      ikon="cari"
      nada="biru"
      judul="Tidak ada yang cocok"
      :keterangan="`Tidak ada nama atau NIP yang memuat “${cari.trim()}” pada daftar hari ini.`"
    />

    <div v-else class="gulir-halus flex-1 overflow-y-auto">
      <table class="min-w-full text-sm">
        <thead
          class="sticky top-0 z-10 bg-permukaan-2 text-xs uppercase tracking-wider text-redup backdrop-blur"
        >
          <tr>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">No</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">NIP</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Nama</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Masuk</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Pulang</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Foto</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-garis">
          <tr
            v-for="(baris, urutan) in barisTampil"
            :key="baris.pegawai_id"
            class="transition-colors duration-300"
            :class="baru.has(baris.pegawai_id) ? 'baris-baru' : 'hover:bg-permukaan-hover'"
          >
            <td class="px-4 py-2.5 font-display tabular-nums text-redup">
              {{ (halaman - 1) * PER_HALAMAN + urutan + 1 }}
            </td>
            <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">
              {{ baris.nip }}
            </td>
            <td class="px-4 py-2.5 font-medium text-utama">
              {{ baris.nama }}
              <Lencana
                v-if="baris.status_ketepatan === 'terlambat'"
                warna="amber"
                :titik="false"
                :baru="baru.has(baris.pegawai_id)"
                class="ml-1.5"
              >
                Terlambat
              </Lencana>
            </td>
            <td class="px-4 py-2.5 font-display tabular-nums text-berhasil-teks">
              {{ baris.jam_masuk ?? '—' }}
            </td>
            <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">
              {{ baris.jam_pulang ?? '—' }}
            </td>
            <td class="px-4 py-2.5">
              <img
                v-if="baris.foto_url"
                :src="baris.foto_url"
                :alt="`Foto absen ${baris.nama}`"
                class="h-9 w-9 rounded-md border border-garis object-cover"
              />
              <span v-else class="text-redup">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginasiLokal
      v-if="tersaring.length > 0"
      v-model:halaman="halaman"
      :total="tersaring.length"
      :total-asli="daftar.length"
      :per-halaman="PER_HALAMAN"
    />
  </section>
</template>

<style>
/*
 * Baris yang baru masuk menyala sebentar lalu meredup sendiri — cukup untuk
 * menarik mata, tidak cukup lama untuk mengganggu pembacaan daftar.
 */
@keyframes baris-masuk {
  0% {
    background-color: var(--tema-aksen-lembut);
  }

  70% {
    background-color: var(--tema-aksen-lembut);
  }

  100% {
    background-color: transparent;
  }
}

.baris-baru {
  animation: baris-masuk 2600ms ease-out;
}
</style>
