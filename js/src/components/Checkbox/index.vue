<script setup lang="ts">
import type { CheckboxProps } from './types'
const props = defineProps<CheckboxProps>()
</script>

<template>
	<el-tooltip
		v-if="!!props?.tooltip"
		effect="dark"
		:content="props?.tooltip"
		placement="top-start"
	>
		<el-checkbox :label="props.value" :disabled="props.disabled">
			{{ props.label }}
		</el-checkbox>
	</el-tooltip>

	<el-checkbox
		v-if="!props?.tooltip"
		:label="props.value"
		:disabled="props.disabled"
	>
		{{ props.label }}
	</el-checkbox>
</template>

<style scoped></style>
