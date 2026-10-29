<script>
import hljs from 'highlight.js/lib/core';
import sql from 'highlight.js/lib/languages/sql';
import { format, supportedDialects } from 'sql-formatter';

hljs.registerLanguage('sql', sql);

// REMOVED: the json grammar, the mongodb alias and formatQuery()'s JSON branch; Hypervel does not support MongoDB.
const driverAliases = {
    pgsql: 'postgresql',
    sqlsrv: 'transactsql',
};

export default {
    methods: {
        highlightQuery() {
            this.$nextTick(() => {
                if (this.$refs.querycode) {
                    hljs.highlightElement(this.$refs.querycode);
                }
            });
        },

        /**
         * Format the recorded query for display with the SQL dialect
         * matching its connection driver.
         */
        formatQuery(query, driver) {
            driver = driverAliases[driver] || driver;

            const dialect = this.sqlDialect(driver);

            if (dialect) {
                return format(query, { language: dialect });
            }

            return query;
        },

        /**
         * Resolve the SQL dialect matching the given connection driver.
         * Returns null when the formatter does not support the driver.
         */
        sqlDialect(driver) {
            if (!driver) {
                return 'sql';
            }

            return supportedDialects.includes(driver) ? driver : null;
        },

        // REMOVED: queryLanguage(); without JSON commands, every query highlights as SQL.
    },
};
</script>

<template>
    <preview-screen title="Query Details" resource="queries" :id="$route.params.id" v-on:ready="highlightQuery()">
        <template #table-parameters="slotProps">
            <tr>
                <td class="table-fit text-muted">Connection</td>
                <td>
                    {{ slotProps.entry.content.connection }}
                </td>
            </tr>

            <tr v-if="slotProps.entry.content.file">
                <td class="table-fit text-muted">Location</td>
                <td>{{ slotProps.entry.content.file }}:{{ slotProps.entry.content.line }}</td>
            </tr>

            <tr>
                <td class="table-fit text-muted">Duration</td>
                <td>
                    <span class="badge badge-danger" v-if="slotProps.entry.content.slow">
                        {{ slotProps.entry.content.time }}ms
                    </span>

                    <span v-else> {{ slotProps.entry.content.time }}ms </span>
                </td>
            </tr>
        </template>

        <template #after-attributes-card="slotProps">
            <div class="card mt-5 overflow-hidden">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link active">Query</a>
                    </li>
                </ul>
                <div class="code-bg p-4 mb-0 text-white">
                    <copy-clipboard :data="formatQuery(slotProps.entry.content.sql, slotProps.entry.content.driver)">
                        <pre class="code-bg text-white language-sql" ref="querycode">{{
                            formatQuery(slotProps.entry.content.sql, slotProps.entry.content.driver)
                        }}</pre>
                    </copy-clipboard>
                </div>
            </div>
        </template>
    </preview-screen>
</template>
