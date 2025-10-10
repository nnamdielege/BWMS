import api from './api';

export default {
    /**
     * Login user
     * @param {Object} credentials - { email, password }
     * @returns {Promise}
     */
    login(credentials) {
        return api.post('/login', credentials);
    },

    /**
     * Register new user
     * @param {Object} userData - { name, email, password, password_confirmation }
     * @returns {Promise}
     */
    register(userData) {
        return api.post('/register', userData);
    },

    /**
     * Logout current user
     * @returns {Promise}
     */
    logout() {
        return api.post('/logout');
    },

    /**
     * Get current authenticated user
     * @returns {Promise}
     */
    getUser() {
        return api.get('/user');
    },

    /**
     * Update user profile
     * @param {Object} data - User profile data
     * @returns {Promise}
     */
    updateProfile(data) {
        return api.put('/user/profile', data);
    },

    /**
     * Change user password
     * @param {Object} data - { current_password, password, password_confirmation }
     * @returns {Promise}
     */
    changePassword(data) {
        return api.put('/user/password', data);
    },

    /**
     * Request password reset
     * @param {Object} data - { email }
     * @returns {Promise}
     */
    forgotPassword(data) {
        return api.post('/forgot-password', data);
    },

    /**
     * Reset password with token
     * @param {Object} data - { email, password, password_confirmation, token }
     * @returns {Promise}
     */
    resetPassword(data) {
        return api.post('/reset-password', data);
    },
};