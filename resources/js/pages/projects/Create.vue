<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import { index, create } from '@/routes/projects';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Projects',
                href: index(),
            },
            {
                title: 'New project',
                href: create(),
            },
        ],
    },
});
</script>

<template>
    <Head title="New project" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="New project"
            description="Name it, claim its public address, and describe what it ships."
        />

        <div class="max-w-xl">
            <ProjectForm />
        </div>
    </div>
</template>
