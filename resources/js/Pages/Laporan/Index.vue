<script setup>
import { computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import Paginasi from '@/Components/UI/Paginasi.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import RentangTanggal from '@/Components/UI/RentangTanggal.vue'

/**
 * Laporan kehadiran per pegawai (FR-LAP-01 s.d. FR-LAP-03).
 */

const props = defineProps({
  baris: { type: Object, required: true },
  ringkasan: { type: Object, required: true },
  jumlah_event: { type: Number, required: true },
  unit_kerja: { type: Array, required: true },
  filter: { type: Object, required: true },
})

const filter = reactive({ ...props.filter })

const opsiUnit = computed(() => [
  { nilai: '', label: 'Semua unit dalam cakupan' },
  ...props.unit_kerja.map((u) => ({ nilai: u.id, label: u.nama, keterangan: u.kode })),
])

const kueri = computed(() =>
  Object.fromEntries(Object.entries(filter).filter(([, n]) => n !== '' && n !== null)),
)

function terapkan() {
  router.get('/admin/laporan', kueri.value, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

function unduh(format) {
  window.location.href =
    '/admin/laporan/ekspor?' + new URLSearchParams({ ...kueri.value, format }).toString()
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

const kartu = computed(() => [
  { label: 'Pegawai', nilai: props.ringkasan.pegawai, warna: 'text-utama', ikon: 'pegawai', latar: 'bg-info-lembut text-info-teks' },
  { label: 'Total Hadir', nilai: props.ringkasan.hadir, warna: 'text-berhasil-teks', ikon: 'cek', latar: 'bg-berhasil-lembut text-berhasil' },
  { label: 'Total Terlambat', nilai: props.ringkasan.terlambat, warna: 'text-peringatan-teks', ikon: 'jam', latar: 'bg-peringatan-lembut text-peringatan' },
  { label: 'Tanpa Keterangan', nilai: props.ringkasan.tanpa_keterangan, warna: 'text-sekunder', ikon: 'peringatan', latar: 'bg-permukaan-2 text-redup' },
])
</script>

<template>
  <AdminLayout
    judul="Laporan Kehadiran"
    deskripsi="Rekap per pegawai untuk rentang tanggal dan unit kerja yang dipilih."
  >
    <template #aksi>
      <div class="flex flex-wrap items-center gap-2 print:hidden">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95"
          @click="cetak"
        >
          <Ikon nama="cetak" ukuran="h-4 w-4" /> Cetak
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md border border-garis bg-permukaan px-3 py-2 text-sm font-medium text-utama transition hover:bg-permukaan-hover active:scale-95"
          @click="unduh('csv')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> CSV
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-md bg-aksen px-4 py-2 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat active:scale-95"
          @click="unduh('pdf')"
        >
          <Ikon nama="unduh" ukuran="h-4 w-4" /> Unduh PDF
        </button>
      </div>
    </template>

    <!-- FR-LAP-01 -->
    <div class="mb-5 rounded-lg border border-garis bg-permukaan p-4 bayang print:hidden">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
          <RentangTanggal
            label="Rentang Tanggal"
            v-model:dari="filter.dari"
            v-model:sampai="filter.sampai"
            @ubah="terapkan"
          />
        </div>
        <div>
          <label for="unit" class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
            Unit Kerja
          </label>
          <Pilihan
            id="unit"
            v-model="filter.unit_kerja_id"
            :opsi="opsiUnit"
            @update:model-value="terapkan"
          />
        </div>
        <div>
          <span class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
            Cari Pegawai
          </span>
          <KolomCari v-model="filter.cari" placeholder="Nama, NIP, atau unit…" @cari="terapkan" />
        </div>
      </div>
    </div>

    <!-- Kop laporan; ikut tercetak -->
    <div class="rounded-lg border border-garis bg-permukaan p-6 bayang print:border-0 print:p-0 print:shadow-none">
      <h2 class="font-display text-lg font-semibold text-utama">Rekap Kehadiran Pegawai</h2>
      <p class="mt-1 text-sm text-sekunder">
        {{ tanggalPanjang(filter.dari) }} — {{ tanggalPanjang(filter.sampai) }}
        · {{ jumlah_event }} event pada rentang ini
      </p>

      <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div
          v-for="item in kartu"
          :key="item.label"
          class="rounded-md border border-garis px-4 py-3"
        >
          <div class="flex items-start justify-between gap-2">
            <div>
              <dt class="text-xs uppercase tracking-wider text-redup">{{ item.label }}</dt>
              <dd class="mt-1 font-display text-2xl font-semibold tabular-nums" :class="item.warna">
                {{ item.nilai }}
              </dd>
            </div>
            <span class="rounded-md p-1.5 print:hidden" :class="item.latar">
              <Ikon :nama="item.ikon" ukuran="h-4 w-4" />
            </span>
          </div>
        </div>
      </dl>
    </div>

    <!-- FR-LAP-02 -->
    <div class="mt-6 overflow-hidden rounded-lg border border-garis bg-permukaan bayang print:border-0 print:shadow-none">
      <div class="tabel-gulir gulir-halus">
        <table class="min-w-full divide-y divide-garis text-sm">
          <thead class="border-b border-garis bg-permukaan-2 text-xs uppercase tracking-wider text-redup">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-medium">No</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">NIP</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
              <th scope="col" class="px-4 py-3 text-left font-medium">Unit Kerja</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Event</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Hadir</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Terlambat</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Tanpa Ket.</th>
              <th scope="col" class="px-4 py-3 text-right font-medium">Capaian</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-garis">
            <tr
              v-for="(isi, urutan) in baris.data"
              :key="isi.pegawai_id"
              class="transition-colors hover:bg-permukaan-hover"
            >
              <td class="px-4 py-2.5 font-display tabular-nums text-redup">
                {{ (baris.from ?? 1) + urutan }}
              </td>
              <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ isi.nip }}</td>
              <td class="whitespace-nowrap px-4 py-2.5 font-medium text-utama">{{ isi.nama }}</td>
              <td class="max-w-[14rem] truncate px-4 py-2.5 text-sekunder" :title="isi.unit_kerja">
                {{ isi.unit_kerja ?? '—' }}
              </td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums text-redup">
                {{ isi.event_berlaku }}
              </td>
              <td class="px-4 py-2.5 text-right font-display tabular-nums text-berhasil-teks">
                {{ isi.hadir }}
              </td>
              <td
                class="px-4 py-2.5 text-right font-display tabular-nums"
                :class="isi.terlambat > 0 ? 'text-peringatan-teks' : 'text-redup'"
              >
                {{ isi.terlambat }}
              </td>
              <td
                class="px-4 py-2.5 text-right font-display tabular-nums"
                :class="isi.tanpa_keterangan > 0 ? 'text-utama' : 'text-redup'"
              >
                {{ isi.tanpa_keterangan }}
              </td>
              <td class="px-4 py-2.5">
                <div class="flex items-center justify-end gap-2">
                  <div class="hidden h-1.5 w-16 overflow-hidden rounded-full bg-permukaan-2 sm:block print:hidden">
                    <div
                      class="h-full rounded-full"
                      :class="isi.hadir / Math.max(isi.event_berlaku, 1) >= 0.75 ? 'bg-berhasil' : 'bg-peringatan'"
                      :style="{ width: `${Math.min(100, (isi.hadir / Math.max(isi.event_berlaku, 1)) * 100)}%` }"
                    ></div>
                  </div>
                  <span class="font-display tabular-nums text-sekunder">{{ persenKehadiran(isi) }}</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <KeadaanKosong
        v-if="baris.data.length === 0"
        ikon="pegawai"
        judul="Tidak ada pegawai"
        keterangan="Tidak ada pegawai pada cakupan, rentang, dan pencarian ini."
      />

      <Paginasi :data="baris" />
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
