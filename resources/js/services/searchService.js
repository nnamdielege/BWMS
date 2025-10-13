import api from './api';

const searchService = {
    globalSearch(query, params = {}) {
        return api.get('/search', {
            params: {
                query,
                ...params
            }
        });
    },
};

export default searchService;