<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Ikon from '@/Components/Ikon.vue'
import KolomCari from '@/Components/UI/KolomCari.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'
import Lencana from '@/Components/UI/Lencana.vue'
import KeadaanKosong from '@/Components/UI/KeadaanKosong.vue'

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
const opsiEvent = computed(() =>
  props.daftar_event.map((item) => ({
    nilai: item.id,
    label: item.nama,
    keterangan: `${item.tanggal} · ${item.status_label}`,
  })),
)

const eventTerpilih = ref(props.event?.id ?? '')
const cari = ref('')

let jedaSegar = null

const masihDibuka = computed(() => statusEvent.value === 'aktif')

/* Pencarian dilakukan di sisi klien: satu event paling banyak berisi ratusan
   baris yang sudah ada di layar, sehingga menyaringnya tidak perlu perjalanan
   ke server dan tetap terasa seketika. */
const barisTampil = computed(() => {
  const kunci = cari.value.trim().toLowerCase()

  if (kunci === '') return barisRekap.value

  return barisRekap.value.filter(
    (b) =>
      b.nama.toLowerCase().includes(kunci) ||
      b.nip.includes(kunci) ||
      (b.unit_kerja ?? '').toLowerCase().includes(kunci),
  )
})

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
  router.get(
    '/admin/kelola-absen/rekap',
    { event_absen_id: eventTerpilih.value },
    { preserveState: true, preserveScroll: true, replace: true },
  )
}

