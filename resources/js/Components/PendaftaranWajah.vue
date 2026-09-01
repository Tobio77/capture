<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import { useFaceApi } from '@/Composables/useFaceApi'

/**
 * Pendaftaran/pembaruan foto referensi wajah pegawai (FR-PEG-05).
 *
 * Embedding dihitung di browser ini juga: server tidak pernah memproses wajah,
 * dan yang kelak dikirim ke kiosk hanyalah embedding, bukan fotonya.
 */

const props = defineProps({
  pegawai: { type: Object, default: null },
})

const emit = defineEmits(['tutup'])

const { memuat: memuatModel, hitungEmbedding } = useFaceApi()

const berkas = ref(null)
const pratinjau = ref(null)
const embedding = ref(null)
const galat = ref(null)
const memeriksa = ref(false)
const mengirim = ref(false)

const terbuka = computed(() => props.pegawai !== null)
const sudahTerdaftar = computed(() => props.pegawai?.wajah_terdaftar === true)

const statusPeriksa = computed(() => {
  if (memuatModel.value) return 'Memuat model pengenalan wajah…'
  if (memeriksa.value) return 'Memeriksa wajah pada foto…'
  return null
})

watch(terbuka, (nilai) => {
  if (!nilai) return
  bersihkan()
})

function bersihkan() {
  if (pratinjau.value) URL.revokeObjectURL(pratinjau.value)
  berkas.value = null
  pratinjau.value = null
  embedding.value = null
  galat.value = null
}

function tutup() {
  bersihkan()
  emit('tutup')
}

async function pilihBerkas(event) {
  const dipilih = event.target.files?.[0] ?? null

  if (pratinjau.value) URL.revokeObjectURL(pratinjau.value)

  berkas.value = dipilih
  embedding.value = null
  galat.value = null
  pratinjau.value = dipilih ? URL.createObjectURL(dipilih) : null

  if (!dipilih) return

  memeriksa.value = true

  try {
    const gambar = await muatGambar(pratinjau.value)
    const hasil = await hitungEmbedding(gambar)

    if (hasil.galat) {
      galat.value = hasil.galat
      return
    }

    embedding.value = hasil.embedding
  } catch (e) {
    galat.value = 'Gagal memuat model pengenalan wajah. Periksa koneksi lalu muat ulang halaman.'
  } finally {
    memeriksa.value = false
  }
}

function muatGambar(url) {
  return new Promise((selesai, gagal) => {
    const gambar = new Image()
    gambar.onload = () => selesai(gambar)
    gambar.onerror = () => gagal(new Error('Gambar tidak dapat dibaca.'))
    gambar.src = url
  })
}

function simpan() {
  if (!berkas.value || !embedding.value) return

  mengirim.value = true

  router.post(
    `/admin/pegawai/${props.pegawai.id}/wajah`,
    { foto: berkas.value, embedding: embedding.value },
    {
      preserveScroll: true,
      onSuccess: () => tutup(),
      onError: (kesalahan) => {
        galat.value = kesalahan.foto ?? kesalahan.embedding ?? 'Penyimpanan gagal.'
      },
      onFinish: () => {
        mengirim.value = false
      },
    },
  )
}

function cabut() {
  if (!window.confirm(`Cabut pendaftaran wajah ${props.pegawai.nama}? Foto referensi akan dihapus.`)) {
    return
  }

  mengirim.value = true

  router.delete(`/admin/pegawai/${props.pegawai.id}/wajah`, {
    preserveScroll: true,
    onSuccess: () => tutup(),
    onFinish: () => {
      mengirim.value = false
    },
  })
}
</script>

<template>
  <Modal :terbuka="terbuka" :judul="sudahTerdaftar ? 'Perbarui Foto Referensi Wajah' : 'Daftarkan Foto Referensi Wajah'" @tutup="tutup">
    <div v-if="pegawai" class="space-y-4">
      <div class="rounded-lg bg-slate-50 px-4 py-3">
        <p class="font-medium text-navy-700">{{ pegawai.nama }}</p>
        <p class="font-display text-xs tabular-nums text-slate-500">{{ pegawai.nip }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div v-if="sudahTerdaftar">
          <p class="mb-1.5 text-xs font-medium uppercase tracking-wider text-slate-500">Terdaftar saat ini</p>
          <img
            :src="`/admin/pegawai/${pegawai.id}/wajah`"
            :alt="`Foto referensi ${pegawai.nama}`"
            class="aspect-square w-full rounded-lg border border-slate-200 object-cover"
          />
        </div>

        <div :class="sudahTerdaftar ? '' : 'sm:col-span-2'">
          <p class="mb-1.5 text-xs font-medium uppercase tracking-wider text-slate-500">
            {{ sudahTerdaftar ? 'Foto pengganti' : 'Foto referensi' }}
          </p>
          <img
            v-if="pratinjau"
            :src="pratinjau"
            alt="Pratinjau foto referensi"
            class="aspect-square w-full rounded-lg border border-slate-200 object-cover"
          />
          <div
            v-else
            class="flex aspect-square w-full items-center justify-center rounded-lg border border-dashed border-slate-300 text-center text-xs text-slate-400"
          >
            Belum ada foto dipilih
          </div>
        </div>
      </div>

      <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-700" for="berkas-wajah">Pilih foto</label>
        <input
          id="berkas-wajah"
          type="file"
          accept="image/jpeg,image/png"
          class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-navy-700 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-navy-800"
          @change="pilihBerkas"
        />
        <p class="mt-1.5 text-xs text-slate-500">
          Foto tampak depan, satu orang, pencahayaan cukup. JPG atau PNG, maksimal 5 MB.
        </p>
      </div>

      <p v-if="statusPeriksa" class="flex items-center gap-2 text-sm text-slate-600">
        <span class="h-3 w-3 animate-spin rounded-full border-2 border-teal-600 border-t-transparent"></span>
        {{ statusPeriksa }}
      </p>

      <p v-if="galat" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ galat }}</p>

      <p v-if="embedding" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
        Wajah terdeteksi. Foto siap disimpan.
      </p>
    </div>

    <template #aksi>
      <button
        v-if="sudahTerdaftar"
        type="button"
        class="mr-auto rounded-lg px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50"
        :disabled="mengirim"
        @click="cabut"
      >
        Cabut pendaftaran
      </button>
      <button
        type="button"
        class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
        @click="tutup"
      >
        Batal
      </button>
      <button
        type="button"
        class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
        :disabled="!embedding || mengirim"
        @click="simpan"
      >
        {{ mengirim ? 'Menyimpan…' : 'Simpan' }}
      </button>
    </template>
  </Modal>
</template>
