<script setup>
import { onMounted, onUnmounted, watch } from 'vue'

const props = defineProps({
  terbuka: { type: Boolean, default: false },
  judul: { type: String, required: true },
})

const emit = defineEmits(['tutup'])

const tanganiEscape = (event) => {
  if (event.key === 'Escape' && props.terbuka) emit('tutup')
}

watch(
  () => props.terbuka,
  (terbuka) => {
    document.body.style.overflow = terbuka ? 'hidden' : ''
  },
)

onMounted(() => document.addEventListener('keydown', tanganiEscape))
onUnmounted(() => {
  document.removeEventListener('keydown', tanganiEscape)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="terbuka" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="fixed inset-0 bg-navy-900/60" @click="emit('tutup')"></div>

        <div class="relative flex min-h-full items-center justify-center p-4">
          <div
            class="w-full max-w-lg rounded-lg bg-white shadow-xl"
            role="dialog"
            aria-modal="true"
            :aria-label="judul"
          >
            <div class="flex items-start justify-between border-b border-slate-200 px-6 py-4">
              <h2 class="font-display text-base font-semibold text-navy-700">{{ judul }}</h2>
              <button
                type="button"
                class="-mr-1 rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                aria-label="Tutup"
                @click="emit('tutup')"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <div class="px-6 py-5">
              <slot />
            </div>

            <div v-if="$slots.aksi" class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4">
              <slot name="aksi" />
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
