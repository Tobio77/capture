<script setup>
/**
 * Panel Daftar e-Presensi (UIUX §4.2.2).
 *
 * Satu baris per pegawai: tap "Pulang" memperbarui baris yang sama, bukan
 * menambah baris baru. Pengisian datanya menyusul bersama penyimpanan absen
 * (S16) dan pembaruan berkala (S21).
 */

defineProps({
  daftar: { type: Array, required: true },
  event: { type: Object, default: null },
})
</script>

<template>
  <section class="flex flex-col rounded-lg border border-white/10 bg-white/5">
    <div class="flex items-center justify-between border-b border-white/10 px-5 py-4">
      <h2 class="font-display text-sm font-semibold uppercase tracking-wider text-slate-400">
        Daftar e-Presensi
      </h2>
      <span class="font-display text-sm tabular-nums text-slate-300">
        {{ daftar.length }} <span class="text-xs text-slate-500">hadir</span>
      </span>
    </div>

    <div class="flex-1 overflow-y-auto">
      <table class="min-w-full text-sm">
        <thead class="sticky top-0 bg-slate-900/95 text-xs uppercase tracking-wider text-slate-500 backdrop-blur">
          <tr>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">No</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">NIP</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Nama</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Masuk</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Pulang</th>
            <th scope="col" class="px-4 py-2.5 text-left font-medium">Foto</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-white/5">
          <tr v-for="(baris, urutan) in daftar" :key="baris.pegawai_id">
            <td class="px-4 py-2.5 font-display tabular-nums text-slate-500">{{ urutan + 1 }}</td>
            <td class="px-4 py-2.5 font-display tabular-nums text-slate-300">{{ baris.nip }}</td>
            <td class="px-4 py-2.5 text-slate-100">
              {{ baris.nama }}
              <span
                v-if="baris.status_ketepatan === 'terlambat'"
                class="ml-1.5 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs text-amber-400"
              >
                Terlambat
              </span>
            </td>
            <td class="px-4 py-2.5 font-display tabular-nums text-emerald-400">{{ baris.jam_masuk ?? '—' }}</td>
            <td class="px-4 py-2.5 font-display tabular-nums text-slate-300">{{ baris.jam_pulang ?? '—' }}</td>
            <td class="px-4 py-2.5">
              <img
                v-if="baris.foto_url"
                :src="baris.foto_url"
                :alt="`Foto absen ${baris.nama}`"
                class="h-8 w-8 rounded object-cover"
              />
              <span v-else class="text-slate-600">—</span>
            </td>
          </tr>

          <tr v-if="daftar.length === 0">
            <td colspan="6" class="px-6 py-16 text-center text-sm text-slate-500">
              <template v-if="event">
                Belum ada yang absen pada event ini.
                <span class="mt-1 block text-xs">Daftar bertambah otomatis setiap tap berhasil.</span>
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
