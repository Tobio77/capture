<script setup>
import { computed, ref } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import PanelEntry from '@/Components/Kiosk/PanelEntry.vue'
import PanelPresensi from '@/Components/Kiosk/PanelPresensi.vue'

/**
 * Layar Utama Kiosk (UIUX §4.2) — header status event dan dua panel yang
 * selalu terlihat sepanjang sesi.
 *
 * Tahap yang dikenali: menunggu_event, menunggu_tap, memindai, berhasil,
 * gagal. Verifikasi wajah (S15) dan penyimpanan absen (S16) yang kelak
 * menggerakkan tahap memindai/berhasil/gagal.
 */

const props = defineProps({
  event: { type: Object, default: null },
  metode: { type: Object, required: true },
  daftar_presensi: { type: Array, required: true },
})

const page = usePage()
const kiosk = computed(() => page.props.kiosk)

const jenis = ref('datang')
const hasil = ref(null)
const pesan = ref(null)
const panel = ref(null)

const entryDibuka = computed(() => props.event !== null)
const tahap = ref(entryDibuka.value ? 'menunggu_tap' : 'menunggu_event')

let jedaPulih = null

/**
 * Tap dari kolom ID. Untuk sementara hanya mencari identitas pegawai lewat
 * endpoint validasi NIP; penerimaan HID/RFID diperdalam pada S14, verifikasi
 * wajah pada S15, dan penyimpanan absennya pada S16.
 */
async function tangkapTap({ id_card }) {
  clearTimeout(jedaPulih)

  tahap.value = 'memindai'
  hasil.value = null
  pesan.value = null

  try {
    const jawaban = await fetch('/kiosk/tap/validasi-nip', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
      },
      body: JSON.stringify({ nip: id_card }),
    })

    const isi = await jawaban.json()

    if (!isi.success) {
      gagalkan(isi.message ?? 'Tap tidak dapat diproses.')

      return
    }

    hasil.value = {
      nip: isi.data.nip,
      nama: isi.data.nama,
      unit_kerja: isi.data.unit_kerja_nama,
      jam: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
    }

    tahap.value = 'berhasil'
    pulihkan()
  } catch {
    gagalkan('Perangkat tidak dapat menghubungi server.')
  }
}

function gagalkan(teks) {
  pesan.value = teks
  tahap.value = 'gagal'
  pulihkan()
}

/** Kembali menunggu tap berikutnya setelah hasil sempat terbaca. */
function pulihkan() {
  jedaPulih = setTimeout(() => {
    tahap.value = entryDibuka.value ? 'menunggu_tap' : 'menunggu_event'
    panel.value?.rebutFokus()
  }, 4000)
}

const lepas = () => {
  if (window.confirm('Lepaskan perangkat ini dari titik absen? Perangkat harus diaktifkan ulang dengan kode baru.')) {
    router.post('/kiosk/lepas')
  }
}
</script>

<template>
  <Head title="Layar Kiosk" />

  <div class="flex min-h-screen flex-col bg-slate-900 text-slate-100">
    <header class="border-b border-white/10 bg-navy-700">
      <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-4 px-6 py-4">
        <div class="flex items-center gap-5">
          <div>
            <p class="font-display text-lg font-semibold text-white">
              {{ event?.nama ?? 'Tidak ada event aktif' }}
            </p>
            <p class="text-sm text-navy-200">
              {{ kiosk.nama_titik }} · {{ kiosk.unit_kerja?.nama }}
            </p>
          </div>

          <span
            class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
            :class="entryDibuka ? 'bg-emerald-600/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400'"
          >
            <span class="h-2 w-2 rounded-full" :class="entryDibuka ? 'bg-emerald-500' : 'bg-slate-500'"></span>
            {{ entryDibuka ? 'Entry Dibuka' : 'Entry Ditutup' }}
          </span>
        </div>

        <div class="flex items-center gap-4">
          <p v-if="event" class="text-right text-xs text-navy-200">
            Mulai {{ event.jam_mulai }} · toleransi {{ event.toleransi_menit }} menit
          </p>
          <button
            type="button"
            class="rounded-md border border-white/20 px-3 py-1.5 text-xs font-medium text-navy-100 transition hover:bg-white/10 hover:text-white"
            @click="lepas"
          >
            Lepas Perangkat
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto grid w-full max-w-[1600px] flex-1 gap-5 px-6 py-6 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
      <PanelEntry
        ref="panel"
        v-model:jenis="jenis"
        :tahap="tahap"
        :pesan="pesan"
        :hasil="hasil"
        :metode="metode"
        :aktif="entryDibuka"
        @tap="tangkapTap"
      />

      <PanelPresensi :daftar="daftar_presensi" :event="event" />
    </main>
  </div>
</template>
