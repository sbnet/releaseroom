<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { Project } from '@/types';

const props = defineProps<{
    project: Project;
}>();
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Delete project"
            description="Delete this project and everything it will ever hold"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Warning</p>
                <p class="text-sm">
                    Please proceed with caution, this cannot be undone. The slug
                    <span class="font-medium">{{ props.project.slug }}</span>
                    becomes available to anyone again.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        data-test="delete-project-button"
                    >
                        Delete project
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="
                            ProjectController.destroy.form(props.project.id)
                        "
                        :options="{ preserveScroll: true }"
                        class="space-y-6"
                        v-slot="{ processing }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>
                                Delete {{ props.project.name }}?
                            </DialogTitle>
                            <DialogDescription>
                                Once this project is deleted, all of its
                                resources and data will also be permanently
                                deleted. This cannot be undone.
                            </DialogDescription>
                        </DialogHeader>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-project-button"
                            >
                                Delete project
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
