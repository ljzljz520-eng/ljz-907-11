<script setup>
import { ref, onMounted, watch } from 'vue';
import axios from 'axios';
import Navbar from './components/Navbar.vue';
import MovieCard from './components/MovieCard.vue';
import Pagination from './components/Pagination.vue';
import UploadModal from './components/UploadModal.vue';
import MovieDetailModal from './components/MovieDetailModal.vue';
import { Loader2, Film, Search } from 'lucide-vue-next';

const movies = ref([]);
const currentPage = ref(1);
const lastPage = ref(1);
const loading = ref(true);
const isUploadOpen = ref(false);
const isDetailOpen = ref(false);
const selectedMovie = ref(null);
const searchQuery = ref('');

const fetchMovies = async (page = 1) => {
  loading.value = true;
  try {
    const response = await axios.get(`http://localhost:8000/api/movies`, {
      params: {
        page,
        search: searchQuery.value
      }
    });
    movies.value = response.data.data;
    currentPage.value = response.data.current_page;
    lastPage.value = response.data.last_page;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (error) {
    console.error('Failed to fetch movies:', error);
  } finally {
    loading.value = false;
  }
};

// Debounced search
let searchTimeout;
watch(searchQuery, () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchMovies(1);
  }, 300);
});

onMounted(() => {
  fetchMovies();
});

const handlePageChange = (page) => {
  fetchMovies(page);
};

const handleUploadSuccess = () => {
  fetchMovies(1); // Refresh to first page
};

const openDetail = (movie) => {
  selectedMovie.value = movie;
  isDetailOpen.value = true;
};
</script>

<template>
  <div class="min-h-screen pb-20 bg-dark-900">
    <Navbar @open-upload="isUploadOpen = true" />

    <main class="container mx-auto px-4 py-8 sm:px-6 lg:px-8">
      
      <!-- Search Bar -->
      <div class="mb-12 flex justify-center">
        <div class="relative w-full max-w-2xl">
          <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
            <Search class="h-5 w-5 text-gray-500" />
          </div>
          <input 
            v-model="searchQuery"
            type="text" 
            placeholder="搜索电影、导演或演员..."
            class="block w-full rounded-2xl border-none bg-white/5 py-4 pl-12 pr-4 text-white placeholder-gray-500 ring-1 ring-white/10 transition focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-purple-500"
          />
        </div>
      </div>
      
      <!-- Loading State -->
      <div v-if="loading && movies.length === 0" class="flex h-[60vh] items-center justify-center">
        <Loader2 class="h-10 w-10 animate-spin text-purple-500" />
      </div>

      <!-- Empty State -->
      <div v-else-if="movies.length === 0" class="flex h-[60vh] flex-col items-center justify-center text-center">
        <div class="rounded-full bg-white/5 p-6 mb-4">
          <Film class="h-12 w-12 text-gray-500" />
        </div>
        <h2 class="text-xl font-semibold text-white">暂无电影数据</h2>
        <p class="mt-2 text-gray-400 max-w-sm">
          您的收藏夹目前是空的。点击“导入电影”按钮开始添加电影。
        </p>
        <button 
          @click="isUploadOpen = true"
          class="mt-6 rounded-lg bg-purple-600 px-6 py-2.5 font-medium text-white hover:bg-purple-500 transition"
        >
          导入电影
        </button>
      </div>

      <!-- Movie Grid -->
      <div v-else>
        <div class="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
          <MovieCard 
            v-for="movie in movies" 
            :key="movie.id" 
            :movie="movie" 
            @click="openDetail(movie)"
          />
        </div>

        <Pagination 
          :current-page="currentPage" 
          :last-page="lastPage" 
          @page-change="handlePageChange"
        />
      </div>
    </main>

    <UploadModal 
      :is-open="isUploadOpen" 
      @close="isUploadOpen = false"
      @upload-success="handleUploadSuccess"
    />

    <MovieDetailModal 
      :is-open="isDetailOpen"
      :movie="selectedMovie"
      @close="isDetailOpen = false"
    />
  </div>
</template>