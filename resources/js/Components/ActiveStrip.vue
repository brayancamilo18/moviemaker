<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    stories: { type: Array, required: true },
    selectedId: { type: Number, default: null },
});

const failedCount = computed(
    () => props.stories.filter((story) => story.failed).length,
);

const summary = computed(
    () => props.stories.length + ' historias en proceso · ' + failedCount.value + ' fallidas',
);

function thumbStyle(tone) {
    const hue = Number(tone) || 0;

    return {
        width: '32px',
        height: '32px',
        flex: 'none',
        background:
            'linear-gradient(160deg,hsl(' +
            hue +
            ' 14% 11%),hsl(' +
            ((hue + 28) % 360) +
            ' 10% 6%))',
    };
}

function cardStyle(story) {
    const selected = story.id === props.selectedId;

    return {
        width: '240px',
        flex: 'none',
        position: 'relative',
        display: 'flex',
        alignItems: 'flex-start',
        gap: '10px',
        padding: '12px',
        textDecoration: 'none',
        color: 'inherit',
        border: selected
            ? '1px solid var(--color-amber)'
            : '1px solid var(--color-border-soft)',
        background: selected ? 'var(--color-surface-3)' : 'transparent',
    };
}
</script>

<template>
    <div style="margin-bottom:20px">
        <div style="font-size:11px;color:#605F5D;margin-bottom:10px">{{ summary }}</div>
        <div style="display:flex;gap:8px;overflow-x:auto">
            <Link
                v-for="story in stories"
                :key="story.id"
                :href="`/pipeline?story=${story.id}`"
                preserve-scroll
                :style="cardStyle(story)"
            >
                <span :style="thumbStyle(story.tone)"></span>
                <span
                    v-if="story.failed"
                    style="position:absolute;top:6px;right:6px;width:8px;height:8px;background:var(--color-bad)"
                ></span>
                <div style="min-width:0;flex:1">
                    <div
                        style="font-size:13px;font-weight:800;letter-spacing:-.01em;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"
                    >{{ story.title }}</div>
                    <div style="font-size:11px;margin-top:4px">
                        <span :style="{ color: story.status.color }">{{ story.status.label }}</span>
                        <span style="color:#605F5D"> · Paso {{ story.currentRow }} de 7</span>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
