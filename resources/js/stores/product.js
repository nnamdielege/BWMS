import { defineStore } from 'pinia';
import productService from '../services/productService';

export const useProductStore = defineStore('product', {
    state: () => ({
        products: [],
        currentProduct: null,
        categories: [],
        loading: false,
        error: null,
        pagination: {
            current_page: 1,
            per_page: 15,
            total: 0,
            last_page: 1,
        },
        filters: {
            search: '',
            category_id: null,
            is_active: null,
            sort_by: 'created_at',
            sort_order: 'desc',
        },
    }),

    getters: {
        /**
         * Get active products
         */
        activeProducts: (state) => state.products.filter(p => p.is_active),

        /**
         * Get products by category
         */
        productsByCategory: (state) => (categoryId) => {
            return state.products.filter(p => p.category_id === categoryId);
        },

        /**
         * Check if there are more pages
         */
        hasMore: (state) => state.pagination.current_page < state.pagination.last_page,

        /**
         * Get total products count
         */
        totalProducts: (state) => state.pagination.total,
    },
    actions: {
        /**
        * Fetch all products with filters
        */
        async fetchProducts(params = {}) {
        this.loading = true;
        this.error = null;

        try {
            const response = await productService.getAll({
                ...this.filters,
                ...params,
            });

            this.products = response.data.data;
            this.pagination = {
                current_page: response.data.current_page,
                per_page: response.data.per_page,
                total: response.data.total,
                last_page: response.data.last_page,
            };
        } catch (error) {
            this.error = error.response?.data?.message || 'Failed to fetch products';
            throw error;
        } finally {
            this.loading = false;
        }
    },

     /**
     * Fetch single product
     */
    async fetchProduct(id) {
        this.loading = true;
        this.error = null;

        try {
            console.log('Fetching product:', id); // Debug
            
            const response = await productService.getOne(id);
            
            console.log('Product response:', response.data); // Debug
            
            this.currentProduct = response.data.data || response.data;
            return this.currentProduct;
        } catch (error) {
            console.error('Fetch product error:', error); // Debug
            this.error = error.response?.data?.message || 'Failed to fetch product';
            throw error;
        } finally {
            this.loading = false;
        }
    },

    /**
     * Create new product
     */
    async createProduct(data) {
        this.loading = true;
        this.error = null;

        try {
            console.log('Creating product with data:', data); // Debug log
            
            const response = await productService.create(data);
            
            console.log('Product created:', response.data); // Debug log
            
            this.products.unshift(response.data.data || response.data);
            return response.data.data || response.data;
        } catch (error) {
            console.error('Create product error:', error); // Debug log
            console.error('Error response:', error.response); // Debug log
            
            this.error = error.response?.data?.message || 'Failed to create product';
            
            // If validation errors, store them
            if (error.response?.data?.errors) {
                console.error('Validation errors:', error.response.data.errors);
            }
            
            throw error;
        } finally {
            this.loading = false;
        }
    },

    /**
     * Update existing product
     */
    async updateProduct(id, data) {
        this.loading = true;
        this.error = null;

        try {
            const response = await productService.update(id, data);
            const index = this.products.findIndex(p => p.id === id);
            if (index !== -1) {
                this.products[index] = response.data;
            }
            if (this.currentProduct?.id === id) {
                this.currentProduct = response.data;
            }
            return response.data;
        } catch (error) {
            this.error = error.response?.data?.message || 'Failed to update product';
            throw error;
        } finally {
            this.loading = false;
        }
    },

    /**
     * Delete product
     */
    async deleteProduct(id) {
        this.loading = true;
        this.error = null;

        try {
            await productService.delete(id);
            this.products = this.products.filter(p => p.id !== id);
            if (this.currentProduct?.id === id) {
                this.currentProduct = null;
            }
        } catch (error) {
            this.error = error.response?.data?.message || 'Failed to delete product';
            throw error;
        } finally {
            this.loading = false;
        }
    },

    /**
     * Fetch product categories
     */
    async fetchCategories() {
        try {
            const response = await productService.getCategories();
            this.categories = response.data;
        } catch (error) {
            console.error('Failed to fetch categories:', error);
        }
    },

    /**
     * Search products
     */
    async searchProducts(query) {
        this.filters.search = query;
        await this.fetchProducts();
    },

    /**
     * Set filters
     */
    setFilters(filters) {
        this.filters = { ...this.filters, ...filters };
    },

    /**
     * Reset filters
     */
    resetFilters() {
        this.filters = {
            search: '',
            category_id: null,
            is_active: null,
            sort_by: 'created_at',
            sort_order: 'desc',
        };
    },

    /**
     * Clear error
     */
    clearError() {
        this.error = null;
    },

    /**
     * Clear current product
     */
    clearCurrentProduct() {
        this.currentProduct = null;
    },
},});