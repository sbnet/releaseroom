<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DeleteProject from '@/components/DeleteProject.vue';
import Heading from '@/components/Heading.vue';
import ProjectForm from '@/components/ProjectForm.vue';
import { index } from '@/routes/projects';
import type { Project } from '@/types';

const props = defineProps<{
    project: Project;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Projects',
                href: index(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${props.project.name}`" />

    <div class="flex max-w-xl flex-col gap-10 p-4">
        <div class="flex flex-col gap-6">
            <Heading
                title="Project settings"
                description="Rename the project, change its public address or its description."
            />

            <ProjectForm :project="props.project" />
        </div>

        <DeleteProject :project="props.project" />
    </div>
</template>
