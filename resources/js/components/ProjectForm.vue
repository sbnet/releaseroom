<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SLUG_MAX_LENGTH, toSlug } from '@/lib/slug';
import { index, show } from '@/routes/projects';
import type { Project } from '@/types';

const props = defineProps<{
    project?: Project;
}>();

const name = ref(props.project?.name ?? '');
const slug = ref(props.project?.slug ?? '');
const description = ref(props.project?.description ?? '');

/*
 * While creating, the slug trails the name until the user takes it over.
 * An existing project already has a slug the user chose, so it is never
 * rewritten from under them.
 */
const slugIsUserOwned = ref(props.project !== undefined);

watch(name, (value) => {
    if (!slugIsUserOwned.value) {
        slug.value = toSlug(value);
    }
});

const action =
    props.project === undefined
        ? ProjectController.store.form()
        : ProjectController.update.form(props.project.id);

const cancelHref =
    props.project === undefined ? index() : show(props.project.id);
</script>

<template>
    <Form
        v-bind="action"
        class="space-y-6"
        :options="{ preserveScroll: true }"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input
                id="name"
                name="name"
                v-model="name"
                required
                autocomplete="off"
                placeholder="Acme Platform"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="slug">Public slug</Label>
            <Input
                id="slug"
                name="slug"
                v-model="slug"
                required
                autocomplete="off"
                spellcheck="false"
                :maxlength="SLUG_MAX_LENGTH"
                placeholder="acme-platform"
                @input="slugIsUserOwned = true"
            />
            <p class="text-sm text-muted-foreground">
                The address of your public release page. Lowercase letters,
                numbers and hyphens, unique across ReleaseRoom.
            </p>
            <InputError :message="errors.slug" />
        </div>

        <div class="grid gap-2">
            <Label for="description">Description</Label>
            <textarea
                id="description"
                name="description"
                v-model="description"
                rows="3"
                maxlength="280"
                placeholder="What this project ships, in a sentence."
                class="flex w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30"
            ></textarea>
            <InputError :message="errors.description" />
        </div>

        <div class="flex items-center gap-4">
            <Button
                type="submit"
                :disabled="processing"
                data-test="save-project-button"
            >
                {{
                    props.project === undefined
                        ? 'Create project'
                        : 'Save changes'
                }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="cancelHref">Cancel</Link>
            </Button>
        </div>
    </Form>
</template>
