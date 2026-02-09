<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ChevronDown, Check, Plus, Server, Monitor, Sparkles } from 'lucide-vue-next';

interface Node {
    id: number;
    name: string;
    host: string;
    is_local: boolean;
    is_default: boolean;
}

const props = defineProps<{
    nodes: Node[];
    currentNode: Node | null;
    collapsed?: boolean;
}>();

const isOpen = ref(false);

const displayName = computed(() => {
    if (props.currentNode) {
        return props.currentNode.name;
    }
    if (props.nodes.length > 0) {
        return props.nodes[0].name;
    }
    return 'No Node';
});

const currentId = computed(() => {
    return props.currentNode?.id ?? props.nodes[0]?.id ?? null;
});

function toggle() {
    isOpen.value = !isOpen.value;
}

function close() {
    isOpen.value = false;
}

function selectNode(node: Node) {
    if (node.id === currentId.value) {
        close();
        return;
    }

    close();
    router.post(`/nodes/${node.id}/set-default`);
}

function handleClickOutside(event: MouseEvent) {
    const target = event.target as HTMLElement;
    if (!target.closest('.node-switcher')) {
        close();
    }
}

import { onMounted, onUnmounted } from 'vue';
onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div class="node-switcher relative">
        <!-- Empty State - No Nodes -->
        <Link
            v-if="nodes.length === 0 && $page.props.multi_node"
            href="/nodes/create"
            class="w-full flex items-center gap-3 px-3 py-2 text-left rounded-lg transition-colors hover:bg-white/5"
            :class="collapsed ? 'justify-center' : ''"
            :title="collapsed ? 'Add Node' : undefined"
        >
            <div
                class="w-7 h-7 rounded-lg bg-zinc-800 flex items-center justify-center flex-shrink-0 border border-dashed border-zinc-600"
            >
                <Plus class="w-4 h-4 text-zinc-500" />
            </div>
            <template v-if="!collapsed">
                <span class="flex-1 text-sm text-zinc-400">Add Node</span>
            </template>
        </Link>

        <!-- Trigger Button -->
        <button
            v-else
            @click="$page.props.multi_node ? toggle() : null"
            class="w-full flex items-center gap-3 px-3 py-2 text-left rounded-lg transition-colors"
            :class="[
                collapsed ? 'justify-center' : '',
                $page.props.multi_node ? 'hover:bg-white/5' : 'cursor-default'
            ]"
            :title="collapsed ? displayName : undefined"
        >
            <div
                class="w-7 h-7 rounded-lg bg-lime-500/15 flex items-center justify-center flex-shrink-0"
            >
                <Sparkles class="w-4 h-4 text-lime-400" />
            </div>
            <template v-if="!collapsed">
                <span class="flex-1 text-sm font-medium text-white truncate">{{
                    displayName
                }}</span>
                <ChevronDown
                    v-if="$page.props.multi_node"
                    class="w-4 h-4 text-zinc-500 transition-transform"
                    :class="{ 'rotate-180': isOpen }"
                />
            </template>
        </button>

        <!-- Dropdown Menu -->
        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div
                v-if="isOpen"
                class="absolute left-0 right-0 mt-1 py-1 bg-zinc-900 border border-zinc-800 rounded-lg shadow-xl z-50"
                :class="collapsed ? 'left-full ml-2 w-48 -mt-12' : ''"
            >
                <!-- Node List -->
                <div class="max-h-64 overflow-y-auto">
                    <button
                        v-for="node in nodes"
                        :key="node.id"
                        @click="selectNode(node)"
                        class="w-full flex items-center gap-3 px-3 py-2 text-left hover:bg-white/5 transition-colors"
                    >
                        <div
                            class="w-6 h-6 rounded bg-zinc-800 flex items-center justify-center flex-shrink-0"
                        >
                            <Monitor v-if="node.is_local" class="w-3.5 h-3.5 text-zinc-400" />
                            <Server v-else class="w-3.5 h-3.5 text-zinc-400" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm text-white truncate">{{ node.name }}</div>
                            <div class="text-xs text-zinc-500 truncate">
                                {{ node.is_local ? 'Local' : node.host }}
                            </div>
                        </div>
                        <Check
                            v-if="node.id === currentId"
                            class="w-4 h-4 text-lime-400 flex-shrink-0"
                        />
                    </button>
                </div>

                <!-- Divider -->
                <div class="border-t border-zinc-800 my-1"></div>

                <!-- Add Node -->
                <Link
                    v-if="$page.props.multi_node"
                    href="/nodes/create"
                    class="flex items-center gap-3 px-3 py-2 text-left hover:bg-white/5 transition-colors"
                    @click="close"
                >
                    <div class="w-6 h-6 rounded bg-zinc-800 flex items-center justify-center">
                        <Plus class="w-3.5 h-3.5 text-zinc-400" />
                    </div>
                    <span class="text-sm text-zinc-400">Add node</span>
                </Link>
            </div>
        </Transition>
    </div>
</template>
