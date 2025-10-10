<template>
    <div class="not-found">
        <div class="not-found-content">
            <h1 class="not-found-title">404</h1>
            <p class="not-found-subtitle">Page not found</p>
            <p class="not-found-text">
                The page you're looking for doesn't exist or has been moved.
            </p>
            <router-link to="/" class="btn btn-primary">
                Go back home
            </router-link>
        </div>
    </div>
</template>

<style scoped>
.not-found {
    @apply min-h-screen flex items-center justify-center bg-gray-50 px-4;
}

.not-found-content {
    @apply text-center;
}

.not-found-title {
    @apply text-9xl font-bold text-indigo-600;
}

.not-found-subtitle {
    @apply text-3xl font-bold text-gray-900 mt-4;
}

.not-found-text {
    @apply text-gray-600 mt-2 mb-8;
}

.btn {
    @apply inline-flex items-center gap-2 px-6 py-3 rounded-lg font-medium transition-all no-underline;
}
.btn-primary {
@apply bg-indigo-600 text-white hover:bg-indigo-700;
}
</style>