<script setup>
import { onMounted, onUnmounted, watch } from 'vue'
import Ikon from '@/Components/Ikon.vue'

const props = defineProps({
  terbuka: { type: Boolean, default: false },
  judul: { type: String, required: true },
  lebar: { type: String, default: 'max-w-lg' },
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
    <!-- Tirai dan panel dianimasikan terpisah: tirai memudar, panel naik
         sedikit sambil membesar, sehingga asal-usulnya terbaca. -->
    <Transition
      enter-active-class="transition-opacity duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150 ease-in"
      leave-to-class="opacity-0"
    >
      <div v-if="terbuka" class="fixed inset-0 z-50 overflow-y-auto">
        <div
          class="fixed inset-0 bg-navy-900/60 backdrop-blur-[2px]"
          @click="emit('tutup')"
        ></div>

        <div class="relative flex min-h-full items-center justify-center p-4">
          <Transition
            appear
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-3 scale-95 opacity-0"
            enter-to-class="translate-y-0 scale-100 opacity-100"
          >
            <div
              class="w-full rounded-xl border border-garis bg-permukaan shadow-2xl"
              :class="lebar"
              role="dialog"
              aria-modal="true"
              :aria-label="judul"
            >
              <div class="flex items-start justify-between border-b border-garis px-6 py-4">
                <h2 class="font-display text-base font-semibold text-utama">{{ judul }}</h2>
                <button
                  type="button"
                  class="-mr-1 rounded-md p-1.5 text-redup transition-colors duration-150 hover:bg-permukaan-hover hover:text-utama"
                  aria-label="Tutup"
                  @click="emit('tutup')"
                >
                  <Ikon nama="tutup" ukuran="h-5 w-5" />
                </button>
              </div>

              <div class="px-6 py-5">
                <slot />
              </div>

              <div
                v-if="$slots.aksi"
                class="flex justify-end gap-3 border-t border-garis px-6 py-4"
              >
                <slot name="aksi" />
              </div>
            </div>
          </Transition>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
