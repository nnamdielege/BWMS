import { defineStore } from 'pinia';
import productService from '../services/productService';

export const useProductStore = defineStore('product', {
    state: () => ({
        products: [],
        categories: [],
        error: null,
        loading: false,
        currentProduct: null,
    }),

    getters: {
        getProductById: (state) => (id) => {
            return state.products.find(p => p.id === id);
        },
    },

    actions: {
        async fetchProducts(params = {}) {
            this.loading = true;
            this.error = null;
            
            try {
                const response = await productService.getAll(params);
                this.products = response.data.data || response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch products';
                console.error('Fetch products error:', error);
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchProduct(id) {
            this.loading = true;
            this.error = null;
            
            try {
                const response = await productService.getOne(id);
                const product = response.data.data || response.data;
                this.currentProduct = product;
                return product;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch product';
                console.error('Fetch product error:', error);
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async fetchCategories() {
            try {
                const response = await productService.getCategories();
                this.categories = response.data.data || response.data;
                return this.categories;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to fetch categories';
                console.error('Fetch categories error:', error);
                throw error;
            }
        },

        async createProduct(productData) {
            this.loading = true;
            this.error = null;
            
            try {
                const response = await productService.create(productData);
                const product = response.data.data || response.data;
                this.products.push(product);
                return product;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to create product';
                console.error('Create product error:', error);
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async updateProduct(id, productData) {
            this.loading = true;
            this.error = null;
            
            try {
                const response = await productService.update(id, productData);
                const updatedProduct = response.data.data || response.data;
                
                const index = this.products.findIndex(p => p.id === id);
                if (index !== -1) {
                    this.products[index] = updatedProduct;
                }
                
                this.currentProduct = updatedProduct;
                return updatedProduct;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to update product';
                console.error('Update product error:', error);
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async deleteProduct(id) {
            this.loading = true;
            this.error = null;
            
            try {
                await productService.delete(id);
                this.products = this.products.filter(p => p.id !== id);
                return true;
            } catch (error) {
                this.error = error.response?.data?.message || 'Failed to delete product';
                console.error('Delete product error:', error);
                throw error;
            } finally {
                this.loading = false;
            }
        },

        async searchProducts(searchTerm, filters = {}) {
            this.loading = true;
            this.error = null;
            
            try {
                const response = await productService.getAll({
                    search: searchTerm,
                    ...filters
                });
                
                this.products = response.data.data || response.data;
                return response.data;
            } catch (error) {
                this.error = error.response?.data?.message || 'Search failed';
                console.error('Search error:', error);
                throw error;
            } finally {
                this.loading = false;
            }
        },

        clearError() {
            this.error = null;
        },

        reset() {
            this.products = [];
            this.categories = [];
            this.error = null;
            this.loading = false;
            this.currentProduct = null;
        }
    }
});