async function segarkan() {
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

function unduh(format) {
  if (!props.event) return

  window.location.href = `/admin/kelola-absen/rekap/${props.event.id}/ekspor?format=${format}`
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

const kartu = computed(() => [
  { label: 'Hadir', nilai: angka.value.hadir, warna: 'text-utama', ikon: 'pegawai', latar: 'bg-info-lembut text-info-teks' },
  { label: 'Tepat Waktu', nilai: angka.value.tepat, warna: 'text-berhasil-teks', ikon: 'cek', latar: 'bg-berhasil-lembut text-berhasil' },
  { label: 'Terlambat', nilai: angka.value.terlambat, warna: 'text-peringatan-teks', ikon: 'jam', latar: 'bg-peringatan-lembut text-peringatan' },
  { label: 'Sudah Pulang', nilai: angka.value.sudah_pulang, warna: 'text-sekunder', ikon: 'keluar', latar: 'bg-permukaan-2 text-redup' },
])
</script>

<template>
  <AdminLayout
    judul="Rekap Absen"
    deskripsi="Daftar e-presensi per event. Tabel diperbarui sendiri selama event masih dibuka."
  >
    <template v-if="event" #aksi>
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

    <!-- Pemilih event dan pencarian -->
    <div class="mb-5 grid gap-3 sm:grid-cols-2 print:hidden">
      <div>
        <label for="event" class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
          Event
        </label>
        <Pilihan
          id="event"
          v-model="eventTerpilih"
          :opsi="opsiEvent"
          placeholder="Pilih event…"
          @update:model-value="pilihEvent"
        />
      </div>

      <div v-if="event">
        <span class="mb-1.5 block text-xs font-medium uppercase tracking-wider text-redup">
          Cari Peserta
        </span>
        <KolomCari v-model="cari" placeholder="Nama, NIP, atau unit kerja…" :jeda="0" />
      </div>
    </div>

    <KeadaanKosong
      v-if="!event"
      ikon="absen"
      judul="Belum ada event yang dapat direkap"
      keterangan="Buat event pada menu Daftar Event untuk mulai mencatat kehadiran."
    />

    <template v-else>
      <div class="rounded-lg border border-garis bg-permukaan p-6 bayang print:border-0 print:p-0 print:shadow-none">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 class="font-display text-lg font-semibold text-utama">{{ event.nama }}</h2>
            <p class="mt-1 text-sm text-sekunder">
              {{ tanggalPanjang(event.tanggal) }} · mulai {{ event.jam_mulai }} ·
              toleransi {{ event.toleransi_menit }} menit
            </p>
            <p class="mt-0.5 text-xs text-redup">
              Cakupan tampilan:
              {{ pengguna.lintas_unit ? 'seluruh unit kerja' : pengguna.unit_kerja?.nama }}
            </p>
          </div>

          <Lencana :warna="masihDibuka ? 'emerald' : 'slate'" :denyut="masihDibuka" class="print:hidden">
            {{ masihDibuka ? 'Entry dibuka — diperbarui otomatis' : 'Entry ditutup' }}
          </Lencana>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div v-for="item in kartu" :key="item.label" class="rounded-md border border-garis px-4 py-3">
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

      <!-- FR-REK-01 -->
      <div class="mt-6 overflow-hidden rounded-lg border border-garis bg-permukaan bayang print:border-0 print:shadow-none">
        <div class="tabel-gulir gulir-halus">
          <table class="min-w-full divide-y divide-garis text-sm">
            <thead class="border-b border-garis bg-permukaan-2 text-xs uppercase tracking-wider text-redup">
              <tr>
                <th scope="col" class="px-4 py-3 text-left font-medium">No</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">NIP</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Nama</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Unit Kerja</th>
                <th scope="col" class="whitespace-nowrap px-4 py-3 text-left font-medium">Jam Masuk</th>
                <th scope="col" class="whitespace-nowrap px-4 py-3 text-left font-medium">Jam Pulang</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Metode</th>
                <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
                <th scope="col" class="px-4 py-3 text-left font-medium print:hidden">Foto</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-garis">
              <tr
                v-for="(baris, urutan) in barisTampil"
                :key="baris.pegawai_id"
                class="transition-colors hover:bg-permukaan-hover"
              >
                <td class="px-4 py-2.5 font-display tabular-nums text-redup">{{ urutan + 1 }}</td>
                <td class="px-4 py-2.5 font-display tabular-nums text-sekunder">{{ baris.nip }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-utama">
                  {{ baris.nama }}
                </td>
                <td
                  class="max-w-[14rem] truncate px-4 py-2.5 text-sekunder"
                  :title="baris.unit_kerja"
                >
                  {{ baris.unit_kerja ?? '—' }}
                </td>
                <td class="px-4 py-2.5 font-display tabular-nums text-utama">
                  {{ baris.jam_masuk ?? '—' }}
                </td>
                <td class="px-4 py-2.5 font-display tabular-nums text-utama">
                  {{ baris.jam_pulang ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-sekunder">{{ baris.metode }}</td>
                <td class="px-4 py-2.5">
                  <Lencana
                    v-if="baris.status_label"
                    :warna="baris.status_ketepatan === 'terlambat' ? 'amber' : 'emerald'"
                  >
                    {{ baris.status_label }}
                  </Lencana>
                  <span v-else class="text-xs text-redup">—</span>
                </td>
                <td class="px-4 py-2.5 print:hidden">
                  <img
                    v-if="baris.foto_url"
                    :src="baris.foto_url"
                    :alt="`Foto absen ${baris.nama}`"
                    class="h-9 w-9 rounded object-cover ring-1 ring-[var(--tema-garis)]"
                  />
                  <span v-else class="text-xs text-redup">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <KeadaanKosong
          v-if="barisTampil.length === 0"
          ikon="pegawai"
          :judul="cari ? 'Tidak ada peserta yang cocok' : 'Belum ada kehadiran'"
          :keterangan="
            cari
              ? 'Coba kata kunci lain, atau bersihkan pencarian.'
              : 'Baris bertambah otomatis setiap ada tap berhasil pada perangkat absen.'
          "
        />

        <p
          v-else-if="cari"
          class="border-t border-garis px-4 py-3 text-xs text-redup print:hidden"
        >
          Menampilkan
          <span class="font-display tabular-nums text-utama">{{ barisTampil.length }}</span>
          dari
          <span class="font-display tabular-nums text-utama">{{ barisRekap.length }}</span>
          peserta
        </p>
      </div>
    </template>
  </AdminLayout>
</template>

<style>
/*
 * FR-REK-03. Sidebar, pemilih event, tombol, dan kolom foto disembunyikan saat
 * mencetak supaya lembar rekap berisi tabelnya saja; foto tidak ikut karena
 * rekap cetak dipakai sebagai lampiran administratif, bukan bukti visual.
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
