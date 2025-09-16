<script lang="ts">
export interface IGateway {
  name: string
  description?: string
  icon_url?: string
  active?: boolean
}

enum ETabKey {
  Payment = 'payment',
  Invoice = 'invoice',
  Setting = 'setting'
}
</script>

<script lang="ts" setup>
import {ref, reactive} from 'vue'
import type {TabsPaneContext} from 'element-plus'
import Card from '@/components/Card.vue'


const activeName = ref(ETabKey.Payment)

const gateways = reactive<IGateway[]>([
  {
    name: 'Shopline Payment',
  },
  {
    name: 'PAYUni | 統一金流',
  },
  {
    name: 'ECPay | 綠界科技',
  },
  {
    name: 'NeWebPay | 藍新金流',
  },
  {
    name: 'PayPal',
  },
  {
    name: 'PayNow | 立吉富線上金流',
  },
  {
    name: 'TapPay',
  },
])

const handleClick = (tab: TabsPaneContext, event: Event) => {
  console.log(tab, event)
}
</script>


<template>
  <div class="min-h-[40rem]">
    <el-tabs v-model="activeName" :tab-position="'left'" @tab-click="handleClick">
      <el-tab-pane :name="ETabKey.Payment" class="px-8" label="金流">
        <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-5">
          <Card v-for="gateway in gateways" :key="gateway.name" v-bind="gateway"/>
        </div>

      </el-tab-pane>
      <el-tab-pane :name="ETabKey.Invoice" label="電子發票">電子發票</el-tab-pane>
      <el-tab-pane :name="ETabKey.Setting" label="設定">Config</el-tab-pane>
    </el-tabs>
  </div>
</template>
