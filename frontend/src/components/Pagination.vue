<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
  currentPage: { type: Number, required: true },
  lastPage: { type: Number, required: true }
});

const emit = defineEmits(['page-change']);

const pages = computed(() => {
  const current = props.currentPage;
  const last = props.lastPage;
  const delta = 2;
  const range = [];
  
  if (last <= 1) return [1];

  for (let i = Math.max(2, current - delta); i <= Math.min(last - 1, current + delta); i++) {
    range.push(i);
  }

  if (current - delta > 2) {
      range.unshift('...');
  }
  
  if (current + delta < last - 1) {
      range.push('...');
  }

  range.unshift(1);
  if (last !== 1) range.push(last);

  return range;
});
</script>

<template>
  <div v-if="lastPage > 1" class="flex items-center justify-center gap-2 py-8">
    <button 
      @click="emit('page-change', currentPage - 1)"
      :disabled="currentPage === 1"
      class="rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-white disabled:opacity-30 disabled:hover:bg-transparent disabled:cursor-not-allowed"
    >
      <ChevronLeft class="h-5 w-5" />
    </button>

    <template v-for="(page, index) in pages" :key="index">
      <span v-if="page === '...'" class="px-2 text-gray-600">...</span>
      <button 
        v-else
        @click="emit('page-change', page)"
        :class="[
          'h-8 min-w-[2rem] rounded-lg px-2 text-sm font-medium transition-colors',
          currentPage === page 
            ? 'bg-purple-600 text-white shadow-lg shadow-purple-500/30' 
            : 'text-gray-400 hover:bg-white/5 hover:text-white'
        ]"
      >
        {{ page }}
      </button>
    </template>

    <button 
      @click="emit('page-change', currentPage + 1)"
      :disabled="currentPage === lastPage"
      class="rounded-lg p-2 text-gray-400 hover:bg-white/5 hover:text-white disabled:opacity-30 disabled:hover:bg-transparent disabled:cursor-not-allowed"
    >
      <ChevronRight class="h-5 w-5" />
    </button>
  </div>
</template>
