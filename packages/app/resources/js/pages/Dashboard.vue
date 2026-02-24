<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Server as ServerIcon } from 'lucide-vue-next';
import NodeCard from '@/components/NodeCard.vue';
import { Button } from '@hardimpactdev/craft-ui';

interface Node {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    is_default: boolean;
}

defineProps<{
    nodes: Node[];
    defaultNode: Node | null;
}>();
</script>

<template>
    <Head title="Dashboard" />

    <div>
        <!-- Header -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Dashboard</h1>
            </div>
            <Button v-if="$page.props.multi_node" as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                <Link href="/nodes/create">
                    <ServerIcon class="w-4 h-4 mr-1.5" />
                    Add Node
                </Link>
            </Button>
        </header>

        <!-- Empty State -->
        <div
            v-if="nodes.length === 0"
            class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center"
        >
            <ServerIcon class="w-16 h-16 mx-auto text-zinc-600 mb-4" />
            <h3 class="text-lg font-medium text-zinc-100 mb-2">No nodes configured</h3>
            <p class="text-zinc-400 mb-4">Get started by adding your first node.</p>
            <Button v-if="$page.props.multi_node" as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                <Link href="/nodes/create">
                    <ServerIcon class="w-4 h-4 mr-1.5" />
                    Add Node
                </Link>
            </Button>
        </div>

        <!-- Node Cards Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <NodeCard
                v-for="node in nodes"
                :key="node.id"
                :node="node"
            />
        </div>
    </div>
</template>
