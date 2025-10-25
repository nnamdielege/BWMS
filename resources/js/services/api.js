import axios from 'axios';

// Combine base URL and API version cleanly
const baseURL = `${import.meta.env.VITE_API_BASE_URL}${import.meta.env.VITE_API_VERSION}`;

const api = axios.create({
    // baseURL: 'http://127.0.0.1:8000/api/v1',  // NOT /api/v1
    // baseURL: 'https://334e9da0e488.ngrok-free.app/api/v1',  // NOT /api/v1
    // baseURL,
    baseURL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true,
});

// Request interceptor
api.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('token');
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Response interceptor
api.interceptors.response.use(
    (response) => {
        return response;
    },
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('token');
            window.location.href = '/login';
        }
        return Promise.reject(error);
    }
);

export default api;