<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import {
    DownloadCloud,
    ExternalLink,
    GitBranch,
    Lock,
    RefreshCw,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import RepositoryConnectionController from '@/actions/App/Http/Controllers/RepositoryConnectionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { toRelativeTime } from '@/lib/relativeTime';
import { index as candidates } from '@/routes/projects/candidates';
import { edit } from '@/routes/projects/repository';
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection | null;
    pendingCount: number;
}>();

const lastChecked = computed(() =>
    props.connection === null
        ? null
        : toRelativeTime(props.connection.last_checked_at),
);

const lastSynced = computed(() =>
    props.connection?.last_synced_at
        ? toRelativeTime(props.connection.last_synced_at)
        : null,
);

const isWebhookActive = computed(
    () => props.connection?.webhook_status === 'active',
);

/**
 * When GitHub last reached us. Shown next to "Active" because "the hook
 * exists" and "the hook is still delivering" are different claims, and only
 * the second one is worth anything — a hook deleted on GitHub goes quiet
 * without telling us, and this timestamp is the symptom.
 */
const lastDelivery = computed(() =>
    props.connection?.webhook_last_delivery_at
        ? toRelativeTime(props.connection.webhook_last_delivery_at)
        : null,
);

/* One interpolation rather than a nested template, so the spacing around the
 * separator does not depend on how the compiler condenses whitespace. */
const liveDeliveryLabel = computed(() =>
    lastDelivery.value === null
        ? 'Active'
        : `Active · last ${lastDelivery.value}`,
);

const pendingLabel = computed(() => {
    if (props.pendingCount === 0) {
        return 'No pull requests pending';
    }

    return props.pendingCount === 1
        ? '1 pull request pending curation'
        : `${props.pendingCount} pull requests pending curation`;
});
</script>

<template>
    <section
        class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="space-y-1">
                <h2 class="font-medium">Repository</h2>
                <p class="text-sm text-muted-foreground">
                    Where this project reads its merged pull requests from.
                </p>
            </div>

            <Button
                v-if="props.connection === null"
                as-child
                data-test="connect-repository-button"
            >
                <Link :href="edit(props.project.id)">Connect a repository</Link>
            </Button>
        </div>

        <p
            v-if="props.connection === null"
            class="text-sm text-muted-foreground"
        >
            No repository connected yet.
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <a
                    :href="props.connection.url"
                    target="_blank"
                    rel="noreferrer noopener"
                    class="inline-flex items-center gap-1.5 font-mono text-sm hover:underline"
                    data-test="repository-full-name"
                >
                    {{ props.connection.full_name }}
                    <ExternalLink class="size-3.5 text-muted-foreground" />
                </a>

                <Badge variant="secondary">
                    <Lock v-if="props.connection.is_private" />
                    {{ props.connection.is_private ? 'Private' : 'Public' }}
                </Badge>

                <Badge
                    :variant="
                        props.connection.status === 'connected'
                            ? 'outline'
                            : 'destructive'
                    "
                    data-test="repository-status"
                >
                    {{
                        props.connection.status === 'connected'
                            ? 'Connected'
                            : 'Connection failed'
                    }}
                </Badge>
            </div>

            <p
                v-if="props.connection.error_message"
                class="text-sm text-destructive"
                data-test="repository-error"
            >
                {{ props.connection.error_message }}
            </p>

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div class="space-y-1">
                    <dt class="text-muted-foreground">Default branch</dt>
                    <dd class="inline-flex items-center gap-1.5 font-mono">
                        <GitBranch class="size-3.5 text-muted-foreground" />
                        {{ props.connection.default_branch }}
                    </dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-muted-foreground">Last checked</dt>
                    <!-- Relative time: rendered twice, a beat apart. -->
                    <dd data-allow-mismatch="text">{{ lastChecked }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-muted-foreground">Live delivery</dt>
                    <dd
                        class="inline-flex items-center gap-1.5"
                        data-test="webhook-state"
                    >
                        <Zap
                            class="size-3.5"
                            :class="
                                isWebhookActive
                                    ? 'text-muted-foreground'
                                    : 'text-amber-500'
                            "
                        />
                        <span v-if="isWebhookActive" data-allow-mismatch="text">
                            {{ liveDeliveryLabel }}
                        </span>
                        <Link
                            v-else
                            :href="edit(props.project.id)"
                            class="hover:underline"
                        >
                            Not set up
                        </Link>
                    </dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-muted-foreground">Last synced</dt>
                    <dd data-test="last-synced" data-allow-mismatch="text">
                        {{ lastSynced ?? 'Never' }}
                    </dd>
                </div>
            </dl>

            <p class="text-sm">
                <Link
                    :href="candidates(props.project.id)"
                    class="hover:underline"
                    data-test="pending-count"
                >
                    <span class="font-medium">{{ pendingLabel }}</span>
                </Link>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <Form
                    v-bind="
                        RepositoryConnectionController.check.form(
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
                        data-test="check-repository-button"
                    >
                        <RefreshCw :class="processing ? 'animate-spin' : ''" />
                        Test connection
                    </Button>
                </Form>

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
                        variant="outline"
                        :disabled="processing"
                        data-test="sync-repository-button"
                    >
                        <DownloadCloud />
                        Sync now
                    </Button>
                </Form>

                <Button variant="ghost" as-child>
                    <Link
                        :href="edit(props.project.id)"
                        data-test="manage-repository-button"
                    >
                        Manage
                    </Link>
                </Button>
            </div>
        </template>
    </section>
</template>
