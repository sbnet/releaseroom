<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DisconnectRepository from '@/components/DisconnectRepository.vue';
import Heading from '@/components/Heading.vue';
import RepositoryConnectionForm from '@/components/RepositoryConnectionForm.vue';
import RepositoryWebhookCard from '@/components/RepositoryWebhookCard.vue';
import { index } from '@/routes/projects';
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection | null;
    /** Ours, not GitHub's — see the controller for why this one is readable. */
    webhook_secret: string | null;
    candidate_count: number;
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
    <Head :title="`Repository — ${props.project.name}`" />

    <div class="flex max-w-xl flex-col gap-10 p-4">
        <div class="flex flex-col gap-6">
            <Heading
                title="GitHub repository"
                :description="
                    props.connection === null
                        ? 'Connect the repository this project reads its merged pull requests from.'
                        : 'Change the repository, or replace the token that reads it.'
                "
            />

            <p
                v-if="props.connection && props.candidate_count > 0"
                class="rounded-lg border border-sidebar-border/70 bg-muted/40 p-3 text-sm text-muted-foreground dark:border-sidebar-border"
                data-test="repointing-locked"
            >
                This project holds {{ props.candidate_count }} pull
                {{ props.candidate_count === 1 ? 'request' : 'requests' }} from
                {{ props.connection.full_name }}. You can replace the token, but
                pointing the project at a different repository means
                disconnecting first.
            </p>

            <RepositoryConnectionForm
                :project="props.project"
                :connection="props.connection"
            />
        </div>

        <RepositoryWebhookCard
            v-if="props.connection && props.webhook_secret"
            :project="props.project"
            :connection="props.connection"
            :secret="props.webhook_secret"
        />

        <DisconnectRepository
            v-if="props.connection"
            :project="props.project"
            :connection="props.connection"
        />
    </div>
</template>
