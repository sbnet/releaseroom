<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import RepositoryConnectionController from '@/actions/App/Http/Controllers/RepositoryConnectionController';
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
import type { Project, RepositoryConnection } from '@/types';

const props = defineProps<{
    project: Project;
    connection: RepositoryConnection;
}>();
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Disconnect repository"
            description="Detach this repository and destroy the stored token"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">Warning</p>
                <p class="text-sm">
                    The token you stored for
                    <span class="font-medium">{{
                        props.connection.full_name
                    }}</span>
                    is destroyed and cannot be recovered. Reconnecting means
                    pasting a new one.
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        data-test="disconnect-repository-button"
                    >
                        Disconnect repository
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="
                            RepositoryConnectionController.destroy.form(
                                props.project.id,
                            )
                        "
                        :options="{ preserveScroll: true }"
                        class="space-y-6"
                        v-slot="{ processing }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>
                                Disconnect {{ props.connection.full_name }}?
                            </DialogTitle>
                            <DialogDescription>
                                This project stops reading from this repository
                                and the stored token is permanently deleted.
                                This cannot be undone.
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
                                data-test="confirm-disconnect-repository-button"
                            >
                                Disconnect repository
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
