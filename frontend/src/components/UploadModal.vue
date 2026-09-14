<script setup>
import { ref, computed, watch } from 'vue';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import {
  Upload, X, FileText, CheckCircle, AlertCircle, Loader2,
  ArrowLeft, Eye, Database, ShieldAlert, Link2, Filter
} from 'lucide-vue-next';
import axios from 'axios';

const API_BASE = 'http://localhost:8000/api';

const props = defineProps({
  isOpen: Boolean
});

const emit = defineEmits(['close', 'upload-success']);

// step: select(选择文件) -> previewing(生成预览中) -> preview(预览确认) -> importing(写入中) -> result(结果)
const step = ref('select');
const isDragging = ref(false);
const file = ref(null);
const preview = ref(null);
const result = ref(null);
const error = ref(null);
const filter = ref('all'); // all | valid | invalid

// 每次重新打开弹窗时回到初始状态
watch(
  () => props.isOpen,
  (open) => {
    if (open) reset();
  }
);

const reset = () => {
  step.value = 'select';
  file.value = null;
  preview.value = null;
  result.value = null;
  error.value = null;
  filter.value = 'all';
};

const close = () => {
  // 导入正在进行时不允许直接关闭，避免用户误以为数据丢失
  if (step.value === 'importing' || step.value === 'previewing') return;
  emit('close');
};

const onDrop = (e) => {
  isDragging.value = false;
  const droppedFile = e.dataTransfer.files[0];
  if (droppedFile && /\.csv$/i.test(droppedFile.name)) {
    file.value = droppedFile;
    error.value = null;
  } else {
    error.value = '请上传 CSV 格式的文件。';
  }
};

const onFileSelect = (e) => {
  const selectedFile = e.target.files[0];
  if (selectedFile) {
    file.value = selectedFile;
    error.value = null;
  }
};

