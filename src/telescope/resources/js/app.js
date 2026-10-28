import { createApp } from 'vue/dist/vue.esm-bundler.js';
import Base from './base';
import axios from 'axios';
import Routes from './routes';
import { createRouter, createWebHistory } from 'vue-router';
import VueJsonPretty from 'vue-json-pretty';
import 'vue-json-pretty/lib/styles.css';
import moment from 'moment-timezone';
import RelatedEntries from './components/RelatedEntries.vue';
import IndexScreen from './components/IndexScreen.vue';
import PreviewScreen from './components/PreviewScreen.vue';
import Alert from './components/Alert.vue';
import CopyClipboard from './components/CopyClipboard.vue';

const LOCALSTORAGE_AUTOLOAD_KEY = 'telescopeAutoLoadsNewEntries';

let token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

moment.tz.setDefault(Telescope.timezone);

const app = createApp({
    data() {
        return {
            alert: {
                type: null,
                autoClose: 0,
                message: '',
                confirmationProceed: null,
                confirmationCancel: null,
            },

            autoLoadsNewEntries: localStorage[LOCALSTORAGE_AUTOLOAD_KEY] === '1',

            recording: Telescope.recording,
        };
    },

    created() {
        window.addEventListener('keydown', this.keydownListener);
    },

    beforeUnmount() {
        window.removeEventListener('keydown', this.keydownListener);
    },

    methods: {
        autoLoadNewEntries() {
            this.autoLoadsNewEntries = !this.autoLoadsNewEntries;
            localStorage[LOCALSTORAGE_AUTOLOAD_KEY] = Number(this.autoLoadsNewEntries);
        },

        toggleRecording() {
            axios.post(Telescope.basePath + '/telescope-api/toggle-recording');

            window.Telescope.recording = !Telescope.recording;
            this.recording = !this.recording;
        },

        clearEntries(shouldConfirm = true) {
            if (shouldConfirm && !confirm('Are you sure you want to delete all Telescope data?')) {
                return;
            }

            axios.delete(Telescope.basePath + '/telescope-api/entries').then((response) => location.reload());
        },

        keydownListener(event) {
            if (event.metaKey && event.key === 'k') {
                this.clearEntries(false);
            }
        },
    },
});

window.Telescope.basePath = '/' + window.Telescope.path;

let routerBasePath = window.Telescope.basePath + '/';

if (window.Telescope.path === '' || window.Telescope.path === '/') {
    routerBasePath = '/';
    window.Telescope.basePath = '';
}

const router = createRouter({
    history: createWebHistory(routerBasePath),
    routes: Routes,
});

app.use(router);

app.component('vue-json-pretty', VueJsonPretty);
app.component('related-entries', RelatedEntries);
app.component('index-screen', IndexScreen);
app.component('preview-screen', PreviewScreen);
app.component('alert', Alert);
app.component('copy-clipboard', CopyClipboard);

app.mixin(Base);

app.mount('#telescope');
