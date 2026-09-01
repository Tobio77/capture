import { ref } from 'vue'

/**
 * Pembungkus face-api untuk menghitung embedding wajah di sisi klien.
 *
 * Memakai @vladmandic/face-api — fork face-api.js yang masih dirawat dan
 * berjalan di atas TensorFlow.js 4.x. API-nya sama dengan paket asli, dan
 * bobot pengenalan wajahnya berkas yang sama (hanya digabung dari dua shard
 * menjadi satu .bin), sehingga deskriptor tetap 128 dimensi.
 *
 * Verifikasi maupun pendaftaran wajah berjalan di browser, bukan di server
 * (lihat SDD §3). Modul beserta bobotnya berat (~6,8 MB), jadi keduanya dimuat
 * malas — hanya ketika admin benar-benar membuka pendaftaran wajah, bukan pada
 * setiap kunjungan halaman Kelola Pegawai.
 */

const JALUR_MODEL = '/models'

/** Panjang deskriptor yang divalidasi server (FotoReferensiWajahService). */
const DIMENSI_EMBEDDING = 128

let faceapi = null
let pemuatan = null

async function muatSekali() {
    if (faceapi) {
        return faceapi
    }

    if (! pemuatan) {
        pemuatan = (async () => {
            const modul = await import('@vladmandic/face-api')

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

        const embedding = Array.from(wajah[0].descriptor)

        // Penjaga terhadap pergantian model: server menolak apa pun yang
        // bukan 128 dimensi, jadi gagalkan lebih awal dengan pesan yang jelas
        // daripada menabrak validasi setelah foto terkirim.
        if (embedding.length !== DIMENSI_EMBEDDING) {
            return { galat: `Model menghasilkan ${embedding.length} dimensi, seharusnya ${DIMENSI_EMBEDDING}. Hubungi pengelola sistem.` }
        }

        return { embedding }
    }

    return { siap, memuat, siapkan, hitungEmbedding }
}
