import { vi } from 'vitest'

/**
 * Persiapan lingkungan uji sisi klien.
 *
 * Yang dipalsukan di sini hanya DUA hal: pustaka pihak ketiga yang berat, dan
 * kemampuan peramban yang memang tidak dimiliki jsdom. Kode aplikasi sendiri
 * tidak pernah dipalsukan — kalau ia dipalsukan, uji asapnya berhenti menguji
 * apa pun.
 */

/*
 * face-api berukuran ~6,8 MB dan memuat bobot model dari jaringan. Layar tap
 * memanggilnya lewat `import()` dinamis saat verifikasi wajah menyala; yang
 * ingin diuji adalah jalur kode kita menuju panggilan itu, bukan pustakanya.
 */
vi.mock('@vladmandic/face-api', () => ({
  nets: {
    ssdMobilenetv1: { loadFromUri: vi.fn().mockResolvedValue(undefined) },
    faceLandmark68Net: { loadFromUri: vi.fn().mockResolvedValue(undefined) },
    faceRecognitionNet: { loadFromUri: vi.fn().mockResolvedValue(undefined) },
    tinyFaceDetector: { loadFromUri: vi.fn().mockResolvedValue(undefined) },
  },
  detectSingleFace: vi.fn(),
  detectAllFaces: vi.fn(),
  SsdMobilenetv1Options: class {},
  TinyFaceDetectorOptions: class {},
}))

/*
 * jsdom tidak punya kamera. Tanpa stub ini layar tap selalu jatuh ke keadaan
 * "kamera tidak dapat diakses" — keadaan yang sah, tetapi bukan yang biasa
 * dilihat orang, sehingga jalur normalnya tidak pernah tergambar.
 */
Object.defineProperty(navigator, 'mediaDevices', {
  configurable: true,
  value: {
    getUserMedia: vi.fn().mockResolvedValue({
      getTracks: () => [{ stop: vi.fn() }],
    }),
  },
})

// Elemen <video> jsdom tidak dapat memutar apa pun.
window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined)
window.HTMLMediaElement.prototype.pause = vi.fn()

/* Dipakai penyaklar tema dan pemeriksaan prefers-reduced-motion. */
window.matchMedia =
  window.matchMedia ||
  ((kueri) => ({
    matches: false,
    media: kueri,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))

/*
 * Penarikan berkala Daftar e-Presensi. Jawaban kosong sudah cukup: yang diuji
 * adalah perenderan, bukan penyegarannya.
 */
global.fetch = vi.fn().mockResolvedValue({
  ok: true,
  json: async () => ({ daftar: [], rekap: [], baris: [], ringkasan: {}, event: null }),
})

/*
 * Inertia dipalsukan agar prop bersama dapat disetel per uji. Lihat
 * catatan pada inertia-palsu.js: prop bersama yang realistis itulah inti uji
 * asap ini, karena di situlah cacat "props.kiosk vs page.props.kiosk" hidup.
 */
vi.mock('@inertiajs/vue3', async () => {
  const { buatInertiaPalsu } = await import('./inertia-palsu.js')

  return buatInertiaPalsu()
})
