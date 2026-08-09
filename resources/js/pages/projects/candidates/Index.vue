<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { GitPullRequest, RefreshCw } from '@lucide/vue';
import { computed } from 'vue';
import RepositoryConnectionController from '@/actions/App/Http/Controllers/RepositoryConnectionController';
import Heading from '@/components/Heading.vue';
import PullRequestCandidateRow from '@/components/PullRequestCandidateRow.vue';
import { Button } from '@/components/ui/button';
import { toRelativeTime } from '@/lib/relativeTime';
import { index as projects, show } from '@/routes/projects';
import { index as candidateList } from '@/routes/projects/candidates';
import { edit as repository } from '@/routes/projects/repository';
import type {
    CandidateCounts,
    CandidateState,
    Paginated,
    Project,
    PullRequestCandidate,
    RepositoryConnection,
} from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection | null;
    candidates: Paginated<PullRequestCandidate>;
    state: CandidateState;
    counts: CandidateCounts;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Projects',
                href: projects(),
            },
        ],
    },
});

const lastSynced = computed(() =>
    props.connection?.last_synced_at
        ? toRelativeTime(props.connection.last_synced_at)
        : null,
);

const total = computed(() => props.counts.pending + props.counts.dismissed);

/**
 * Five different situations produce an empty list, and they need five
 * different sentences: there is nothing to read from, reading it failed,
 * there is something to read from but it has not been read yet, it has been
 * read and found nothing, or everything has already been triaged.
 *
 * The failed case has to come first. `last_synced_at` is only ever written on
 * a successful import, so a backfill that died — a rate limit right after
 * connecting, say — leaves it null forever, and "still importing" would be a
 * promise this page could never keep.
 */
const emptyState = computed(() => {
    if (props.connection === null) {
        return 'disconnected';
    }

    if (total.value > 0) {
        return 'triaged';
    }

    if (props.connection.status === 'failed') {
        return 'failed';
    }

    return props.connection.last_synced_at === null
        ? 'importing'
        : 'nothing-ingested';
});

const tabs = computed(() => [
    {
        state: 'pending' as CandidateState,
        label: 'Pending',
        count: props.counts.pending,
    },
    {
        state: 'dismissed' as CandidateState,
        label: 'Dismissed',
        count: props.counts.dismissed,
    },
]);
</script>

