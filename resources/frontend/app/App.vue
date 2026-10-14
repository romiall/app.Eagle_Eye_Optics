<script setup lang="ts"></script>

<template>
  <div class="flex min-h-screen flex-col">
    <header class="border-b border-line bg-brand text-brand-contrast">
      <div class="mx-auto flex w-full max-w-5xl items-center gap-3 px-4 py-3">
        <span
          class="grid size-9 place-items-center rounded-lg bg-brand-contrast/10 text-lg font-semibold"
          aria-hidden="true"
          >E</span
        >
        <p class="text-base font-semibold tracking-tight">Eagle’s Eye Optics Medical</p>
      </div>
    </header>

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
      <RouterView />
    </main>

    <footer class="border-t border-line px-4 py-4 text-center text-sm text-muted">
      Application privée du cabinet — version de développement.
    </footer>
  </div>
</template>
