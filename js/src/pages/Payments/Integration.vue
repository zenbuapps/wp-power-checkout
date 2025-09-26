<script lang="ts" setup>
import {Back, InfoFilled} from "@element-plus/icons-vue";
import {computed, reactive, ref, toRaw, watch} from 'vue'
import {useRoute} from 'vue-router'
import {useQuery, useMutation, useQueryClient} from "@tanstack/vue-query";
import apiClient from "@/api";
import type {FormInstance, FormRules} from 'element-plus'

interface IFormData {
  mode: string,
  platformId?: string,
  merchantId: string,
  apiKey: string,
  clientKey: string,
  signKey: string,
  allowPaymentMethodList: string[],
}


const route = useRoute()
const settingKey = route.params.id

const {isPending, data} = useQuery({
  queryKey: ['integration_settings', settingKey,],
  queryFn: async () => await apiClient.get<{
    code: string,
    message: string,
    data: IFormData
  }>(`settings/${settingKey}`),
  select: (res) => res.data?.data,
})


// Element Plus 表單 ref
const formRef = ref()

// 表單資料
const form = ref<IFormData>({
  mode: 'test',
  merchantId: '',
  apiKey: '',
  clientKey: '',
  signKey: '',
  allowPaymentMethodList: [
    'CreditCard',
    'VirtualAccount',
    'JKOPay',
    'ApplePay',
    'LinePay',
    'ChaileaseBNPL',
  ]
})

watch(
    data,
    (newData) => {
      if (newData) {
        form.value = {
          ...newData
        } // 將 API 回傳資料填入表單
      }
    },
    {immediate: true}
)

const isTestMode = computed(() => form.value.mode === 'test')

const onSubmit = async () => {
  console.log('submit!', toRaw<IFormData>(form.value))

  await formRef.value.validate((valid: boolean) => {
    console.log('valid', valid)
    if (valid) {
      save(toRaw(form.value)) // 呼叫 mutation
    }
  })
}

const queryClient = useQueryClient()

// 定義 mutation
const {mutate: save, isPending: isSavePending} = useMutation({
  mutationFn: async (payload: IFormData) => await apiClient.post(`/settings/${settingKey}`, payload),
  onSuccess: () => {
    // 成功後可刷新相關快取
    queryClient.invalidateQueries({queryKey: ['integration_settings', settingKey,]})
  },
  onError: (err) => {
    console.error('更新失敗', err)
  }
})

const rules = reactive<FormRules<IFormData>>({
  merchantId: [
    {required: true, message: '此欄位為必填'},
  ],
  apiKey: [
    {required: true, message: '此欄位為必填'},
  ],
  clientKey: [
    {required: true, message: '此欄位為必填'}
  ],
  signKey: [
    {required: true, message: '此欄位為必填'}
  ],
  allowPaymentMethodList: [
    {
      validator: (_, value, callback) => {
        if (Array.isArray(value) && value.length > 0) {
          callback()
        } else {
          callback(new Error('請至少選擇一種付款方式'))
        }
      },
    }
  ],
})


</script>

<template>
  <div class="flex items-center gap-x-2 mb-4 cursor-pointer" @click="$router.push('/payments')">
    <el-icon>
      <Back/>
    </el-icon>
    回《金流》
  </div>

  <el-form
      v-loading="isPending"
      element-loading-background="rgba(255, 255, 255, 0)"
      :model="form" ref="formRef" label-position="right" label-width="auto"
      :class="{
      'opacity-25': isPending,
      }"
      :rules="rules"
      style="max-width: 40rem">
    <el-form-item>
      <template #label>
        <span class="flex gap-x-2 items-center">
          <span>啟用測試模式</span>
          <el-tooltip content="啟用後，將使用測試的串接碼測試付款" placement="top">
            <el-icon><InfoFilled/></el-icon>
          </el-tooltip>
        </span>
      </template>
      <el-switch
          v-model="form.mode"
          active-value="test"
          inactive-value="prod"/>
    </el-form-item>

    <el-form-item prop="platformId">
      <template #label>
        <span class="flex gap-x-2 items-center">
          <span>Platform Id</span>
          <el-tooltip content="SLP 平台 ID，平台特店必填，平台特店底下會有子特店" placement="top">
            <el-icon><InfoFilled/></el-icon>
          </el-tooltip>
        </span>
      </template>
      <el-input v-model="form.platformId" :disabled="isTestMode"/>
    </el-form-item>

    <el-form-item :required="!isTestMode" prop="merchantId">
      <template #label>
        <span class="flex gap-x-2 items-center">
          <span>Merchant Id</span>
          <el-tooltip content="直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID" placement="top">
            <el-icon><InfoFilled/></el-icon>
          </el-tooltip>
        </span>
      </template>
      <el-input v-model="form.merchantId" :disabled="isTestMode"/>
    </el-form-item>

    <el-form-item :required="!isTestMode" prop="apiKey" label="Api Key">
      <el-input v-model="form.apiKey" :disabled="isTestMode"/>
    </el-form-item>

    <el-form-item :required="!isTestMode" prop="clientKey" label="Client Key">
      <el-input v-model="form.clientKey" :disabled="isTestMode"/>
    </el-form-item>

    <el-form-item :required="!isTestMode" prop="signKey" label="Sign Key">
      <el-input v-model="form.signKey" :disabled="isTestMode"/>
      <p class="text-sm text-gray-500">Sign Key 簽名密鑰，需要設定完 WebHook 後，由 Shopline 窗口提供</p>
    </el-form-item>


    <el-form-item prop="allowPaymentMethodList" label="允許的付款方式">
      <el-checkbox-group v-model="form.allowPaymentMethodList">
        <el-checkbox name="allowPaymentMethodList" value="CreditCard">
          信用卡
        </el-checkbox>
        <el-checkbox name="allowPaymentMethodList" value="VirtualAccount">
          ATM 虛擬帳號
        </el-checkbox>
        <el-checkbox name="allowPaymentMethodList" value="JKOPay">
          街口支付
        </el-checkbox>
        <el-checkbox name="allowPaymentMethodList" value="ApplePay">
          Apple Pay
        </el-checkbox>
        <el-checkbox name="allowPaymentMethodList" value="LinePay">
          Line Pay
        </el-checkbox>
        <el-checkbox name="allowPaymentMethodList" value="ChaileaseBNPL">
          中租
        </el-checkbox>
      </el-checkbox-group>
    </el-form-item>

    <el-form-item>
      <el-button v-loading="isSavePending" type="primary" @click="onSubmit">儲存</el-button>
    </el-form-item>
  </el-form>
</template>