<template>
    <Head :title="`Pull requests — ${props.project.name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Merged pull requests"
                description="Everything merged into the default branch, waiting to become a changelog."
            />

            <div class="flex items-center gap-2">
                <Form
                    v-if="props.connection"
                    v-bind="
                        RepositoryConnectionController.sync.form(
                            props.project.id,
                        )
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        data-test="sync-button"
                    >
                        <RefreshCw :class="processing ? 'animate-spin' : ''" />
                        Sync now
                    </Button>
                </Form>

                <Button variant="ghost" as-child>
                    <Link :href="show(props.project.id)">Back to project</Link>
                </Button>
            </div>
        </div>

        <!-- Relative time: server and client disagree by design, see the row. -->
        <p
            v-if="lastSynced"
            class="-mt-2 text-sm text-muted-foreground"
            data-allow-mismatch="text"
        >
            Last synced {{ lastSynced }}.
        </p>

        <nav class="flex gap-1 border-b border-sidebar-border/70">
            <Link
                v-for="tab in tabs"
                :key="tab.state"
                :href="
                    candidateList(props.project.id, {
                        query: { state: tab.state },
                    })
                "
                class="-mb-px border-b-2 px-3 py-2 text-sm transition-colors"
                :class="
                    props.state === tab.state
                        ? 'border-foreground font-medium'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :data-test="`tab-${tab.state}`"
            >
                {{ tab.label }}
                <span class="ml-1 text-xs text-muted-foreground">
                    {{ tab.count }}
                </span>
            </Link>
        </nav>

        <ul v-if="props.candidates.data.length > 0" class="flex flex-col gap-3">
            <PullRequestCandidateRow
                v-for="candidate in props.candidates.data"
                :key="candidate.id"
                :project="props.project"
                :candidate="candidate"
            />
        </ul>

        <div
            v-else
            class="flex flex-1 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
            data-test="candidates-empty"
        >
            <GitPullRequest class="size-8 text-muted-foreground" />

            <div class="space-y-1" v-if="emptyState === 'disconnected'">
                <p class="font-medium">No repository connected</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Connect a GitHub repository to start collecting merged pull
                    requests.
                </p>
            </div>

            <div class="space-y-1" v-else-if="emptyState === 'failed'">
                <p class="font-medium">Could not import pull requests</p>
                <p
                    class="max-w-md text-sm text-destructive"
                    data-test="import-failed-reason"
                >
                    {{
                        props.connection?.error_message ??
                        'GitHub refused the last import.'
                    }}
                </p>
            </div>

            <div class="space-y-1" v-else-if="emptyState === 'importing'">
                <p class="font-medium">Importing pull requests</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Reading recent history from
                    {{ props.connection?.full_name }}. Reload this page in a
                    moment.
                </p>
            </div>

            <div
                class="space-y-1"
                v-else-if="emptyState === 'nothing-ingested'"
            >
                <p class="font-medium">Nothing merged yet</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Pull requests appear here as they are merged into
                    <span class="font-mono">{{
                        props.connection?.default_branch
                    }}</span
                    >.
                    <template
                        v-if="props.connection?.webhook_status !== 'active'"
                    >
                        <Link
                            :href="repository(props.project.id)"
                            class="underline underline-offset-4"
                            data-test="webhook-setup-link"
                        >
                            Live delivery is not set up yet</Link
                        >, so nothing will arrive on its own.
                    </template>
                </p>
            </div>

            <div class="space-y-1" v-else-if="props.state === 'pending'">
                <p class="font-medium">Nothing pending</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    {{ props.counts.dismissed }} dismissed.
                </p>
            </div>

            <div class="space-y-1" v-else>
                <p class="font-medium">Nothing dismissed</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Pull requests you rule out appear here.
                </p>
            </div>

            <Button
                v-if="emptyState === 'disconnected'"
                as-child
                data-test="connect-repository-button"
            >
                <Link :href="repository(props.project.id)">
                    Connect a repository
                </Link>
            </Button>

            <!-- The reason is on screen; so is the way to act on it. -->
            <div
                v-else-if="emptyState === 'failed'"
                class="flex flex-wrap items-center justify-center gap-2"
            >
                <Form
                    v-bind="
                        RepositoryConnectionController.sync.form(
                            props.project.id,
                        )
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-test="retry-import-button"
                    >
                        <RefreshCw :class="processing ? 'animate-spin' : ''" />
                        Try again
                    </Button>
                </Form>

                <Button variant="outline" as-child>
                    <Link :href="repository(props.project.id)">
                        Repository settings
                    </Link>
                </Button>
            </div>
        </div>

        <nav
            v-if="props.candidates.last_page > 1"
            class="flex items-center justify-between gap-4 text-sm"
        >
            <Button
                variant="outline"
                size="sm"
                as-child
                :disabled="props.candidates.prev_page_url === null"
            >
                <Link
                    v-if="props.candidates.prev_page_url"
                    :href="props.candidates.prev_page_url"
                >
                    Previous
                </Link>
                <span v-else>Previous</span>
            </Button>

            <span class="text-muted-foreground">
                Page {{ props.candidates.current_page }} of
                {{ props.candidates.last_page }}
            </span>

            <Button
                variant="outline"
                size="sm"
                as-child
                :disabled="props.candidates.next_page_url === null"
            >
                <Link
                    v-if="props.candidates.next_page_url"
                    :href="props.candidates.next_page_url"
                >
                    Next
                </Link>
                <span v-else>Next</span>
            </Button>
        </nav>
    </div>
</template>
