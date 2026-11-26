<script setup>
import ParentLayout from '@vuepress/theme-default/layouts/Layout.vue'
import VersionNotice from '../components/VersionNotice.vue'
</script>

<template>
  <ParentLayout>
    <template #page-content-top>
      <VersionNotice />
    </template>
  </ParentLayout>
</template>
