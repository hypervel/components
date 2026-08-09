<script type="text/ecmascript-6">
export default {
    data(){
        return {
            entry: null,
            batch: [],
        };
    }
}
</script>

<template>
    <preview-screen title="Scheduled Command Details" resource="requests" :id="$route.params.id">
        <template slot="table-parameters" slot-scope="slotProps">
            <tr>
                <td class="table-fit text-muted">Description</td>
                <td>
                    {{ slotProps.entry.content.description || '-' }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Command</td>
                <td>
                    <code>{{ slotProps.entry.content.command || '-' }}</code>
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Expression</td>
                <td>
                    {{ slotProps.entry.content.expression }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">User</td>
                <td>
                    {{ slotProps.entry.content.user || '-' }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Timezone</td>
                <td>
                    {{ slotProps.entry.content.timezone || '-' }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Status</td>
                <td>
                    <span
                        class="badge"
                        :class="slotProps.entry.content.status === 'finished' ? 'badge-success' : 'badge-danger'"
                    >
                        {{ slotProps.entry.content.status }}
                    </span>
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Exit Code</td>
                <td>
                    {{ slotProps.entry.content.exit_code === undefined || slotProps.entry.content.exit_code === null
                        ? '-'
                        : slotProps.entry.content.exit_code }}
                </td>
            </tr>

            <tr v-if="slotProps.entry.content.exception">
                <td class="table-fit text-muted">Exception</td>
                <td>
                    <code>{{ slotProps.entry.content.exception.class }}</code>
                </td>
            </tr>

            <tr v-if="slotProps.entry.content.exception">
                <td class="table-fit text-muted">Exception Message</td>
                <td>
                    {{ slotProps.entry.content.exception.message }}
                </td>
            </tr>
        </template>

        <div slot="after-attributes-card" slot-scope="slotProps" v-if="slotProps.entry.content.output">
            <div class="card mt-5 overflow-hidden">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link active">Output</a>
                    </li>
                </ul>

                <copy-clipboard :data="slotProps.entry.content.output">
                    <pre class="code-bg p-4 mb-0 text-white">{{ slotProps.entry.content.output }}</pre>
                </copy-clipboard>
            </div>
        </div>
    </preview-screen>
</template>
