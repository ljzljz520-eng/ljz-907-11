<script setup>
import { ref } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { Upload, X, FileText, CheckCircle, AlertCircle } from 'lucide-vue-next';
import axios from 'axios';

const props = defineProps({
  isOpen: Boolean
});

const emit = defineEmits(['close', 'upload-success']);

const isDragging = ref(false);
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const result = ref(null);
const error = ref(null);

const onDrop = (e) => {
  isDragging.value = false;
  const droppedFile = e.dataTransfer.files[0];
  if (droppedFile && droppedFile.type === 'text/csv') {
    file.value = droppedFile;
    error.value = null;
  } else {
    error.value = "Please upload a valid CSV file.";
  }
};

const onFileSelect = (e) => {
  const selectedFile = e.target.files[0];
  if (selectedFile) {
    file.value = selectedFile;
    error.value = null;
  }
};

const upload = async () => {
  if (!file.value) return;

  uploading.value = true;
  progress.value = 0;
  result.value = null;
  error.value = null;

  const formData = new FormData();
  formData.append('file', file.value);

  try {
    // API Call
    const response = await axios.post('http://localhost:8000/api/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (progressEvent) => {
        progress.value = Math.round((progressEvent.loaded * 100) / progressEvent.total);
      }
    });

    result.value = response.data;
    emit('upload-success');
  } catch (err) {
    error.value = err.response?.data?.error || "Upload failed. Please try again.";
  } finally {
    uploading.value = false;
  }
};

const reset = () => {
  file.value = null;
  result.value = null;
  error.value = null;
  progress.value = 0;
};
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
        <div class="fixed inset-0 bg-black/80 backdrop-blur-sm" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
            as="template"
            enter="duration-300 ease-out"
            enter-from="opacity-0 scale-95"
            enter-to="opacity-100 scale-100"
            leave="duration-200 ease-in"
            leave-from="opacity-100 scale-100"
            leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-md transform overflow-hidden rounded-2xl bg-dark-800 p-6 text-left align-middle shadow-xl transition-all border border-white/10">
              <div class="flex items-center justify-between mb-4">
                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-white">
                  导入电影资料
                </DialogTitle>
                <button @click="$emit('close')" class="text-gray-400 hover:text-white">
                  <X class="h-5 w-5" />
                </button>
              </div>

              <div v-if="!result" class="mt-2">
                <div 
                  @dragover.prevent="isDragging = true"
                  @dragleave.prevent="isDragging = false"
                  @drop.prevent="onDrop"
                  :class="[
                    'relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-8 transition-colors',
                    isDragging ? 'border-purple-500 bg-purple-500/10' : 'border-gray-600 hover:border-gray-500 hover:bg-white/5'
                  ]"
                >
                  <Upload class="h-10 w-10 text-gray-400 mb-4" />
                  <p class="text-sm text-gray-300 text-center mb-2">
                    <span class="font-semibold text-purple-400">点击上传</span> 或拖拽文件至此
                  </p>
                  <p class="text-xs text-gray-500">仅支持 CSV 文件 (最大 10MB)</p>
                  <input type="file" accept=".csv" class="absolute inset-0 cursor-pointer opacity-0" @change="onFileSelect" />
                </div>

                <div v-if="file" class="mt-4 flex items-center gap-3 rounded-lg bg-white/5 p-3">
                  <FileText class="h-5 w-5 text-purple-400" />
                  <div class="flex-1 truncate">
                    <p class="text-sm text-white truncate">{{ file.name }}</p>
                    <p class="text-xs text-gray-500">{{ (file.size / 1024).toFixed(1) }} KB</p>
                  </div>
                  <button @click="file = null" class="text-gray-400 hover:text-red-400">
                    <X class="h-4 w-4" />
                  </button>
                </div>

                <div v-if="error" class="mt-3 flex items-start gap-2 text-sm text-red-400 bg-red-400/10 p-2 rounded">
                  <AlertCircle class="h-4 w-4 mt-0.5 shrink-0" />
                  <span>{{ error }}</span>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                  <button @click="$emit('close')" class="px-4 py-2 text-sm font-medium text-gray-300 hover:text-white">取消</button>
                  <button 
                    @click="upload" 
                    :disabled="!file || uploading"
                    class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-500 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <span v-if="uploading" class="animate-spin h-4 w-4 border-2 border-white/20 border-t-white rounded-full"></span>
                    {{ uploading ? '上传中...' : '开始导入' }}
                  </button>
                </div>
              </div>

              <!-- Success State -->
              <div v-else class="text-center py-6">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-500/20 mb-4">
                  <CheckCircle class="h-6 w-6 text-green-500" />
                </div>
                <h4 class="text-lg font-medium text-white">导入完成！</h4>
                <p class="mt-2 text-sm text-gray-400">
                  成功导入 <span class="text-green-400 font-bold">{{ result.imported }}</span> 部电影。
                  <span v-if="result.failed > 0" class="text-red-400">({{ result.failed }} 失败)</span>
                </p>
                
                <div v-if="result.errors && result.errors.length > 0" class="mt-4 max-h-32 overflow-y-auto rounded bg-black/30 p-2 text-left text-xs font-mono text-red-300">
                  <div v-for="(err, i) in result.errors" :key="i">{{ err }}</div>
                </div>

                <div class="mt-6">
                  <button @click="reset" class="text-sm text-gray-400 hover:text-white mr-4">继续导入</button>
                  <button @click="$emit('close')" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">完成</button>
                </div>
              </div>

            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
