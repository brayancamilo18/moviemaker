<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const pendingReviewCount = computed(() => Number(page.props.pendingReviewCount ?? 0));
const monthlySpend = computed(() => String(page.props.monthlySpend ?? '0,00 €'));
const monthlySpendTitle = computed(() => String(page.props.monthlySpendTitle ?? ''));

const links = [
    { n: '1', label: 'Cola de trabajo', href: '/queue', badge: true },
    { n: '2', label: 'Nueva historia', href: '/stories/create' },
    { n: '3', label: 'Progreso', href: '/pipeline' },
    { n: '4', label: 'Revisión', href: '/review' },
    { n: '5', label: 'Hoja de contactos', href: '/sheet' },
    { n: '6', label: 'Miniatura', href: '/thumbnail' },
    { n: '7', label: 'Paquete', href: '/package' },
];

const isActive = (href) => {
    const path = page.url.split('?')[0];

    if (href === '/review') {
        return path === '/review' || /\/stories\/.+\/review$/.test(path);
    }

    return path === href;
};
</script>

<template>
    <div class="flex h-full overflow-hidden bg-bg">
        <aside class="flex h-full w-[228px] shrink-0 flex-col border-r border-border-soft bg-surface">
            <div class="border-b border-border-soft px-4 py-[18px]">
                <div class="text-[14px] font-extrabold tracking-[-0.01em] text-text">horror-studio</div>
                <div class="mt-[3px] text-[10.5px] tracking-[0.09em] text-text-dim uppercase">Producción interna</div>
            </div>

            <nav class="flex flex-col gap-px px-2 py-2.5">
                <Link
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    class="flex items-center gap-[9px] border-l-2 px-2.5 py-2 text-[12.5px]"
                    :class="
                        isActive(link.href)
                            ? 'border-amber bg-surface-3 font-extrabold text-text'
                            : 'border-transparent font-normal text-[#9C9A97]'
                    "
                >
                    <span class="w-4 shrink-0 text-[10.5px] text-text-dim">{{ link.n }}</span>
                    <span class="flex-1">{{ link.label }}</span>
                    <span
                        v-if="link.badge && pendingReviewCount > 0"
                        class="bg-amber px-1.5 py-px text-[10px] font-extrabold text-[#151006]"
                    >
                        {{ pendingReviewCount }}
                    </span>
                </Link>
            </nav>

            <div class="mt-auto flex justify-between border-t border-border-soft px-4 py-3.5 text-[11px] text-text-muted">
                <span>Gasto del mes</span>
                <span class="font-extrabold text-amber" :title="monthlySpendTitle || undefined">{{ monthlySpend }}</span>
            </div>
        </aside>

        <main class="min-w-0 flex-1 overflow-y-auto">
            <slot />
        </main>
    </div>
</template>
