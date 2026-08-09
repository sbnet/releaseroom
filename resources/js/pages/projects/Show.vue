<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import RepositoryConnectionCard from '@/components/RepositoryConnectionCard.vue';
import { Button } from '@/components/ui/button';
import { edit, index } from '@/routes/projects';
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection | null;
    pending_count: number;
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

const createdAt = computed(() =>
    props.project.created_at === null
        ? null
        : new Date(props.project.created_at).toLocaleDateString(undefined, {
              year: 'numeric',
              month: 'long',
              day: 'numeric',
          }),
);
</script>

<template>
    <Head :title="props.project.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                :title="props.project.name"
                :description="props.project.description ?? undefined"
            />
            <Button variant="outline" as-child>
                <Link
                    :href="edit(props.project.id)"
                    data-test="edit-project-button"
                >
                    Edit
                </Link>
            </Button>
        </div>

        <dl
            class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-2 dark:border-sidebar-border"
        >
            <div class="space-y-1">
                <dt class="text-sm text-muted-foreground">Public slug</dt>
                <dd class="font-mono text-sm">/{{ props.project.slug }}</dd>
            </div>
            <div class="space-y-1" v-if="createdAt">
                <dt class="text-sm text-muted-foreground">Created</dt>
                <dd class="text-sm">{{ createdAt }}</dd>
            </div>
        </dl>

        <RepositoryConnectionCard
            :project="props.project"
            :connection="props.connection"
            :pending-count="props.pending_count"
        />

        <div
            class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
        >
            <p class="font-medium">Nothing to publish yet</p>
            <p class="max-w-md text-sm text-muted-foreground">
                Composing and publishing releases lands in the next slice. For
                now this project holds its identity, its public address and
                where it reads from.
            </p>
        </div>
    </div>
</template>
