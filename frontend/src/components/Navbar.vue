<script setup>
import { Film, Upload } from 'lucide-vue-next';

defineEmits(['open-upload']);
</script>

<template>
  <nav class="fixed top-0 z-40 w-full border-b border-white/5 bg-dark-900/80 backdrop-blur-md">
    <div class="container mx-auto flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
      <!-- Logo -->
      <div class="flex items-center gap-2">
        <Film class="h-6 w-6 text-purple-500" />
        <span class="text-xl font-bold tracking-tight text-white">
          影<span class="text-purple-500">格</span>
        </span>
      </div>

      <!-- Actions -->
      <button 
        @click="$emit('open-upload')"
        class="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20 active:scale-95"
      >
        <Upload class="h-4 w-4" />
        <span class="hidden sm:inline">导入电影</span>
      </button>
    </div>
  </nav>
  <!-- Spacer to prevent content from hiding under fixed nav -->
  <div class="h-16"></div>
</template>
