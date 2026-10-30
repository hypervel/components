<script>
import _ from 'lodash';

export default {
    methods: {
        /**
         * Determine whether any addresses were recorded.
         */
        hasAddresses(addresses) {
            return !_.isEmpty(addresses);
        },

        /**
         * Format the given list of addresses.
         */
        formatAddresses(addresses) {
            return _.chain(addresses)
                .map((name, email) => {
                    return (name ? '<' + name + '> ' : '') + email;
                })
                .join(', ')
                .value();
        },
    },

    data() {
        return {
            entry: null,
            batch: [],
        };
    },
};
</script>

<template>
    <preview-screen title="Mail Details" resource="mail" :id="$route.params.id">
        <template #table-parameters="slotProps">
            <tr>
                <td class="table-fit text-muted">Mailable</td>
                <td>
                    {{ slotProps.entry.content.mailable }}

                    <span class="badge badge-secondary ms-2" v-if="slotProps.entry.content.queued"> Queued </span>
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">From</td>
                <td>
                    {{ formatAddresses(slotProps.entry.content.from) }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">To</td>
                <td>
                    {{ formatAddresses(slotProps.entry.content.to) }}
                </td>
            </tr>

            <tr v-if="hasAddresses(slotProps.entry.content.replyTo)">
                <td class="table-fit text-muted">Reply-To</td>
                <td>
                    {{ formatAddresses(slotProps.entry.content.replyTo) }}
                </td>
            </tr>

            <tr v-if="hasAddresses(slotProps.entry.content.cc)">
                <td class="table-fit text-muted">CC</td>
                <td>
                    {{ formatAddresses(slotProps.entry.content.cc) }}
                </td>
            </tr>

            <tr v-if="hasAddresses(slotProps.entry.content.bcc)">
                <td class="table-fit text-muted">BCC</td>
                <td>
                    {{ formatAddresses(slotProps.entry.content.bcc) }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Subject</td>
                <td>
                    {{ slotProps.entry.content.subject }}
                </td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Download</td>
                <td>
                    <a :href="Telescope.basePath + '/telescope-api/mail/' + $route.params.id + '/download'"
                        >Download .eml file</a
                    >
                </td>
            </tr>
        </template>

        <template #after-attributes-card>
            <div class="card mt-5">
                <iframe
                    :src="Telescope.basePath + '/telescope-api/mail/' + $route.params.id + '/preview'"
                    width="100%"
                    height="400"
                ></iframe>
            </div>
        </template>
    </preview-screen>
</template>

<style scoped>
iframe {
    border: none;
}
</style>
