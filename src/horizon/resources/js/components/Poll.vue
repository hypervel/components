<script>
    export default {
        data() {
            return {
                loading: false,
                lastExecutionTime: 0,
                pollingInterval: null,
            };
        },

        props: {
            callback: {
                type: Function,
                required: true,
            },

            interval: {
                type: Number,
                default: 3,
            },

            keepAlive: {
                type: Boolean,
                default: false,
            },

            immediate: {
                type: Boolean,
                default: true,
            },
        },

        beforeMount() {
            this.updatePollingInterval();

            if (this.immediate) {
                this.emitPoll();
            }
        },

        mounted() {
            this.createListener();

            if (!this.keepAlive) {
                document.addEventListener('visibilitychange', (this.visibilitychangeListener = this.changedVisibility));
            }
        },

        beforeUnmount() {
            this.removeListener();

            if (this.visibilitychangeListener) {
                document.removeEventListener('visibilitychange', this.visibilitychangeListener);
            }
        },

        methods: {
            async emitPoll() {
                if (this.loading) {
                    return;
                }

                this.loading = true;
                this.lastExecutionTime = Date.now();

                try {
                    await this.callback();
                } catch (error) {
                    console.error(error);
                } finally {
                    this.loading = false;
                }
            },

            removeListener() {
                if (this.poll) {
                    clearInterval(this.poll);

                    this.poll = null;
                }
            },

            createListener() {
                this.poll = setInterval(() => {
                    this.emitPoll();
                }, this.pollingInterval);
            },

            updatePollingInterval() {
                if (this.keepAlive) {
                    this.pollingInterval = this.interval * 1000;
                    return;
                }

                if (document.visibilityState === 'visible') {
                    this.pollingInterval = 1000 * this.interval;
                } else if (document.visibilityState === 'hidden') {
                    // One hour...
                    this.pollingInterval = 1000 * 60 * 60;
                }
            },

            changedVisibility() {
                this.updatePollingInterval();
                this.removeListener();
                this.createListener();

                // throttling
                if (Date.now() - this.lastExecutionTime >= this.pollingInterval) {
                    this.emitPoll();
                }
            },
        },

        render() {
            return null;
        },
    };
</script>
