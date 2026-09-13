<script setup>
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { X, Star, Calendar, User, Tag, Globe, MessageSquare, Clock, Edit3, Award, Image as ImageIcon, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
  movie: Object,
  isOpen: Boolean
});

defineEmits(['close']);
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="$emit('close')" class="relative z-50">
      <TransitionChild
        as="template"
        enter="duration-300 ease-out"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="duration-200 ease-in"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/95 backdrop-blur-md" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 md:p-8">
          <TransitionChild
            as="template"
            enter="duration-300 ease-out"
            enter-from="opacity-0 scale-95"
            enter-to="opacity-100 scale-100"
            leave="duration-200 ease-in"
            leave-from="opacity-100 scale-100"
            leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-5xl transform overflow-hidden rounded-3xl bg-dark-800 shadow-2xl transition-all border border-white/5">
              
              <!-- Close Button -->
              <button 
                @click="$emit('close')" 
                class="absolute right-6 top-6 z-20 rounded-full bg-white/5 p-2 text-gray-400 hover:bg-white/10 hover:text-white transition"
              >
                <X class="h-6 w-6" />
              </button>

              <div class="relative flex flex-col lg:flex-row">
                
                <!-- Left Column: Poster -->
                <div class="w-full lg:w-[350px] shrink-0 p-6 md:p-10 lg:pr-0">
                  <div class="relative aspect-[2/3] w-full overflow-hidden rounded-2xl shadow-2xl ring-1 ring-white/10">
                    <img 
                      v-if="movie?.poster_url"
                      :src="movie.poster_url.includes('playwoool.com') ? `http://localhost:8000/api/proxy-image?url=${encodeURIComponent(movie.poster_url)}` : movie.poster_url" 
                      :alt="movie.title"
                      class="h-full w-full object-cover"
                      @error="$event.target.style.display='none'; $event.target.nextElementSibling.style.display='flex'"
                    />
                    <div v-else class="flex h-full w-full items-center justify-center bg-dark-700 text-gray-500" style="display: none;">
                      无海报
                    </div>
                  </div>
                </div>

                <!-- Right Column: Content -->
                <div class="flex flex-1 flex-col p-6 md:p-10">
                  
                  <DialogTitle as="h2" class="mb-2 text-3xl font-bold text-white md:text-4xl">
                    {{ movie?.translated_title || movie?.title }}
                  </DialogTitle>
                  <p class="mb-6 text-xl text-gray-400 font-medium">{{ movie?.title }} ({{ movie?.year }})</p>

                  <!-- Technical Info Grid -->
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 py-6 border-y border-white/5 mb-8 text-sm">
                    <div class="space-y-3">
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">译　　名</span>
                        <span class="text-gray-200">{{ movie?.translated_title || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">片　　名</span>
                        <span class="text-gray-200">{{ movie?.title || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">年　　代</span>
                        <span class="text-gray-200">{{ movie?.year || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">产　　地</span>
                        <span class="text-gray-200">{{ movie?.country || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">类　　别</span>
                        <span class="text-gray-200">{{ movie?.genre || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">语　　言</span>
                        <span class="text-gray-200">{{ movie?.language || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">上映日期</span>
                        <span class="text-gray-200">{{ movie?.release_date || '-' }}</span>
                      </div>
                    </div>
                    
                    <div class="space-y-3">
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">IMDb评分</span>
                        <div class="flex items-center gap-2">
                          <span class="text-yellow-500 font-bold">{{ movie?.imdb_rating || '-' }}</span>
                          <a v-if="movie?.imdb_link" :href="movie.imdb_link" target="_blank" class="text-blue-400 hover:text-blue-300">
                            <ExternalLink class="h-3 w-3" />
                          </a>
                        </div>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">豆瓣评分</span>
                        <div class="flex items-center gap-2">
                          <span class="text-green-500 font-bold">{{ movie?.rating || '-' }}</span>
                          <a v-if="movie?.douban_link" :href="movie.douban_link" target="_blank" class="text-blue-400 hover:text-blue-300">
                            <ExternalLink class="h-3 w-3" />
                          </a>
                        </div>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">片　　长</span>
                        <span class="text-gray-200">{{ movie?.runtime || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">导　　演</span>
                        <span class="text-gray-200">{{ movie?.director || '-' }}</span>
                      </div>
                      <div class="flex items-center gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">编　　剧</span>
                        <span class="text-gray-200">{{ movie?.writer || '-' }}</span>
                      </div>
                      <div class="flex items-start gap-3">
                        <span class="w-20 text-gray-500 font-bold shrink-0">主　　演</span>
                        <span class="text-gray-200 leading-relaxed">{{ movie?.actors || '-' }}</span>
                      </div>
                    </div>
                  </div>

                  <!-- Description -->
                  <div class="mb-10">
                    <div class="flex items-center gap-2 mb-4 text-white">
                      <div class="h-4 w-1 bg-purple-500 rounded-full"></div>
                      <h3 class="text-lg font-bold">简介</h3>
                    </div>
                    <p class="text-gray-400 leading-loose text-base whitespace-pre-wrap">
                      {{ movie?.description || '暂无相关简介资料。' }}
                    </p>
                  </div>

                  <!-- Awards -->
                  <div v-if="movie?.awards" class="mb-10">
                    <div class="flex items-center gap-2 mb-4 text-white">
                      <div class="h-4 w-1 bg-purple-500 rounded-full"></div>
                      <h3 class="text-lg font-bold">获奖情况</h3>
                    </div>
                    <p class="text-gray-400 leading-loose text-sm whitespace-pre-wrap italic">
                      {{ movie.awards }}
                    </p>
                  </div>

                </div>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
