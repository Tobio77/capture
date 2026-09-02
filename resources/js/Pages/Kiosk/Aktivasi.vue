<script setup>
import { computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import Ikon from '@/Components/Ikon.vue'
import Pilihan from '@/Components/UI/Pilihan.vue'

const props = defineProps({
  // FR-SET-06: perangkat boleh masuk tanpa kode selagi mode ini menyala.
  mode_terbuka: { type: Boolean, default: false },
  unit_kerja: { type: Array, default: () => [] },
})

const page = usePage()
const flash = computed(() => page.props.flash)

const form = useForm({
  kode_aktivasi: '',
})

const formTerbuka = useForm({
  unit_kerja_id: props.unit_kerja[0]?.id ?? null,
})

const opsiUnit = computed(() =>
  props.unit_kerja.map((unit) => ({ nilai: unit.id, label: unit.nama, keterangan: unit.kode })),
)

const masukTanpaKode = () => formTerbuka.post('/kiosk/aktivasi/terbuka')

// Tampilkan sebagai XXXX-XXXX; server menormalkan lagi sebelum dicocokkan.
const rapikan = (event) => {
  const bersih = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8)
  form.kode_aktivasi = bersih.length > 4 ? `${bersih.slice(0, 4)}-${bersih.slice(4)}` : bersih
}

const kirim = () => form.post('/kiosk/aktivasi')
</script>

<template>
  <Head title="Aktivasi Perangkat" />

  <div class="flex min-h-screen items-center justify-center bg-navy-700 px-4 py-12">
    <div class="w-full max-w-lg">
      <div class="text-center">
        <h1 class="font-display text-3xl font-semibold text-white">Capture</h1>
        <p class="mt-1 text-sm text-navy-200">Perangkat Titik Absen</p>
      </div>

      <div class="mt-8 rounded-lg bg-permukaan p-8 shadow-lg">
        <h2 class="font-display text-lg font-semibold text-utama">Aktivasi Perangkat</h2>
        <p class="mt-1 text-sm text-redup">
          Masukkan kode aktivasi yang diberikan admin untuk titik absen ini.
          Perangkat cukup diaktifkan satu kali.
        </p>

        <div
          v-if="flash.gagal"
          class="mt-5 rounded-md border border-peringatan bg-peringatan-lembut px-4 py-3 text-sm text-peringatan-teks"
        >
          {{ flash.gagal }}
        </div>
        <div
          v-if="flash.sukses"
          class="mt-5 rounded-md border border-berhasil bg-berhasil-lembut px-4 py-3 text-sm text-berhasil-teks"
        >
          {{ flash.sukses }}
        </div>

        <form class="mt-6 space-y-5" @submit.prevent="kirim">
          <div>
            <label for="kode" class="block text-sm font-medium text-utama">Kode Aktivasi</label>
            <input
              id="kode"
              :value="form.kode_aktivasi"
              type="text"
              inputmode="latin"
              autocomplete="off"
              autofocus
              required
              placeholder="XXXX-XXXX"
              class="mt-1 block w-full rounded-md border border-garis px-4 py-3 text-center font-display text-2xl tracking-[0.3em] uppercase bayang focus:border-aksen focus:outline-none focus:ring-1 focus:ring-aksen"
              @input="rapikan"
            />
            <p v-if="form.errors.kode_aktivasi" class="mt-1.5 text-sm text-peringatan-teks">
              {{ form.errors.kode_aktivasi }}
            </p>
          </div>

          <button
            type="submit"
            :disabled="form.processing"
            class="w-full rounded-md bg-aksen px-4 py-3 text-sm font-semibold text-white bayang transition hover:bg-aksen-kuat focus:outline-none focus:ring-2 focus:ring-aksen focus:ring-offset-2 disabled:opacity-60"
          >
            {{ form.processing ? 'Memproses…' : 'Aktifkan Perangkat' }}
          </button>
        </form>

        <!--
          Mode Terbuka. Ditempatkan DI BAWAH kolom kode, bukan di atasnya:
          jalur yang benar tetap jalur berkode, dan yang ini adalah jalan
          keluar darurat — bukan pintu utama.
        -->
        <div v-if="mode_terbuka" class="mt-6 rounded-lg border border-peringatan bg-peringatan-lembut p-4">
          <p class="flex items-center gap-1.5 text-sm font-semibold text-peringatan-teks">
            <Ikon nama="peringatan" ukuran="h-4 w-4" /> Mode Terbuka sedang menyala
          </p>
          <p class="mt-1 text-xs text-peringatan-teks">
            Perangkat dapat masuk tanpa kode aktivasi. Pilih unit kerja tempat perangkat ini
            berada — seluruh absen yang dilayaninya akan tercatat pada unit tersebut.
          </p>

          <div class="mt-3">
            <Pilihan v-model="formTerbuka.unit_kerja_id" :opsi="opsiUnit" placeholder="Pilih unit kerja…" />
            <p v-if="formTerbuka.errors.unit_kerja_id" class="mt-1.5 text-xs text-peringatan-teks">
              {{ formTerbuka.errors.unit_kerja_id }}
            </p>
          </div>

          <button
            type="button"
            :disabled="formTerbuka.processing || !formTerbuka.unit_kerja_id"
            class="mt-3 w-full rounded-md border border-peringatan px-4 py-2.5 text-sm font-semibold text-peringatan-teks transition hover:bg-peringatan-lembut active:scale-[0.99] disabled:opacity-50"
            @click="masukTanpaKode"
          >
            {{ formTerbuka.processing ? 'Memproses…' : 'Masuk Tanpa Kode Aktivasi' }}
          </button>
        </div>

        <p class="mt-6 border-t border-garis pt-4 text-xs text-redup">
          Kode aktivasi diterbitkan admin melalui menu Perangkat Absen dan berlaku 24 jam.
          Alamat IP perangkat ini tercatat otomatis saat aktivasi.
        </p>
      </div>
    </div>
  </div>
</template>
