<script type="text/ecmascript-6">
export default {
    data() {
        return {
            entry: null,
            batch: [],
        };
    },
}
</script>

<template>
    <preview-screen title="Reverb Event Details" resource="reverb" :id="$route.params.id">
        <template slot="table-parameters" slot-scope="slotProps">
            <tr>
                <td class="table-fit text-muted">Event</td>
                <td>
                    <span class="badge badge-info">
                        {{ slotProps.entry.content.event }}
                    </span>
                </td>
            </tr>

            <tr v-if="slotProps.entry.content.socket_id">
                <td class="table-fit text-muted">Socket ID</td>
                <td>{{ slotProps.entry.content.socket_id }}</td>
            </tr>

            <tr v-if="slotProps.entry.content.app_id">
                <td class="table-fit text-muted">App ID</td>
                <td>{{ slotProps.entry.content.app_id }}</td>
            </tr>

            <tr v-if="slotProps.entry.content.channel">
                <td class="table-fit text-muted">Channel</td>
                <td>{{ slotProps.entry.content.channel }}</td>
            </tr>

            <tr v-if="slotProps.entry.content.origin">
                <td class="table-fit text-muted">Origin</td>
                <td>{{ slotProps.entry.content.origin }}</td>
            </tr>
        </template>

        <div slot="after-attributes-card" slot-scope="slotProps">
            <div class="card mt-5 overflow-hidden" v-if="slotProps.entry.content.message">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link active">Message</a>
                    </li>
                </ul>

                <pre class="code-bg p-4 mb-0 text-white">{{ slotProps.entry.content.message }}</pre>
            </div>
        </div>
    </preview-screen>
</template>
