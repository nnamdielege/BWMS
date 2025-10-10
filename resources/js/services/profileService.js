import api from './api';

const profileService = {
    getProfile() {
        return api.get('/profile');
    },

    updateProfile(data) {
        return api.put('/profile', data);
    },

    updatePassword(data) {
        return api.put('/profile/password', data);
    },

    uploadAvatar(formData) {
        return api.post('/profile/avatar', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
    },

    deleteAvatar() {
        return api.delete('/profile/avatar');
    },
};

export default profileService;