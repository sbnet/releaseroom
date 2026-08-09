<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, Copy, Eye, EyeOff, RefreshCw, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import RepositoryWebhookController from '@/actions/App/Http/Controllers/RepositoryWebhookController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { toRelativeTime } from '@/lib/relativeTime';
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection;
    secret: string;
}>();

const revealed = ref(false);
const copied = ref<string | null>(null);
const copyFailed = ref(false);

const isActive = computed(() => props.connection.webhook_status === 'active');

const lastDelivery = computed(() =>
    props.connection.webhook_last_delivery_at
        ? toRelativeTime(props.connection.webhook_last_delivery_at)
        : null,
);

const hookSettingsUrl = computed(
    () => `${props.connection.url}/settings/hooks`,
);

/**
 * Copy, and cope with not being allowed to.
 *
 * `navigator.clipboard` is unavailable in an insecure context and the browser
 * may refuse permission outright. Left unhandled the rejection surfaces as a
 * Vue error with nothing on screen to explain it — on the one screen whose
 * whole purpose is getting two values out of the app and into GitHub. Falling
 * back to revealing the secret lets the owner select it by hand.
 */
async function copy(value: string, field: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        copyFailed.value = true;
        revealed.value = true;

        return;
    }

    copyFailed.value = false;
    copied.value = field;
    setTimeout(() => (copied.value = null), 1500);
}
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Live delivery"
            description="How merged pull requests reach ReleaseRoom as they happen"
        />

        <div
            class="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="isActive ? 'outline' : 'secondary'"
                    data-test="webhook-status"
                >
                    <Zap v-if="isActive" />
                    {{ isActive ? 'Active' : 'Manual setup required' }}
                </Badge>
                <!--
                    A relative time is computed on the server and again in the
                    browser, moments apart, so "11 minutes ago" legitimately
                    becomes "12 minutes ago" between the two. The mismatch is
                    expected here rather than a symptom of anything.
                -->
                <span
                    v-if="lastDelivery"
                    class="text-sm text-muted-foreground"
                    data-allow-mismatch="text"
                >
                    Last delivery {{ lastDelivery }}
                </span>
            </div>

            <p v-if="isActive" class="text-sm text-muted-foreground">
                GitHub is delivering merged pull requests to this project.
                Backfill and
                <span class="font-medium">Sync now</span> stay available for
                anything a delivery misses.
            </p>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    ReleaseRoom could not create the webhook itself — usually
                    because the stored token does not grant
                    <span class="font-medium">Webhooks: Read and write</span>.
                    Replace the token above and retry, or create the hook by
                    hand with the settings below. Until then, pull requests only
                    arrive when you sync.
                </p>

                <dl class="space-y-3 text-sm">
                    <div class="space-y-1">
                        <Label>Payload URL</Label>
                        <div class="flex items-center gap-2">
                            <code
                                class="min-w-0 flex-1 truncate rounded-md bg-muted px-2 py-1.5 font-mono text-xs"
                                data-test="webhook-url"
                            >
                                {{ props.connection.webhook_url }}
                            </code>
                            <Button
                                variant="ghost"
                                size="sm"
                                type="button"
                                @click="
                                    copy(props.connection.webhook_url, 'url')
                                "
                            >
                                <Check v-if="copied === 'url'" />
                                <Copy v-else />
                            </Button>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label>Secret</Label>
                        <div class="flex items-center gap-2">
                            <code
                                class="min-w-0 flex-1 truncate rounded-md bg-muted px-2 py-1.5 font-mono text-xs"
                                data-test="webhook-secret"
                            >
                                {{ revealed ? props.secret : '•'.repeat(32) }}
                            </code>
                            <Button
                                variant="ghost"
                                size="sm"
                                type="button"
                                :aria-label="
                                    revealed ? 'Hide secret' : 'Show secret'
                                "
                                @click="revealed = !revealed"
                                data-test="reveal-secret"
                            >
                                <EyeOff v-if="revealed" />
                                <Eye v-else />
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                type="button"
                                @click="copy(props.secret, 'secret')"
                            >
                                <Check v-if="copied === 'secret'" />
                                <Copy v-else />
                            </Button>
                        </div>
                    </div>

                    <p
                        v-if="copyFailed"
                        class="text-xs text-muted-foreground"
                        data-test="copy-unavailable"
                    >
                        Your browser would not let the page use the clipboard.
                        The values are shown above — select and copy them by
                        hand.
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1">
                            <dt class="text-muted-foreground">Content type</dt>
                            <dd class="font-mono text-xs">application/json</dd>
                        </div>
                        <div class="space-y-1">
                            <dt class="text-muted-foreground">Events</dt>
                            <dd class="text-xs">Pull requests, only</dd>
                        </div>
                    </div>
                </dl>

                <div class="flex flex-wrap items-center gap-2">
                    <Form
                        v-bind="
                            RepositoryWebhookController.store.form(
                                props.project.id,
                            )
                        "
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            :disabled="processing"
                            data-test="retry-webhook-button"
                        >
                            <RefreshCw
                                :class="processing ? 'animate-spin' : ''"
                            />
                            Retry automatic setup
                        </Button>
                    </Form>

                    <Button variant="outline" as-child>
                        <a
                            :href="hookSettingsUrl"
                            target="_blank"
                            rel="noreferrer noopener"
                        >
                            Open webhook settings on GitHub
                        </a>
                    </Button>
                </div>
            </template>

            <div class="border-t border-sidebar-border/70 pt-4">
                <Form
                    v-bind="
                        RepositoryWebhookController.update.form(
                            props.project.id,
                        )
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    class="flex flex-wrap items-center gap-3"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="processing"
                        data-test="rotate-secret-button"
                    >
                        Regenerate secret
                    </Button>
                    <span class="text-xs text-muted-foreground">
                        {{
                            props.connection.manages_hook
                                ? 'The webhook on GitHub is updated for you.'
                                : 'You will need to paste the new secret into the webhook on GitHub.'
                        }}
                    </span>
                </Form>
            </div>
        </div>
    </div>
</template>
