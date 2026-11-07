<script setup lang="ts">
/**
 * Lean, touch-first layout for handhelds: one-row nav bar, flash messages, big content.
 * The bar's title, Back target and menu links come from the server as the `nav` prop.
 * Pass `leaveWarning` while leaving would abandon work; every visit away from the page
 * then has to be confirmed. Leaving never touches the pick-list claim.
 */
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import FlashMessages from '@/components/FlashMessages.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { type SharedData, type WarehouseNav } from '@/types';
import type { PendingVisit } from '@inertiajs/core';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Barcode, ChevronLeft, LayoutGrid, ListChecks, LogOut, Menu, ScanLine, ShoppingCart } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ leaveWarning?: string | null }>();
const page = usePage<SharedData>();
const nav = computed(() => page.props.nav as WarehouseNav);
const icons = { Dashboard: LayoutGrid, 'Pick & pack (phone/handheld)': ScanLine, 'Capture barcodes': Barcode, 'Pick lists (desktop)': ListChecks, Orders: ShoppingCart };

const menuOpen = ref(false);
const pending = ref<PendingVisit | null>(null);
const confirmOpen = computed(() => pending.value !== null);
let confirmed = false;

// Scanners type into whatever has focus and press Enter, so focus must not rest on the bar.
const focusScan = () => {
    if (!menuOpen.value && !pending.value) document.querySelector<HTMLInputElement>('[data-scan-input]')?.focus({ preventScroll: true });
};
const returnFocus = (event: Event) => {
    event.preventDefault();
    focusScan();
};
// Radix would focus the first link, where a stray scan's Enter would follow it.
const focusSheet = (event: Event) => {
    event.preventDefault();
    (event.target as HTMLElement).focus();
};

const leavesPage = (visit: PendingVisit) =>
    visit.method === 'get' ? visit.url.pathname !== window.location.pathname : visit.url.pathname === new URL(route('logout')).pathname;

let stopGuard: VoidFunction | undefined;
onMounted(() => {
    stopGuard = router.on('before', (event) => {
        menuOpen.value = false;
        const visit = event.detail.visit;
        if (confirmed || !props.leaveWarning || !leavesPage(visit)) return;
        pending.value = visit;
        return false;
    });
});
onBeforeUnmount(() => stopGuard?.());

const stay = () => (pending.value = null);
const leave = () => {
    const visit = pending.value!;
    pending.value = null;
    confirmed = true;
    router.visit(visit.url, { method: visit.method, data: visit.data, onFinish: () => (confirmed = false) });
};
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="sticky top-0 z-10 flex h-14 items-center gap-1 border-b bg-background px-1">
            <Link
                :href="nav.back"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md hover:bg-accent"
                aria-label="Back"
                @mousedown.prevent
            >
                <ChevronLeft class="size-7" />
            </Link>
            <AppLogoIcon class-name="size-7 shrink-0" />
            <h1 class="min-w-0 flex-1 truncate text-center text-sm font-semibold">{{ nav.title }}</h1>
            <button
                type="button"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md hover:bg-accent"
                aria-label="Menu"
                @mousedown.prevent
                @click="menuOpen = true"
            >
                <Menu class="size-6" />
            </button>
        </header>
        <FlashMessages />
        <main class="mx-auto max-w-2xl p-3">
            <slot />
        </main>

        <Sheet v-model:open="menuOpen">
            <SheetContent side="right" class="flex flex-col gap-2 p-4" @open-auto-focus="focusSheet" @close-auto-focus="returnFocus">
                <SheetHeader class="text-left">
                    <SheetTitle>Menu</SheetTitle>
                    <SheetDescription class="truncate">{{ page.props.auth.user?.name }}</SheetDescription>
                </SheetHeader>
                <nav class="flex flex-col gap-1">
                    <Link
                        v-for="link in nav.links"
                        :key="link.href"
                        :href="link.href"
                        class="flex h-12 items-center gap-3 rounded-md px-3 font-medium hover:bg-accent"
                    >
                        <component :is="icons[link.title as keyof typeof icons]" class="size-5 text-muted-foreground" />
                        {{ link.title }}
                    </Link>
                    <Link
                        method="post"
                        :href="route('logout')"
                        as="button"
                        class="flex h-12 w-full items-center gap-3 rounded-md px-3 font-medium hover:bg-accent"
                    >
                        <LogOut class="size-5 text-muted-foreground" />
                        Log out
                    </Link>
                </nav>
            </SheetContent>
        </Sheet>

        <Dialog :open="confirmOpen" @update:open="stay">
            <DialogContent class="max-w-[calc(100vw-2rem)] rounded-lg sm:max-w-md" @close-auto-focus="returnFocus">
                <DialogHeader class="text-left">
                    <DialogTitle>Leave this screen?</DialogTitle>
                    <DialogDescription>{{ leaveWarning }}</DialogDescription>
                </DialogHeader>
                <!-- Stay comes first so it takes focus: a scanner's Enter must not confirm leaving. -->
                <DialogFooter class="grid grid-cols-2 gap-2 sm:space-x-0">
                    <Button class="h-12" @click="stay">Stay</Button>
                    <Button class="h-12" variant="outline" @click="leave">Leave</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
