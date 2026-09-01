<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'

/**
 * Pendaftaran kartu RFID pegawai (FR-TAP-03).
 *
 * Admin menempelkan kartu pada reader USB; reader mengetikkan UID-nya ke
 * kolom di bawah persis seperti keyboard. Kolom sengaja dibiarkan dapat
 * diketik manual juga, untuk kartu yang UID-nya sudah diketahui.
 */

const props = defineProps({
  pegawai: { type: Object, default: null },
})

const emit = defineEmits(['tutup'])

const kolom = ref(null)
const terbuka = computed(() => props.pegawai !== null)
const sudahPunya = computed(() => Boolean(props.pegawai?.uid_kartu))

const form = useForm({ uid_kartu: '' })

watch(terbuka, async (nilai) => {
  if (!nilai) return

  form.reset()
  form.clearErrors()

  await nextTick()
  kolom.value?.focus()
})

function tutup() {
  form.reset()
  emit('tutup')
}

function simpan() {
  if (form.uid_kartu.trim() === '') return

  form.post(`/admin/pegawai/${props.pegawai.id}/kartu`, {
    preserveScroll: true,
    onSuccess: () => tutup(),
  })
}

function cabut() {
  if (!window.confirm(`Cabut kartu RFID ${props.pegawai.nama}? Pegawai tetap dapat absen manual.`)) {
    return
  }

  form.delete(`/admin/pegawai/${props.pegawai.id}/kartu`, {
    preserveScroll: true,
    onSuccess: () => tutup(),
  })
}
</script>

<template>
  <Modal
    :terbuka="terbuka"
    :judul="sudahPunya ? 'Ganti Kartu RFID' : 'Daftarkan Kartu RFID'"
    @tutup="tutup"
  >
    <div v-if="pegawai" class="space-y-4">
      <div class="rounded-lg bg-slate-50 px-4 py-3">
        <p class="font-medium text-navy-700">{{ pegawai.nama }}</p>
        <p class="font-display text-xs tabular-nums text-slate-500">{{ pegawai.nip }}</p>
      </div>

      <div v-if="sudahPunya" class="rounded-md border border-slate-200 px-4 py-3">
        <p class="text-xs uppercase tracking-wider text-slate-500">Kartu terdaftar saat ini</p>
        <p class="mt-1 font-display text-sm tabular-nums text-navy-700">{{ pegawai.uid_kartu }}</p>
      </div>

      <div>
        <label for="uid-kartu" class="block text-sm font-medium text-slate-700">
          {{ sudahPunya ? 'UID kartu pengganti' : 'UID kartu' }}
        </label>
        <input
          id="uid-kartu"
          ref="kolom"
          v-model="form.uid_kartu"
          type="text"
          autocomplete="off"
          placeholder="Tempelkan kartu pada reader…"
          class="mt-2 block w-full rounded-md border-slate-300 font-display tabular-nums shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm"
          @keyup.enter="simpan"
        />
        <p class="mt-1.5 text-xs text-slate-500">
          Reader 13,56 MHz akan mengetikkan UID kartu sendiri ke kolom ini. Huruf besar/kecil dan
          tanda pemisah diabaikan.
        </p>
        <p v-if="form.errors.uid_kartu" class="mt-1.5 text-xs text-amber-700">{{ form.errors.uid_kartu }}</p>
      </div>
    </div>

    <template #aksi>
      <button
        v-if="sudahPunya"
        type="button"
        class="mr-auto rounded-lg px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50 disabled:opacity-50"
        :disabled="form.processing"
        @click="cabut"
      >
        Cabut kartu
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
        :disabled="form.processing || form.uid_kartu.trim() === ''"
        @click="simpan"
      >
        {{ form.processing ? 'Menyimpan…' : 'Simpan Kartu' }}
      </button>
    </template>
  </Modal>
</template>
