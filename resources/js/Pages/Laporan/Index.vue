<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03).
 */

const props = defineProps({
  baris: { type: Array, required: true },
  ringkasan: { type: Object, required: true },
  jumlah_event: { type: Number, required: true },
  unit_kerja: { type: Array, required: true },
  filter: { type: Object, required: true },
})

const filter = reactive({
  dari: props.filter.dari,
  sampai: props.filter.sampai,
  unit_kerja_id: props.filter.unit_kerja_id ?? '',
})

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, nilai]) => nilai !== '' && nilai !== null)),
)

function terapkan() {
  router.get('/admin/laporan', kueri.value, { preserveState: true, preserveScroll: true, replace: true })
}

function ekspor() {
  const kueriUrl = new URLSearchParams(kueri.value).toString()

  window.location.href = `/admin/laporan/ekspor?${kueriUrl}`
}

function cetak() {
  window.print()
}

function tanggalPanjang(iso) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

const persenKehadiran = (isi) =>
  isi.event_berlaku === 0 ? '—' : `${Math.round((isi.hadir / isi.event_berlaku) * 100)}%`
</script>

<template>
  <AdminLayout
    judul="Laporan Kehadiran"
    deskripsi="Rekap per pegawai untuk rentang tanggal dan unit kerja yang dipilih."
  >
    <!-- FR-LAP-01 -->
    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 print:hidden">
      <div>
        <label for="dari" class="block text-xs font-medium uppercase tracking-wider text-slate-500">Dari</label>
        <input
          id="dari"
          v-model="filter.dari"
          type="date"
          class="mt-1.5 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          @change="terapkan"
        />
      </div>
      <div>
        <label for="sampai" class="block text-xs font-medium uppercase tracking-wider text-slate-500">Sampai</label>
        <input
          id="sampai"
          v-model="filter.sampai"
          type="date"
          class="mt-1.5 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          @change="terapkan"
        />
      </div>
      <div class="lg:col-span-2">
        <label for="unit" class="block text-xs font-medium uppercase tracking-wider text-slate-500">Unit Kerja</label>
        <select
          id="unit"
          v-model="filter.unit_kerja_id"
          class="mt-1.5 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          @change="terapkan"
        >
          <option value="">Semua unit dalam cakupan</option>
          <option v-for="unit in unit_kerja" :key="unit.id" :value="unit.id">{{ unit.nama }}</option>
        </select>
      </div>
      <div class="flex items-end gap-2">
        <button
          type="button"
          class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
          @click="cetak"
        >
          Cetak
        </button>
        <button
          type="button"
          class="flex-1 rounded-md bg-teal-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700"
          @click="ekspor"
        >
          Ekspor
        </button>
      </div>
    </div>

    <!-- Kop laporan; ikut tercetak -->
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm print:border-0 print:shadow-none">
      <h2 class="font-display text-lg font-semibold text-navy-700">Rekap Kehadiran Pegawai</h2>
      <p class="mt-1 text-sm text-slate-600">
        {{ tanggalPanjang(filter.dari) }} — {{ tanggalPanjang(filter.sampai) }}
        · {{ jumlah_event }} event pada rentang ini
      </p>

      <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div v-for="item in [
          { label: 'Pegawai', nilai: ringkasan.pegawai, warna: 'text-navy-700' },
          { label: 'Total Hadir', nilai: ringkasan.hadir, warna: 'text-emerald-700' },
          { label: 'Total Terlambat', nilai: ringkasan.terlambat, warna: 'text-amber-700' },
          { label: 'Tanpa Keterangan', nilai: ringkasan.tanpa_keterangan, warna: 'text-slate-600' },
        ]" :key="item.label" class="rounded-md border border-slate-200 px-4 py-3">
          <dt class="text-xs uppercase tracking-wider text-slate-500">{{ item.label }}</dt>
          <dd class="mt-1 font-display text-2xl font-semibold tabular-nums" :class="item.warna">
            {{ item.nilai }}
          </dd>
        </div>
      </dl>
    </div>

    <!-- FR-LAP-02 -->
    <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm print:border-0 print:shadow-none">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-medium">No</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">NIP</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Unit Kerja</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Event</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Hadir</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Terlambat</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Tanpa Ket.</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">%</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="(isi, urutan) in baris" :key="isi.pegawai_id">
              <td class="px-4 py-2.5 font-display tabular-nums text-slate-500">{{ urutan + 1 }}</td>
              <td class="px-4 py-2.5 font-display tabular-nums text-slate-600">{{ isi.nip }}</td>
              <td class="px-4 py-2.5 font-medium text-navy-700">{{ isi.nama }}</td>
              <td class="px-4 py-2.5 text-slate-600">{{ isi.unit_kerja ?? '—' }}</td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums text-slate-500">{{ isi.event_berlaku }}</td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums text-emerald-700">{{ isi.hadir }}</td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums" :class="isi.terlambat > 0 ? 'text-amber-700' : 'text-slate-400'">
                {{ isi.terlambat }}
              </td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums" :class="isi.tanpa_keterangan > 0 ? 'text-slate-700' : 'text-slate-400'">
                {{ isi.tanpa_keterangan }}
              </td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums text-slate-600">{{ persenKehadiran(isi) }}</td>
            </tr>

            <tr v-if="baris.length === 0">
              <td colspan="9" class="px-6 py-14 text-center text-sm text-slate-500">
                Tidak ada pegawai pada cakupan dan rentang ini.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>

<style>
@media print {
  @page {
    margin: 14mm;
    size: landscape;
  }

  body {
    background: #fff;
  }
}
</style>
