<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DisconnectRepository from '@/components/DisconnectRepository.vue';
import Heading from '@/components/Heading.vue';
import RepositoryConnectionForm from '@/components/RepositoryConnectionForm.vue';
import { index } from '@/routes/projects';
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection | null;
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

            <RepositoryConnectionForm
                :project="props.project"
                :connection="props.connection"
            />
        </div>

        <DisconnectRepository
            v-if="props.connection"
            :project="props.project"
            :connection="props.connection"
        />
    </div>
</template>
