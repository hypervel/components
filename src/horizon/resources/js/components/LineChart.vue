<script>
    import { CategoryScale, Chart, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';
    import { markRaw } from 'vue';

    Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip);

    export default {
        props: ['data'],

        data() {
            return {
                chart: null,
            };
        },

        mounted() {
            // Chart cancels animations by instance identity, which a reactive proxy would change.
            this.chart = markRaw(
                new Chart(this.$refs.canvas, {
                    type: 'line',
                    options: {
                        plugins: {
                            tooltip: { intersect: false },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                // Chart.js applies percentage grace to half the range, giving 25% headroom above zero-based metrics.
                                grace: '50%',
                                ticks: {
                                    callback: (value) =>
                                        this.data.datasets[0].label === 'Seconds' ? `${value} secs` : value,
                                },
                            },
                            x: {
                                ticks: {
                                    callback(value) {
                                        return value % 6 === 0 || value === this.getLabels().length - 1
                                            ? this.getLabelForValue(value)
                                            : '';
                                    },
                                },
                            },
                        },
                    },
                    data: this.data,
                }),
            );
        },

        beforeUnmount() {
            this.chart.destroy();
        },
    };
</script>

<template>
    <div style="position: relative">
        <canvas ref="canvas" height="120"></canvas>
    </div>
</template>
