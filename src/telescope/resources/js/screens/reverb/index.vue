<script type="text/ecmascript-6">
export default {}
</script>

<template>
    <index-screen title="Reverb" resource="reverb">
        <tr slot="table-header">
            <th scope="col">Event</th>
            <th scope="col">Details</th>
            <th scope="col">Happened</th>
            <th scope="col"></th>
        </tr>

        <template slot="row" slot-scope="slotProps">
            <td class="table-fit">
                <span class="badge badge-info">
                    {{ slotProps.entry.content.event }}
                </span>
            </td>

            <td>
                <span v-if="slotProps.entry.content.channel">
                    {{ slotProps.entry.content.channel }}
                </span>
                <span v-else-if="slotProps.entry.content.socket_id">
                    {{ slotProps.entry.content.socket_id }}
                </span>
            </td>

            <td
                class="table-fit text-muted"
                :data-timeago="slotProps.entry.created_at"
                :title="slotProps.entry.created_at"
            >
                {{ timeAgo(slotProps.entry.created_at) }}
            </td>

            <td class="table-fit">
                <router-link
                    :to="{
                        name: 'reverb-preview',
                        params: { id: slotProps.entry.id },
                    }"
                    class="control-action"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                        <path
                            fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM6.75 9.25a.75.75 0 000 1.5h4.59l-2.1 1.95a.75.75 0 001.02 1.1l3.5-3.25a.75.75 0 000-1.1l-3.5-3.25a.75.75 0 10-1.02 1.1l2.1 1.95H6.75z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </router-link>
            </td>
        </template>
    </index-screen>
</template>
