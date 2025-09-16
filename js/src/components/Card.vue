<script lang="ts" setup>
import {ref} from 'vue'
import {Setting} from "@element-plus/icons-vue";
import {IGateway} from '@/App.vue';

const props = withDefaults(defineProps<IGateway>(), {
  description: '',
  icon_url: '',
  active: false
})


const value = ref<boolean>(props.active)
</script>


<template>
  <el-card class="rounded-lg max-w-[30rem]" footer-class="py-2" shadow="hover">

    <div class="flex items-center gap-x-4 mb-4">

      <div class="size-12 rounded-xl flex items-center justify-center bg-gray-200">
        <img v-if="icon_url" :alt="name" :src="icon_url" class="size-7 object-contain"/>
      </div>

      <div class="flex-1">
        <h5 class="text-gray-900 font-semibold text-xl m-0 leading-5">{{ name }}</h5>
      </div>
    </div>

    <p class="text-gray-600 text-base">{{ description || `使用 ${name} 收款` }}</p>

    <template #footer>

      <div class="flex justify-between items-center">
        <div class="flex items-center gap-x-2">
          <el-switch
              v-model="value"
              size="small"
          />
          <span>{{ value ? 'Enabled' : 'Disabled' }}</span>
        </div>
        <div>
          <Setting v-if="value" class="text-gray-400 size-5 cursor-pointer"/>
        </div>
      </div>

    </template>
  </el-card>
</template>
