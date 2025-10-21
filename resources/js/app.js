import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { loadStripe } from '@stripe/stripe-js';
import App from './App.vue';
import router from './router';

const app = createApp(App);
const pinia = createPinia();

// Make loadStripe available globally
window.loadStripe = loadStripe;

app.use(pinia);
app.use(router);

app.mount('#app');