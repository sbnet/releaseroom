<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FolderPlus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index, create, show } from '@/routes/projects';
import type { Project } from '@/types';

defineProps<{
    projects: Project[];
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
    <Head title="Projects" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Projects"
                description="Each project owns its own changelog and public release page."
            />
            <Button as-child v-if="projects.length > 0">
                <Link :href="create()" data-test="new-project-button">
                    New project
                </Link>
            </Button>
        </div>

        <div
            v-if="projects.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
        >
            <FolderPlus class="size-8 text-muted-foreground" />
            <div class="space-y-1">
                <p class="font-medium">No projects yet</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    A project is the unit that owns a changelog. Create one to
                    claim its public address.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()" data-test="new-project-button">
                    New project
                </Link>
            </Button>
        </div>

        <ul v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <li v-for="project in projects" :key="project.id">
                <Link
                    :href="show(project.id)"
                    class="flex h-full flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-muted/50 dark:border-sidebar-border"
                >
                    <span class="font-medium">{{ project.name }}</span>
                    <span class="font-mono text-xs text-muted-foreground">
                        /{{ project.slug }}
                    </span>
                    <span
                        v-if="project.description"
                        class="line-clamp-2 text-sm text-muted-foreground"
                    >
                        {{ project.description }}
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
