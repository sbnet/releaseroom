<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ExternalLink, RotateCcw, X } from '@lucide/vue';
import { computed } from 'vue';
import PullRequestCandidateController from '@/actions/App/Http/Controllers/PullRequestCandidateController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { toRelativeTime } from '@/lib/relativeTime';
import type { Project, PullRequestCandidate } from '@/types';

const props = defineProps<{
    project: Project;
    candidate: PullRequestCandidate;
}>();

/*
 * Rendered on the server and again on hydration, a beat later, so the two can
 * legitimately disagree by a minute. The markup marks that mismatch as
 * expected rather than letting Vue report it as a hydration failure.
 */
const merged = computed(() => toRelativeTime(props.candidate.merged_at));

const isPending = computed(() => props.candidate.state === 'pending');

/**
 * Dismissing and restoring are the same write with opposite intent, so the
 * row swaps one action for the other rather than showing both.
 */
const action = computed(() =>
    isPending.value
        ? PullRequestCandidateController.dismiss.form([
              props.project.id,
              props.candidate.id,
          ])
        : PullRequestCandidateController.restore.form([
              props.project.id,
              props.candidate.id,
          ]),
);
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:flex-row sm:items-start sm:justify-between dark:border-sidebar-border"
        data-test="candidate-row"
    >
        <div class="min-w-0 space-y-2">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="font-mono text-xs text-muted-foreground">
                    #{{ props.candidate.number }}
                </span>
                <a
                    :href="props.candidate.html_url"
                    target="_blank"
                    rel="noreferrer noopener"
                    class="inline-flex items-center gap-1.5 font-medium hover:underline"
                    data-test="candidate-title"
                >
                    {{ props.candidate.title }}
                    <ExternalLink
                        class="size-3.5 shrink-0 text-muted-foreground"
                    />
                </a>
            </div>

            <div
                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
            >
                <span
                    v-if="props.candidate.author_login"
                    class="inline-flex items-center gap-1.5"
                >
                    <img
                        v-if="props.candidate.author_avatar_url"
                        :src="props.candidate.author_avatar_url"
                        alt=""
                        class="size-4 rounded-full"
                    />
                    {{ props.candidate.author_login }}
                </span>
                <!-- Server and client compute this moments apart; see below. -->
                <span data-allow-mismatch="text">merged {{ merged }}</span>
            </div>

            <div
                v-if="props.candidate.labels.length > 0"
                class="flex flex-wrap gap-1.5"
            >
                <Badge
                    v-for="label in props.candidate.labels"
                    :key="label"
                    variant="secondary"
                >
                    {{ label }}
                </Badge>
            </div>
        </div>

        <Form
            v-bind="action"
            :options="{ preserveScroll: true }"
            v-slot="{ processing }"
            class="shrink-0"
        >
            <Button
                type="submit"
                variant="ghost"
                size="sm"
                :disabled="processing"
                :data-test="
                    isPending ? 'dismiss-candidate' : 'restore-candidate'
                "
            >
                <X v-if="isPending" />
                <RotateCcw v-else />
                {{ isPending ? 'Dismiss' : 'Restore' }}
            </Button>
        </Form>
    </li>
</template>
