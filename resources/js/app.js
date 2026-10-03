import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import PusatNotifikasi from './Components/UI/PusatNotifikasi.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Capture';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        /*
         * Pusat notifikasi dipasang di samping App, bukan di dalam layout
         * mana pun: popup dan dialog konfirmasinya berlaku di panel admin,
         * layar masuk, halaman depan, dan layar perangkat sekaligus.
         */
        createApp({
            render: () => [
                h(App, props),
                h(PusatNotifikasi, { flashAwal: props.initialPage?.props?.flash ?? null }),
            ],
        })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#0D9488',
    },
});
