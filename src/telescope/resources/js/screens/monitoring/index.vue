<script>
import { Modal } from 'bootstrap';
import _ from 'lodash';
import axios from 'axios';

export default {
    /**
     * The component's data.
     */
    data() {
        return {
            tags: [],
            ready: false,
            newTag: '',
            newTagModal: null,
            requestController: new AbortController(),
        };
    },

    /**
     * Prepare the component.
     */
    mounted() {
        document.title = 'Monitoring - Telescope';

        this.newTagModal = Modal.getOrCreateInstance(this.$refs.addTagModal, {
            backdrop: 'static',
        });

        const { signal } = this.requestController;

        axios
            .get(Telescope.basePath + '/telescope-api/monitored-tags', { signal })
            .then((response) => {
                if (signal.aborted) return;

                this.tags = response.data.tags;

                this.ready = true;
            })
            .catch((error) => {
                if (!signal.aborted) throw error;
            });
    },

    /**
     * Clean up before the component is unmounted.
     */
    beforeUnmount() {
        this.requestController.abort();
        this.newTagModal.hide();
        this.newTagModal.dispose();
    },

    methods: {
        removeTag(tag) {
            this.alertConfirm('Are you sure you want to remove this tag?', () => {
                return axios
                    .post(Telescope.basePath + '/telescope-api/monitored-tags/delete', { tag })
                    .then(() => {
                        this.tags = _.reject(this.tags, (t) => t === tag);
                    })
                    .catch(() => {
                        this.alertError(
                            'Telescope could not confirm the monitoring change. Reload the page to check the current tags.',
                        );
                    });
            });
        },

        /**
         * Opens the modal for adding new monitored tag.
         */
        openNewTagModal() {
            this.newTagModal.show();
            this.$refs.newTagInput.focus();
        },

        /**
         * Monitor the given tag.
         */
        monitorNewTag() {
            const tag = this.newTag;

            if (!tag.length || this.tags.includes(tag)) {
                this.cancelNewTag();
                return;
            }

            return axios
                .post(Telescope.basePath + '/telescope-api/monitored-tags', { tag })
                .then(() => {
                    if (!this.tags.includes(tag)) this.tags.push(tag);

                    if (this.newTag === tag) {
                        this.newTagModal.hide();
                        this.newTag = '';
                    }
                })
                .catch(() => {
                    this.alertError(
                        'Telescope could not confirm the monitoring change. Reload the page to check the current tags.',
                    );
                });
        },

        /**
         * Cancel adding a new tag.
         */
        cancelNewTag() {
            this.newTagModal.hide();

            this.newTag = '';
        },
    },
};
</script>

<template>
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h2 class="h6 m-0">Monitoring</h2>

            <button class="btn btn-primary" :disabled="!ready" v-on:click.prevent="openNewTagModal">Monitor</button>
        </div>

        <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin me-2 fill-text-color">
                <path
                    d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"
                ></path>
            </svg>

            <span>Scanning...</span>
        </div>

        <div
            v-if="ready && tags.length == 0"
            class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius"
        >
            <span>No tags are currently being monitored.</span>
        </div>

        <table v-if="ready && tags.length > 0" class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Tag</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <tr v-for="tag in tags" :key="tag">
                    <td>{{ truncate(tag, 140) }}</td>

                    <td class="table-fit">
                        <a href="#" class="control-action" v-on:click.prevent="removeTag(tag)">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path
                                    d="M6 2l2-2h4l2 2h4v2H2V2h4zM3 6h14l-1 14H4L3 6zm5 2v10h1V8H8zm3 0v10h1V8h-1z"
                                ></path>
                            </svg>
                        </a>
                    </td>
                </tr>
            </tbody>
        </table>

        <div
            class="modal"
            id="addTagModel"
            ref="addTagModal"
            tabindex="-1"
            role="dialog"
            aria-labelledby="addTagModalLabel"
            aria-hidden="true"
        >
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header" id="addTagModalLabel">Monitor New Tag</div>

                    <div class="modal-body">
                        <input
                            type="text"
                            class="form-control"
                            placeholder="Project:6352"
                            v-on:keyup.enter="monitorNewTag"
                            v-model="newTag"
                            id="newTagInput"
                            ref="newTagInput"
                        />
                    </div>

                    <div class="modal-footer justify-content-start flex-row-reverse">
                        <button class="btn btn-primary" @click="monitorNewTag">Monitor</button>

                        <button class="btn" @click="cancelNewTag">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