// 第一步：上传文件，获取校验预览（此阶段不写库）
const generatePreview = async () => {
  if (!file.value) return;

  step.value = 'previewing';
  error.value = null;

  const formData = new FormData();
  formData.append('file', file.value);

  try {
    const { data } = await axios.post(`${API_BASE}/movies/import/preview`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    preview.value = data;
    step.value = 'preview';
  } catch (err) {
    error.value = err.response?.data?.error || '生成预览失败，请检查文件后重试。';
    step.value = 'select';
  }
};

const filteredRows = computed(() => {
  if (!preview.value) return [];
  if (filter.value === 'valid') return preview.value.rows.filter((r) => r.valid);
  if (filter.value === 'invalid') return preview.value.rows.filter((r) => !r.valid);
  return preview.value.rows;
});

const hasError = (row, keyword) => row.errors.some((e) => e.includes(keyword));

// 第二步：管理员确认，只把校验通过的行写入 MySQL
const confirmImport = async () => {
  if (!preview.value?.token) return;

  step.value = 'importing';
  error.value = null;

  try {
    const { data } = await axios.post(`${API_BASE}/movies/import/confirm`, {
      token: preview.value.token,
    });
    result.value = data;
    step.value = 'result';
    emit('upload-success');
  } catch (err) {
    // 失败时明确告知：已有片库数据未被改动
    result.value = {
      status: 'failed',
      error: err.response?.data?.error || '导入失败，已回滚，已有片库数据未受影响。',
      imported: err.response?.data?.imported ?? 0,
      failed: err.response?.data?.failed ?? null,
      errors: err.response?.data?.errors ?? [],
    };
    step.value = 'result';
  }
};

const backToSelect = () => {
  step.value = 'select';
  preview.value = null;
  result.value = null;
  error.value = null;
};
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="close" class="relative z-50">
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
            <DialogPanel
              class="w-full transform overflow-hidden rounded-2xl bg-dark-800 p-6 text-left align-middle shadow-xl transition-all border border-white/10"
              :class="step === 'preview' ? 'max-w-6xl' : 'max-w-md'"
            >
              <div class="flex items-center justify-between mb-4">
                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-white flex items-center gap-2">
                  <Database class="h-5 w-5 text-purple-400" />
                  导入影片资料
                </DialogTitle>
                <button
                  @click="close"
                  :disabled="step === 'importing' || step === 'previewing'"
                  class="text-gray-400 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed"
                >
                  <X class="h-5 w-5" />
                </button>
              </div>

              <!-- ============ 步骤 1：选择文件 ============ -->
              <div v-if="step === 'select'" class="mt-2">
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
                    <span class="font-semibold text-purple-400">点击选择</span> 或拖拽 CSV 文件至此
                  </p>
                  <p class="text-xs text-gray-500">支持 UTF-8 编码的 CSV，最大 50MB</p>
                  <input type="file" accept=".csv,text/csv" class="absolute inset-0 cursor-pointer opacity-0" @change="onFileSelect" />
                </div>

                <div v-if="file" class="mt-4 flex items-center gap-3 rounded-lg bg-white/5 p-3">
                  <FileText class="h-5 w-5 text-purple-400 shrink-0" />
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

                <div class="mt-4 rounded-lg bg-purple-500/5 border border-purple-500/20 p-3 text-xs text-gray-300 leading-relaxed">
                  <p class="flex items-center gap-1.5 text-purple-300 font-medium mb-1">
                    <Eye class="h-3.5 w-3.5" /> 先预览，再导入
                  </p>
                  上传后系统会逐行校验并标出 <span class="text-red-300">重复片名</span>、
                  <span class="text-red-300">年份格式错误</span>、
                  <span class="text-red-300">缺少分类</span> 的行；只有您确认后，
                  通过校验的数据才会写入片库。
                </div>

                <div class="mt-6 flex justify-end gap-3">
                  <button @click="emit('close')" class="px-4 py-2 text-sm font-medium text-gray-300 hover:text-white">取消</button>
                  <button
                    @click="generatePreview"
                    :disabled="!file"
                    class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-500 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                  >
                    <Eye class="h-4 w-4" />
                    上传并生成预览
                  </button>
                </div>
              </div>

              <!-- ============ 加载中 ============ -->
              <div v-else-if="step === 'previewing' || step === 'importing'" class="py-16 flex flex-col items-center justify-center gap-4">
                <Loader2 class="h-10 w-10 animate-spin text-purple-500" />
                <p class="text-sm text-gray-300">
                  {{ step === 'previewing' ? '正在解析并校验 CSV，请稍候…' : '正在将有效数据写入片库，请稍候…' }}
                </p>
              </div>

              <!-- ============ 步骤 2：预览确认 ============ -->
              <div v-else-if="step === 'preview' && preview" class="mt-2">
                <!-- 汇总条 -->
                <div class="flex flex-wrap items-center gap-3 mb-4">
                  <div class="flex items-center gap-2 rounded-lg bg-white/5 px-3 py-2 text-sm">
                    <FileText class="h-4 w-4 text-gray-400" />
                    <span class="text-gray-300">共 <span class="text-white font-semibold">{{ preview.total }}</span> 行</span>
                  </div>
                  <div class="flex items-center gap-2 rounded-lg bg-green-500/10 border border-green-500/20 px-3 py-2 text-sm">
                    <CheckCircle class="h-4 w-4 text-green-400" />
                    <span class="text-green-300">可导入 <span class="font-semibold">{{ preview.valid_count }}</span> 部</span>
                  </div>
                  <div
                    v-if="preview.invalid_count > 0"
                    class="flex items-center gap-2 rounded-lg bg-red-500/10 border border-red-500/20 px-3 py-2 text-sm"
                  >
                    <AlertCircle class="h-4 w-4 text-red-400" />
                    <span class="text-red-300"><span class="font-semibold">{{ preview.invalid_count }}</span> 行有问题，将被跳过</span>
                  </div>

                  <div class="ml-auto flex items-center gap-1 rounded-lg bg-black/30 p-1 text-xs">
                    <Filter class="h-3.5 w-3.5 text-gray-500 ml-1" />
                    <button
                      v-for="opt in [
                        { v: 'all', label: '全部' },
                        { v: 'invalid', label: '仅问题行' },
                        { v: 'valid', label: '仅有效行' }
                      ]"
                      :key="opt.v"
                      @click="filter = opt.v"
                      :class="[
                        'px-2.5 py-1 rounded-md transition-colors',
                        filter === opt.v ? 'bg-purple-600 text-white' : 'text-gray-400 hover:text-white'
                      ]"
                    >{{ opt.label }}</button>
                  </div>
                </div>

                <!-- 预览表格 -->
                <div class="rounded-xl border border-white/10 overflow-hidden">
                  <div class="max-h-[52vh] overflow-auto">
                    <table class="w-full text-sm">
                      <thead class="bg-black/40 sticky top-0 z-10">
                        <tr class="text-left text-xs text-gray-400">
                          <th class="px-3 py-2.5 font-medium w-14">行号</th>
                          <th class="px-3 py-2.5 font-medium">片名</th>
                          <th class="px-3 py-2.5 font-medium">导演</th>
                          <th class="px-3 py-2.5 font-medium w-20">年份</th>
                          <th class="px-3 py-2.5 font-medium w-24">分类</th>
                          <th class="px-3 py-2.5 font-medium w-20">时长</th>
                          <th class="px-3 py-2.5 font-medium w-48">海报地址</th>
                          <th class="px-3 py-2.5 font-medium w-44">校验结果</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-white/5">
                        <tr
                          v-for="row in filteredRows"
                          :key="row.row_number"
                          :class="row.valid ? 'hover:bg-white/5' : 'bg-red-500/5 hover:bg-red-500/10'"
                        >
                          <td class="px-3 py-2 text-gray-500 text-xs align-top pt-2.5">{{ row.row_number }}</td>
                          <td class="px-3 py-2 align-top">
                            <p class="text-white break-all" :class="{ 'text-red-300': hasError(row, '片名') }">
                              {{ row.data.title || '—' }}
                            </p>
                            <div v-if="row.warnings.length" class="mt-1 flex flex-wrap gap-1">
                              <span
                                v-for="(w, i) in row.warnings"
                                :key="i"
                                class="inline-flex items-center gap-1 rounded bg-amber-500/15 text-amber-300 px-1.5 py-0.5 text-[11px]"
                              >{{ w }}</span>
                            </div>
                          </td>
                          <td class="px-3 py-2 text-gray-300 align-top break-all">{{ row.data.director || '—' }}</td>
                          <td class="px-3 py-2 align-top">
                            <span :class="hasError(row, '年份') ? 'text-red-300 font-medium' : 'text-gray-300'">
                              {{ row.raw.year || '—' }}
                            </span>
                          </td>
                          <td class="px-3 py-2 align-top">
                            <span :class="hasError(row, '分类') ? 'text-red-300 font-medium' : 'text-gray-300'">
                              {{ row.data.genre || '—' }}
                            </span>
                          </td>
                          <td class="px-3 py-2 text-gray-300 align-top whitespace-nowrap">{{ row.data.runtime || '—' }}</td>
                          <td class="px-3 py-2 align-top">
                            <a
                              v-if="row.data.poster_url"
                              :href="row.data.poster_url"
                              target="_blank"
                              rel="noopener"
                              :title="row.data.poster_url"
                              class="inline-flex items-start gap-1 text-gray-400 hover:text-purple-300 text-xs break-all"
                            >
                              <Link2 class="h-3 w-3 mt-0.5 shrink-0" />
                              <span class="line-clamp-2">{{ row.data.poster_url }}</span>
                            </a>
                            <span v-else class="text-gray-600 text-xs">—</span>
                          </td>
                          <td class="px-3 py-2 align-top">
                            <span v-if="row.valid" class="inline-flex items-center gap-1 text-green-400 text-xs">
                              <CheckCircle class="h-3.5 w-3.5" /> 通过
                            </span>
                            <div v-else class="flex flex-wrap gap-1">
                              <span
                                v-for="(msg, i) in row.errors"
                                :key="i"
                                class="inline-flex items-center gap-1 rounded bg-red-500/15 text-red-300 px-1.5 py-0.5 text-[11px]"
                              >
                                <AlertCircle class="h-3 w-3 shrink-0" />{{ msg }}
                              </span>
                            </div>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div v-if="preview.invalid_count > 0" class="mt-3 flex items-start gap-2 text-xs text-gray-400">
                  <ShieldAlert class="h-4 w-4 text-amber-400 shrink-0 mt-0.5" />
                  <span>标红的问题行不会被导入，请修正 CSV 后重新上传；确认后仅写入 {{ preview.valid_count }} 行有效数据。</span>
                </div>

                <div class="mt-5 flex justify-between items-center">
                  <button
                    @click="backToSelect"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-gray-300 hover:text-white"
                  >
                    <ArrowLeft class="h-4 w-4" />
                    重新选择文件
                  </button>
                  <button
                    @click="confirmImport"
                    :disabled="preview.valid_count === 0"
                    class="rounded-lg bg-green-600 px-5 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-40 disabled:cursor-not-allowed inline-flex items-center gap-2"
                  >
                    <Database class="h-4 w-4" />
                    确认导入（{{ preview.valid_count }} 部）
                  </button>
                </div>
              </div>

              <!-- ============ 步骤 3：导入结果 ============ -->
              <div v-else-if="step === 'result' && result" class="text-center py-6">
                <template v-if="result.status === 'success'">
                  <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-500/20 mb-4">
                    <CheckCircle class="h-6 w-6 text-green-500" />
                  </div>
                  <h4 class="text-lg font-medium text-white">导入完成</h4>
                  <p class="mt-2 text-sm text-gray-400">
                    成功写入 <span class="text-green-400 font-bold">{{ result.imported }}</span> 部影片。
                    <span v-if="result.failed > 0" class="text-red-400">（{{ result.failed }} 行未能写入，已跳过）</span>
                  </p>
                  <div v-if="result.errors && result.errors.length" class="mt-4 max-h-32 overflow-y-auto rounded bg-black/30 p-2 text-left text-xs font-mono text-red-300">
                    <div v-for="(err, i) in result.errors" :key="i">{{ err }}</div>
                  </div>
                  <div class="mt-6">
                    <button @click="reset" class="text-sm text-gray-400 hover:text-white mr-4">继续导入</button>
                    <button @click="emit('close')" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">完成</button>
                  </div>
                </template>

                <template v-else>
                  <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-500/20 mb-4">
                    <ShieldAlert class="h-6 w-6 text-red-400" />
                  </div>
                  <h4 class="text-lg font-medium text-white">导入失败</h4>
                  <p class="mt-2 text-sm text-red-300">{{ result.error }}</p>
                  <div class="mt-3 inline-flex items-center gap-2 rounded-lg bg-white/5 px-3 py-2 text-xs text-gray-400">
                    <ShieldAlert class="h-4 w-4 text-amber-400" />
                    本次写入已整体回滚，片库中原有的影片数据保持不变。
                  </div>
                  <div v-if="result.errors && result.errors.length" class="mt-4 max-h-32 overflow-y-auto rounded bg-black/30 p-2 text-left text-xs font-mono text-red-300">
                    <div v-for="(err, i) in result.errors" :key="i">{{ err }}</div>
                  </div>
                  <div class="mt-6">
                    <button @click="backToSelect" class="text-sm text-gray-400 hover:text-white mr-4">重新选择文件</button>
                    <button @click="emit('close')" class="rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20">关闭</button>
                  </div>
                </template>
              </div>

            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
