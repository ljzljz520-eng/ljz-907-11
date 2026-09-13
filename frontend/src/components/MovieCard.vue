<script setup>
import { computed } from 'vue';
import { Star } from 'lucide-vue-next';

const props = defineProps({
  movie: {
    type: Object,
    required: true
  }
});

const poster = computed(() => {
  if (!props.movie.poster_url) return null;
  // 如果URL来自playwoool.com（可能有403问题），使用代理
  if (props.movie.poster_url.includes('playwoool.com')) {
    return `http://localhost:8000/api/proxy-image?url=${encodeURIComponent(props.movie.poster_url)}`;
  }
  return props.movie.poster_url;
});

// 处理图片加载错误
const handleImageError = (event) => {
  const img = event.target;
  // 隐藏图片显示占位符
  img.style.display = 'none';
  const placeholder = img.nextElementSibling;
  if (placeholder) {
    placeholder.style.display = 'flex';
  }
};
</script>

<template>
  <div class="group relative flex flex-col gap-2 cursor-pointer">
    <!-- Poster Container -->
    <div class="relative aspect-[2/3] overflow-hidden rounded-lg bg-dark-800 shadow-lg ring-1 ring-white/5 transition-all duration-300 group-hover:-translate-y-1 group-hover:shadow-purple-500/20 group-hover:ring-purple-500/50">
      
      <!-- Image -->
      <img 
        v-if="poster" 
        :src="poster" 
        :alt="movie.title"
        class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
        loading="lazy"
        @error="handleImageError"
      />
      
      <!-- Fallback Placeholder -->
      <div 
        :class="['absolute inset-0 flex flex-col items-center justify-center bg-dark-800 p-4 text-center text-gray-500', poster ? 'hidden' : 'flex']"
      >
        <span class="text-xs uppercase tracking-widest opacity-50">无海报</span>
        <span class="mt-2 font-serif text-lg text-gray-400">{{ movie.title }}</span>
      </div>

      <!-- Rating Badge (Top Right) -->
      <div v-if="movie.rating > 0" class="absolute right-2 top-2 flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-xs font-bold text-yellow-400 backdrop-blur-sm">
        <Star class="h-3 w-3 fill-current" />
        <span>{{ movie.rating }}</span>
      </div>
      
      <!-- Hover Overlay -->
      <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100">
        <div class="absolute bottom-0 p-4 w-full">
          <p class="text-xs font-medium text-gray-300 line-clamp-3">{{ movie.description }}</p>
        </div>
      </div>
    </div>

    <!-- Meta Info -->
    <div class="mt-1">
      <h3 class="truncate text-sm font-medium text-gray-100 group-hover:text-purple-400 transition-colors">
        {{ movie.translated_title || movie.title }}
      </h3>
      <div class="flex items-center justify-between text-xs text-gray-500">
        <span>{{ movie.year }}</span>
        <span v-if="movie.genre" class="max-w-[60%] truncate rounded border border-white/10 px-1.5 py-0.5 text-[10px] uppercase tracking-wider">{{ movie.genre.split(',')[0] }}</span>
      </div>
    </div>
  </div>
</template>
