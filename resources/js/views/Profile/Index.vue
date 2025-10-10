<template>
    <div class="profile-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Manage your account settings and preferences</p>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="initialLoading" class="loading-container">
            <div class="spinner"></div>
            <p>Loading profile...</p>
        </div>

        <!-- Profile Content -->
        <div v-else class="profile-content">
            <!-- Profile Information Card -->
            <div class="profile-card">
                <h3 class="card-title">Profile Information</h3>
                
                <!-- Avatar Section -->
                <div class="avatar-section">
                    <div class="avatar-container">
                        <div v-if="avatarUrl" class="avatar-preview">
                            <img :src="avatarUrl" alt="Avatar" class="avatar-image" />
                        </div>
                        <div v-else class="avatar-placeholder">
                            <svg class="w-20 h-20 text-gray-300" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                            </svg>
                        </div>
                        <div class="avatar-actions">
                            <label class="btn btn-secondary btn-sm cursor-pointer">
                                <input
                                    type="file"
                                    accept="image/*"
                                    @change="handleAvatarUpload"
                                    class="hidden"
                                />
                                Upload Photo
                            </label>
                            <button
                                v-if="user?.avatar"
                                @click="handleDeleteAvatar"
                                class="btn btn-danger btn-sm"
                                :disabled="loading"
                            >
                                Remove
                            </button>
                        </div>
                        <p class="avatar-help">JPG, PNG or GIF (max. 2MB)</p>
                    </div>
                </div>

                <!-- Profile Form -->
                <form @submit.prevent="handleUpdateProfile" class="profile-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input
                                v-model="profileForm.name"
                                type="text"
                                class="form-input"
                                placeholder="John Doe"
                                required
                            />
                            <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input
                                v-model="profileForm.email"
                                type="email"
                                class="form-input"
                                placeholder="john@example.com"
                                required
                            />
                            <p v-if="errors.email" class="form-error">{{ errors.email[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input
                                v-model="profileForm.phone"
                                type="text"
                                class="form-input"
                                placeholder="+1-555-0100"
                            />
                            <p v-if="errors.phone" class="form-error">{{ errors.phone[0] }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <input
                                :value="user?.role || 'User'"
                                type="text"
                                class="form-input"
                                disabled
                            />
                        </div>
                    </div>

                    <div class="form-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="loading"
                        >
                            {{ loading ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password Card -->
            <div class="profile-card">
                <h3 class="card-title">Change Password</h3>
                <p class="text-sm text-gray-600 mb-6">
                    Update your password to keep your account secure
                </p>

                <form @submit.prevent="handleUpdatePassword" class="profile-form">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="form-label">Current Password *</label>
                            <div class="password-input">
                                <input
                                    v-model="passwordForm.current_password"
                                    :type="showCurrentPassword ? 'text' : 'password'"
                                    class="form-input"
                                    placeholder="Enter current password"
                                    required
                                />
                                <button
                                    type="button"
                                    @click="showCurrentPassword = !showCurrentPassword"
                                    class="password-toggle"
                                >
                                    <svg v-if="showCurrentPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                            <p v-if="passwordErrors.current_password" class="form-error">
                                {{ passwordErrors.current_password[0] }}
                            </p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">New Password *</label>
                            <div class="password-input">
                                <input
                                    v-model="passwordForm.new_password"
                                    :type="showNewPassword ? 'text' : 'password'"
                                    class="form-input"
                                    placeholder="Enter new password"
                                    required
                                />
                                <button
                                    type="button"
                                    @click="showNewPassword = !showNewPassword"
                                    class="password-toggle"
                                >
                                    <svg v-if="showNewPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                            <p class="form-help">Minimum 8 characters</p>
                            <p v-if="passwordErrors.new_password" class="form-error">
                                {{ passwordErrors.new_password[0] }}
                            </p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirm New Password *</label>
                            <div class="password-input">
                                <input
                                    v-model="passwordForm.new_password_confirmation"
                                    :type="showConfirmPassword ? 'text' : 'password'"
                                    class="form-input"
                                    placeholder="Confirm new password"
                                    required
                                />
                                <button
                                    type="button"
                                    @click="showConfirmPassword = !showConfirmPassword"
                                    class="password-toggle"
                                >
                                    <svg v-if="showConfirmPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                    </svg>
                                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="loading"
                        >
                            {{ loading ? 'Updating...' : 'Update Password' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Account Information Card -->
            <div class="profile-card">
                <h3 class="card-title">Account Information</h3>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Account Status:</span>
                        <span :class="['status-badge', user?.is_active ? 'badge-active' : 'badge-inactive']">
                            {{ user?.is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Member Since:</span>
                        <span class="info-value">{{ formatDate(user?.created_at) }}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Updated:</span>
                        <span class="info-value">{{ formatDate(user?.updated_at) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Toast -->
        <div v-if="showSuccess" class="success-toast">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <span>{{ successMessage }}</span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useProfileStore } from '../../stores/profile';

const profileStore = useProfileStore();

const initialLoading = ref(true);
const loading = ref(false);
const showSuccess = ref(false);
const successMessage = ref('');
const errors = ref({});
const passwordErrors = ref({});

const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);

const user = computed(() => profileStore.user);
const avatarUrl = computed(() => profileStore.avatarUrl);

const profileForm = reactive({
    name: '',
    email: '',
    phone: '',
});

const passwordForm = reactive({
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
});

onMounted(async () => {
    await loadProfile();
});

const loadProfile = async () => {
    initialLoading.value = true;

    try {
        await profileStore.fetchProfile();
        
        // Populate form
        profileForm.name = user.value.name;
        profileForm.email = user.value.email;
        profileForm.phone = user.value.phone || '';

    } catch (error) {
        console.error('Error loading profile:', error);
        alert('Failed to load profile');
    } finally {
        initialLoading.value = false;
    }
};

const handleUpdateProfile = async () => {
    errors.value = {};
    loading.value = true;

    try {
        await profileStore.updateProfile(profileForm);
        
        showSuccessMessage('Profile updated successfully!');
    } catch (error) {
        console.error('Update profile error:', error);
        
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        } else {
            alert(error.response?.data?.message || 'Failed to update profile');
        }
    } finally {
        loading.value = false;
    }
};

const handleUpdatePassword = async () => {
    passwordErrors.value = {};
    loading.value = true;

    try {
        await profileStore.updatePassword(passwordForm);
        
        // Clear password form
        passwordForm.current_password = '';
        passwordForm.new_password = '';
        passwordForm.new_password_confirmation = '';
        
        showSuccessMessage('Password updated successfully!');
    } catch (error) {
        console.error('Update password error:', error);
        
        if (error.response?.data?.errors) {
            passwordErrors.value = error.response.data.errors;
        } else {
            alert(error.response?.data?.message || 'Failed to update password');
        }
    } finally {
        loading.value = false;
    }
};

const handleAvatarUpload = async (event) => {
    const file = event.target.files[0];
    
    if (!file) return;

    // Validate file size (2MB)
    if (file.size > 2 * 1024 * 1024) {
        alert('File size must be less than 2MB');
        return;
    }

    // Validate file type
    if (!['image/jpeg', 'image/png', 'image/jpg', 'image/gif'].includes(file.type)) {
        alert('File must be an image (JPG, PNG or GIF)');
        return;
    }

    loading.value = true;

    try {
        await profileStore.uploadAvatar(file);
        showSuccessMessage('Avatar uploaded successfully!');
    } catch (error) {
        console.error('Avatar upload error:', error);
        alert(error.response?.data?.message || 'Failed to upload avatar');
    } finally {
        loading.value = false;
        event.target.value = ''; // Reset input
    }
};

const handleDeleteAvatar = async () => {
    if (!confirm('Are you sure you want to remove your avatar?')) {
        return;
    }

    loading.value = true;

    try {
        await profileStore.deleteAvatar();
        showSuccessMessage('Avatar removed successfully!');
    } catch (error) {
        console.error('Delete avatar error:', error);
        alert(error.response?.data?.message || 'Failed to delete avatar');
    } finally {
        loading.value = false;
    }
};

const showSuccessMessage = (message) => {
    successMessage.value = message;
    showSuccess.value = true;
    setTimeout(() => {
        showSuccess.value = false;
    }, 3000);
};

const formatDate = (date) => {
if (!date) return 'N/A';
return new Date(date).toLocaleDateString('en-US', {
year: 'numeric',
month: 'long',
day: 'numeric'
});
};
</script>


<style scoped>
.profile-page {
    @apply max-w-4xl mx-auto space-y-6;
}

.page-header {
    @apply mb-6;
}

.page-title {
    @apply text-3xl font-bold text-gray-900;
}

.page-subtitle {
    @apply text-gray-600 mt-1;
}

.loading-container {
    @apply flex flex-col items-center justify-center py-20 bg-white rounded-lg shadow;
}

.spinner {
    @apply w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin mb-4;
}

.profile-content {
    @apply space-y-6;
}

.profile-card {
    @apply bg-white rounded-lg shadow p-6;
}

.card-title {
    @apply text-lg font-semibold text-gray-900 mb-6 pb-4 border-b border-gray-200;
}

.avatar-section {
    @apply mb-8;
}

.avatar-container {
    @apply flex flex-col items-center;
}

.avatar-preview {
    @apply mb-4;
}

.avatar-image {
    @apply w-32 h-32 rounded-full object-cover border-4 border-gray-200;
}

.avatar-placeholder {
    @apply w-32 h-32 bg-gray-100 rounded-full flex items-center justify-center mb-4;
}

.avatar-actions {
    @apply flex gap-3 mb-2;
}

.avatar-help {
    @apply text-sm text-gray-500 text-center;
}

.profile-form {
    @apply space-y-6;
}

.form-grid {
    @apply grid grid-cols-1 gap-6;
}

@media (min-width: 768px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .form-group.full-width {
        grid-column: span 2;
    }
}

.form-group {
    @apply flex flex-col;
}

.form-label {
    @apply text-sm font-medium text-gray-700 mb-2;
}

.form-input {
    @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent;
}

.form-input:disabled {
    @apply bg-gray-100 cursor-not-allowed;
}

.password-input {
    @apply relative;
}

.password-toggle {
    @apply absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors;
}

.form-help {
    @apply text-sm text-gray-500 mt-1;
}

.form-error {
    @apply text-sm text-red-600 mt-1;
}

.form-actions {
    @apply flex justify-end pt-6 border-t border-gray-200;
}

.btn {
    @apply flex items-center gap-2 px-6 py-2.5 rounded-lg font-medium transition-colors;
}

.btn-primary {
    @apply bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-secondary {
    @apply bg-white border border-gray-300 text-gray-700 hover:bg-gray-50;
}

.btn-danger {
    @apply bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed;
}

.btn-sm {
    @apply px-4 py-2 text-sm;
}

.hidden {
    @apply sr-only;
}

.info-grid {
    @apply grid grid-cols-1 gap-4;
}

@media (min-width: 768px) {
    .info-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

.info-item {
    @apply flex flex-col;
}

.info-label {
    @apply text-sm text-gray-600 mb-1;
}

.info-value {
    @apply text-sm font-medium text-gray-900;
}

.status-badge {
    @apply inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium w-fit;
}

.badge-active {
    @apply bg-green-100 text-green-800;
}

.badge-inactive {
    @apply bg-red-100 text-red-800;
}

.success-toast {
    @apply fixed bottom-6 right-6 bg-green-50 border border-green-200 text-green-800 px-6 py-4 rounded-lg shadow-lg flex items-center gap-3 animate-slide-up z-50;
}

@keyframes slide-up {
    from {
        transform: translateY(100px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.animate-slide-up {
    animation: slide-up 0.3s ease-out;
}
</style>