import { h, reactive } from 'vue'
import { vi } from 'vitest'

/**
 * Pengganti `@inertiajs/vue3` untuk uji perenderan.
 *
 * Di luar `createInertiaApp`, `usePage()` yang asli tidak punya halaman untuk
 * dibaca, sehingga setiap komponen yang menyentuh `page.props` akan gagal
 * karena lingkungan ujinya — bukan karena kodenya. Yang dibutuhkan uji asap
 * justru sebaliknya: prop bersama yang REALISTIS, karena di situlah cacat
 * yang hendak dijaring pernah bersembunyi.
 */

/** Prop bersama yang dibagikan HandleInertiaRequests; diisi tiap uji. */
export const propsBersama = reactive({
  auth: { pengguna: null },
  kiosk: null,
  menu: [],
  mode_terbuka: false,
  rute_saat_ini: null,
  flash: { sukses: null, gagal: null },
})

export function setelPropsBersama(isi = {}) {
  Object.assign(propsBersama, {
    auth: { pengguna: null },
    kiosk: null,
    menu: [],
    mode_terbuka: false,
    rute_saat_ini: null,
    flash: { sukses: null, gagal: null },
    ...isi,
  })
}

/**
 * Formulir Inertia secukupnya: cukup untuk dirender dan disentuh v-model,
 * tanpa benar-benar mengirim apa pun.
 */
function useForm(awal = {}) {
  const form = reactive({
    ...awal,
    errors: {},
    processing: false,
    post: vi.fn(),
    get: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
    transform: vi.fn(() => form),
  })

  return form
}

export function buatInertiaPalsu() {
  return {
    usePage: () => ({ props: propsBersama, url: '/', component: 'Uji' }),
    useForm,
    router: {
      get: vi.fn(),
      post: vi.fn(),
      put: vi.fn(),
      delete: vi.fn(),
      visit: vi.fn(),
      reload: vi.fn(),
      on: vi.fn(() => vi.fn()),
    },
    Link: {
      name: 'Link',
      props: { href: { type: String, default: '#' } },
      setup: (props, { slots }) => () => h('a', { href: props.href }, slots.default?.()),
    },
    Head: {
      name: 'Head',
      props: { title: { type: String, default: '' } },
      setup: () => () => null,
    },
    Deferred: {
      name: 'Deferred',
      setup: (props, { slots }) => () => slots.default?.(),
    },
  }
}
