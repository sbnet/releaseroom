<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import RepositoryConnectionController from '@/actions/App/Http/Controllers/RepositoryConnectionController';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toLongDate } from '@/lib/relativeTime';
import { show } from '@/routes/projects';
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection | null;
}>();

/**
 * The token GitHub asks the owner to create. Linked rather than described,
 * because the permission set is the one thing they cannot guess.
 */
const TOKEN_SETTINGS_URL =
    'https://github.com/settings/personal-access-tokens/new';

const repositoryUrl = ref(props.connection?.url ?? '');

const action =
    props.connection === null
        ? RepositoryConnectionController.store.form(props.project.id)
        : RepositoryConnectionController.update.form(props.project.id);
</script>

<template>
    <!--
        The token field is cleared whenever the submission is refused. An
        input left to its own devices keeps its DOM value across the failed
        round trip, so a rejected token would sit there — revealable through
        the show/hide toggle — long after it stopped being any use.
    -->
    <Form
        v-bind="action"
        class="space-y-6"
        :options="{ preserveScroll: true }"
        :reset-on-error="['token']"
        v-slot="{ errors, processing }"
    >
        <!--
            `github` carries the failures that are nobody's typo: GitHub down,
            quota exhausted, or the address quietly pointing somewhere else.
        -->
        <AlertError
            v-if="errors.github"
            :errors="[errors.github]"
            title="Could not reach GitHub"
        />

        <div class="grid gap-2">
            <Label for="repository_url">Repository</Label>
            <Input
                id="repository_url"
                name="repository_url"
                v-model="repositoryUrl"
                required
                autocomplete="off"
                spellcheck="false"
                placeholder="https://github.com/owner/name"
            />
            <p class="text-sm text-muted-foreground">
                Paste the repository address. A link to a pull request or a
                branch works too.
            </p>
            <InputError :message="errors.repository_url" />
        </div>

        <div class="grid gap-2">
            <Label for="token">Personal access token</Label>
            <PasswordInput
                id="token"
                name="token"
                :required="props.connection === null"
                autocomplete="off"
                :placeholder="
                    props.connection === null
                        ? 'github_pat_...'
                        : 'Leave blank to keep the current token'
                "
            />
            <p class="text-sm text-muted-foreground">
                Create a
                <TextLink
                    :href="TOKEN_SETTINGS_URL"
                    target="_blank"
                    rel="noreferrer noopener"
                >
                    fine-grained token
                </TextLink>
                scoped to this repository, with
                <span class="font-medium">Pull requests: Read-only</span>.
                ReleaseRoom stores it encrypted and never shows it again.
            </p>

            <p
                v-if="props.connection"
                class="text-sm text-muted-foreground"
                data-test="token-fingerprint"
            >
                Current token
                <span class="font-mono"
                    >••••{{ props.connection.token_last_four }}</span
                >
                <template v-if="props.connection.created_at">
                    , added
                    {{ toLongDate(props.connection.created_at) }}
                </template>
                <template v-if="props.connection.token_expires_at">
                    , expires
                    {{ toLongDate(props.connection.token_expires_at) }}
                </template>
                .
            </p>

            <InputError :message="errors.token" />
        </div>

        <div class="flex items-center gap-4">
            <!--
                Verification is a round trip to GitHub: the button has to stay
                disabled while it is in flight, or an impatient second click
                spends the quota twice.
            -->
            <Button
                type="submit"
                :disabled="processing"
                data-test="save-repository-button"
            >
                {{
                    processing
                        ? 'Checking with GitHub…'
                        : props.connection === null
                          ? 'Connect repository'
                          : 'Save changes'
                }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="show(props.project.id)">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
