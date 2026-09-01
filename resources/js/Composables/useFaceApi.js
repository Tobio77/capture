import { ref } from 'vue'

/**
 * Pembungkus face-api.js untuk menghitung embedding wajah di sisi klien.
 *
 * Verifikasi maupun pendaftaran wajah berjalan di browser, bukan di server
 * (lihat SDD §3). Modul face-api.js beserta bobotnya berat (~6,7 MB), jadi
 * keduanya dimuat malas — hanya ketika admin benar-benar membuka pendaftaran
 * wajah, bukan pada setiap kunjungan halaman Kelola Pegawai.
 */

const JALUR_MODEL = '/models'

let faceapi = null
let pemuatan = null

async function muatSekali() {
    if (faceapi) {
        return faceapi
    }

    if (! pemuatan) {
        pemuatan = (async () => {
            const modul = await import('face-api.js')

            await Promise.all([
                modul.nets.tinyFaceDetector.loadFromUri(JALUR_MODEL),
                modul.nets.faceLandmark68Net.loadFromUri(JALUR_MODEL),
                modul.nets.faceRecognitionNet.loadFromUri(JALUR_MODEL),
            ])

            faceapi = modul

            return modul
        })()
    }

    return pemuatan
}

export function useFaceApi() {
    const siap = ref(faceapi !== null)
    const memuat = ref(false)

    async function siapkan() {
        if (siap.value) {
            return
        }

        memuat.value = true

        try {
            await muatSekali()
            siap.value = true
        } finally {
            memuat.value = false
        }
    }

    /**
     * Hitung deskriptor 128 dimensi dari sebuah elemen gambar.
     *
     * Mengembalikan { embedding } bila tepat satu wajah terdeteksi, atau
     * { galat } berisi pesan Bahasa Indonesia yang siap ditampilkan.
     */
    async function hitungEmbedding(gambar) {
        await siapkan()

        const opsi = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.4 })

        const wajah = await faceapi
            .detectAllFaces(gambar, opsi)
            .withFaceLandmarks()
            .withFaceDescriptors()

        if (wajah.length === 0) {
            return { galat: 'Wajah tidak terdeteksi pada foto. Gunakan foto tampak depan dengan pencahayaan cukup.' }
        }

        if (wajah.length > 1) {
            return { galat: `Terdeteksi ${wajah.length} wajah pada foto. Gunakan foto berisi satu orang saja.` }
        }

        return { embedding: Array.from(wajah[0].descriptor) }
    }

    return { siap, memuat, siapkan, hitungEmbedding }
}
