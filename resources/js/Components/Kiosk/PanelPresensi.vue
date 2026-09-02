<script setup>
import { ref, watch } from 'vue'
import Lencana from '@/Components/UI/Lencana.vue'

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

/** Id pegawai yang barusan muncul, dilupakan lagi setelah sorotannya lewat. */
const baru = ref(new Set())

let dikenal = new Set(props.daftar.map((baris) => baris.pegawai_id))

watch(
  () => props.daftar,
  (daftar) => {
    const masuk = daftar
      .map((baris) => baris.pegawai_id)
      .filter((id) => !dikenal.has(id))

    dikenal = new Set(daftar.map((baris) => baris.pegawai_id))

    if (masuk.length === 0) return

    baru.value = new Set([...baru.value, ...masuk])

    setTimeout(() => {
      const sisa = new Set(baru.value)

      masuk.forEach((id) => sisa.delete(id))
      baru.value = sisa
    }, 2600)
  },
)
</script>

<template>
  <section class="flex flex-col overflow-hidden rounded-xl border border-garis bg-permukaan bayang">
    <div class="flex items-center justify-between border-b border-garis px-5 py-4">
      <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-redup">
        Daftar e-Presensi
      </h2>
      <span class="font-display text-sm tabular-nums text-utama">
        {{ daftar.length }} <span class="text-xs font-normal text-redup">hadir</span>
      </span>
    </div>

    <div class="gulir-halus flex-1 overflow-y-auto">
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
            v-for="(baris, urutan) in daftar"
            :key="baris.pegawai_id"
            class="transition-colors duration-300"
            :class="baru.has(baris.pegawai_id) ? 'baris-baru' : 'hover:bg-permukaan-hover'"
          >
            <td class="px-4 py-2.5 font-display tabular-nums text-redup">{{ urutan + 1 }}</td>
            <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ baris.nip }}</td>
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

          <tr v-if="daftar.length === 0">
            <td colspan="6" class="px-6 py-20 text-center text-sm text-redup">
              <template v-if="event">
                Belum ada yang absen pada event ini.
                <span class="mt-1 block text-xs">
                  Daftar bertambah otomatis setiap tap berhasil.
                </span>
              </template>
              <template v-else>
                Tidak ada event yang sedang dibuka untuk unit kerja ini.
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
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
