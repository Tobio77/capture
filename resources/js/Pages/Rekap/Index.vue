<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

/**
 * Rekap Absen per event (FR-REK-01 s.d. FR-REK-03).
 *
 * Tabel menyegarkan dirinya selama event masih dibuka; begitu ditutup,
 * penyegaran berhenti sendiri karena angkanya tidak akan bergerak lagi.
 */

const props = defineProps({
  daftar_event: { type: Array, required: true },
  event: { type: Object, default: null },
  rekap: { type: Array, required: true },
  ringkasan: { type: Object, required: true },
})

const page = usePage()
const pengguna = computed(() => page.props.auth.pengguna)

const JEDA_SEGAR_MS = 15000

const barisRekap = ref(props.rekap)
const angka = ref(props.ringkasan)
const statusEvent = ref(props.event?.status ?? null)
const eventTerpilih = ref(props.event?.id ?? '')

let jedaSegar = null

const masihDibuka = computed(() => statusEvent.value === 'aktif')

watch(
  () => props.rekap,
  (nilai) => {
    barisRekap.value = nilai
    angka.value = props.ringkasan
    statusEvent.value = props.event?.status ?? null
    eventTerpilih.value = props.event?.id ?? ''
  },
)

onMounted(() => {
  jedaSegar = setInterval(segarkan, JEDA_SEGAR_MS)
})

onBeforeUnmount(() => clearInterval(jedaSegar))

function pilihEvent() {
  router.get('/admin/kelola-absen/rekap', { event_absen_id: eventTerpilih.value }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  })
}

async function segarkan() {
  // Event yang sudah ditutup tidak akan bertambah barisnya.
  if (!props.event || !masihDibuka.value) return

  try {
    const jawaban = await fetch(`/admin/kelola-absen/rekap/${props.event.id}/data`, {
      headers: { Accept: 'application/json' },
    })

    if (!jawaban.ok) return

    const isi = await jawaban.json()

    barisRekap.value = isi.rekap
    angka.value = isi.ringkasan
    statusEvent.value = isi.status
  } catch {
    // Gangguan sesaat; percobaan berikutnya menyusul sendiri.
  }
}

function cetak() {
  window.print()
}

function tanggalPanjang(iso) {
  if (!iso) return '—'

  return new Date(`${iso}T00:00:00`).toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}
</script>

<template>
  <AdminLayout
    judul="Rekap Absen"
    deskripsi="Daftar e-presensi per event. Tabel diperbarui sendiri selama event masih dibuka."
  >
    <!-- Pemilih event dan aksi cetak -->
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4 print:hidden">
      <div class="min-w-72">
        <label for="event" class="block text-sm font-medium text-slate-700">Event</label>
        <select
          id="event"
          v-model="eventTerpilih"
          class="mt-1.5 block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          @change="pilihEvent"
        >
          <option value="" disabled>Pilih event…</option>
          <option v-for="item in daftar_event" :key="item.id" :value="item.id">
            {{ item.nama }} — {{ item.tanggal }} ({{ item.status_label }})
          </option>
        </select>
      </div>

      <button
        v-if="event"
        type="button"
        class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
        @click="cetak"
      >
        Cetak Rekap
      </button>
    </div>

    <div v-if="!event" class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-sm text-slate-500">
      Belum ada event yang dapat direkap.
    </div>

    <template v-else>
      <!-- Kepala rekap; ikut tercetak sebagai kop lembar -->
      <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm print:border-0 print:shadow-none">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="font-display text-lg font-semibold text-navy-700">{{ event.nama }}</h2>
            <p class="mt-1 text-sm text-slate-600">
              {{ tanggalPanjang(event.tanggal) }} · mulai {{ event.jam_mulai }} ·
              toleransi {{ event.toleransi_menit }} menit
            </p>
            <p class="mt-0.5 text-xs text-slate-500">
              Cakupan tampilan: {{ pengguna.lintas_unit ? 'seluruh unit kerja' : pengguna.unit_kerja?.nama }}
            </p>
          </div>

          <span
            class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium print:hidden"
            :class="masihDibuka ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
          >
            <span class="h-1.5 w-1.5 rounded-full" :class="masihDibuka ? 'animate-pulse bg-emerald-600' : 'bg-slate-400'"></span>
            {{ masihDibuka ? 'Entry dibuka — diperbarui otomatis' : 'Entry ditutup' }}
          </span>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div v-for="item in [
            { label: 'Hadir', nilai: angka.hadir, warna: 'text-navy-700' },
            { label: 'Tepat Waktu', nilai: angka.tepat, warna: 'text-emerald-700' },
            { label: 'Terlambat', nilai: angka.terlambat, warna: 'text-amber-700' },
            { label: 'Sudah Pulang', nilai: angka.sudah_pulang, warna: 'text-slate-600' },
          ]" :key="item.label" class="rounded-md border border-slate-200 px-4 py-3">
            <dt class="text-xs uppercase tracking-wider text-slate-500">{{ item.label }}</dt>
            <dd class="mt-1 font-display text-2xl font-semibold tabular-nums" :class="item.warna">
              {{ item.nilai }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- FR-REK-01 -->
      <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm print:border-0 print:shadow-none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
              <tr>
                <th scope="col" class="px-4 py-3 text-left font-medium">No</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">NIP</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Unit Kerja</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Jam Masuk</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Jam Pulang</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Metode</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
                <th scope="col" class="px-4 py-3 text-left font-medium print:hidden">Foto</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(baris, urutan) in barisRekap" :key="baris.pegawai_id">
                <td class="px-4 py-2.5 font-display tabular-nums text-slate-500">{{ urutan + 1 }}</td>
                <td class="px-4 py-2.5 font-display tabular-nums text-slate-600">{{ baris.nip }}</td>
                <td class="px-4 py-2.5 font-medium text-navy-700">{{ baris.nama }}</td>
                <td class="px-4 py-2.5 text-slate-600">{{ baris.unit_kerja ?? '—' }}</td>
                <td class="px-4 py-2.5 font-display tabular-nums text-slate-700">{{ baris.jam_masuk ?? '—' }}</td>
                <td class="px-4 py-2.5 font-display tabular-nums text-slate-700">{{ baris.jam_pulang ?? '—' }}</td>
                <td class="px-4 py-2.5 text-slate-600">{{ baris.metode }}</td>
                <td class="px-4 py-2.5">
                  <span
                    v-if="baris.status_label"
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="baris.status_ketepatan === 'terlambat'
                      ? 'bg-amber-50 text-amber-700'
                      : 'bg-emerald-50 text-emerald-700'"
                  >
                    {{ baris.status_label }}
                  </span>
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
                <td class="px-4 py-2.5 print:hidden">
                  <img
                    v-if="baris.foto_url"
                    :src="baris.foto_url"
                    :alt="`Foto absen ${baris.nama}`"
                    class="h-9 w-9 rounded object-cover"
                  />
                  <span v-else class="text-xs text-slate-400">—</span>
                </td>
              </tr>

              <tr v-if="barisRekap.length === 0">
                <td colspan="9" class="px-6 py-14 text-center text-sm text-slate-500">
                  Belum ada kehadiran tercatat pada event ini.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </AdminLayout>
</template>

<style>
/*
 * FR-REK-03. Sidebar, tombol, dan kolom foto disembunyikan saat mencetak
 * supaya lembar rekap berisi tabelnya saja; foto tidak ikut karena rekap
 * cetak dipakai sebagai lampiran administratif, bukan bukti visual.
 */
@media print {
  @page {
    margin: 14mm;
  }

  body {
    background: #fff;
  }
}
</style>
