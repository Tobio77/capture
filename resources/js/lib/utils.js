import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

/**
 * Penggabung kelas Tailwind milik shadcn-vue.
 *
 * `clsx` merangkai kelas bersyarat, `twMerge` membuang yang bertabrakan —
 * `px-2 px-4` menjadi `px-4`, bukan keduanya. Komponen shadcn-vue menuntut
 * fungsi ini ada pada alias `@/lib/utils`; jangan diubah namanya.
 */
export function cn(...masukan) {
    return twMerge(clsx(masukan))
}
