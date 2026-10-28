<script>
import StylesMixin from './../../mixins/entriesStyles';

export default {
    mixins: [
        StylesMixin,
    ],


    data(){
        return {
            entry: null,
            batch: [],
            currentTab: 'message'
        };
    },
}
</script>

<template>
    <preview-screen title="Log Details" resource="logs" :id="$route.params.id">
        <template #table-parameters="slotProps">
            <tr>
                <td class="table-fit text-muted">Level</td>
                <td>
                    <span class="badge" :class="'badge-' + logLevelClass(slotProps.entry.content.level)">
                        {{ slotProps.entry.content.level }}
                    </span>
                </td>
            </tr>
        </template>

        <template #after-attributes-card="slotProps">
            <div class="card mt-5 overflow-hidden">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a
                            class="nav-link"
                            :class="{ active: currentTab == 'message' }"
                            href="#"
                            v-on:click.prevent="currentTab = 'message'"
                            >Log Message</a
                        >
                    </li>
                    <li class="nav-item">
                        <a
                            class="nav-link"
                            :class="{ active: currentTab == 'context' }"
                            href="#"
                            v-on:click.prevent="currentTab = 'context'"
                            >Context</a
                        >
                    </li>
                    <li class="nav-item" v-if="slotProps.entry.content.extra">
                        <a
                            class="nav-link"
                            :class="{ active: currentTab == 'extra' }"
                            href="#"
                            v-on:click.prevent="currentTab = 'extra'"
                            >Extra</a
                        >
                    </li>
                </ul>
                <div>
                    <!-- Log Message -->
                    <div v-show="currentTab == 'message'">
                        <copy-clipboard :data="slotProps.entry.content.message">
                            <pre class="code-bg p-4 mb-0 text-white">{{ slotProps.entry.content.message }}</pre>
                        </copy-clipboard>
                    </div>

                    <!-- Context -->
                    <div class="code-bg p-4 mb-0 text-white" v-show="currentTab == 'context'">
                        <copy-clipboard :data="slotProps.entry.content.context">
                            <vue-json-pretty :data="slotProps.entry.content.context"></vue-json-pretty>
                        </copy-clipboard>
                    </div>

                    <!-- Extra (Context facade data) -->
                    <div class="code-bg p-4 mb-0 text-white" v-show="currentTab == 'extra'" v-if="slotProps.entry.content.extra">
                        <copy-clipboard :data="slotProps.entry.content.extra">
                            <vue-json-pretty :data="slotProps.entry.content.extra"></vue-json-pretty>
                        </copy-clipboard>
                    </div>
                </div>
            </div>
        </template>
    </preview-screen>
</template>
