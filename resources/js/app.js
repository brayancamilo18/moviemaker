import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import PanelLayout from './Layouts/PanelLayout.vue';

createInertiaApp({
    title: (title) => `${title} · horror-studio`,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];
        page.default.layout = page.default.layout || PanelLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});
