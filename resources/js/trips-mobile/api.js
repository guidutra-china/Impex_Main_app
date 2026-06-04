const TOKEN_KEY = 'trips-mobile.token';
const USER_KEY = 'trips-mobile.user';

const baseUrl = '/api/trips/v1';

export const auth = {
    token: () => localStorage.getItem(TOKEN_KEY),
    user: () => {
        const raw = localStorage.getItem(USER_KEY);
        return raw ? JSON.parse(raw) : null;
    },
    persist(token, user) {
        localStorage.setItem(TOKEN_KEY, token);
        localStorage.setItem(USER_KEY, JSON.stringify(user));
    },
    clear() {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
    },
};

async function request(path, { method = 'GET', body = null, isFormData = false } = {}) {
    const headers = {
        Accept: 'application/json',
    };
    if (!isFormData && body) {
        headers['Content-Type'] = 'application/json';
    }
    const token = auth.token();
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${baseUrl}${path}`, {
        method,
        headers,
        body: isFormData ? body : body ? JSON.stringify(body) : null,
    });

    if (response.status === 401) {
        auth.clear();
        throw new ApiError(401, 'Unauthorized', null);
    }

    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
        throw new ApiError(response.status, data?.message || response.statusText, data);
    }

    return data;
}

export class ApiError extends Error {
    constructor(status, message, body) {
        super(message);
        this.status = status;
        this.body = body;
    }
}

export const api = {
    async login(email, password, deviceName) {
        const data = await request('/auth/login', {
            method: 'POST',
            body: { email, password, device_name: deviceName },
        });
        auth.persist(data.token, data.user);
        return data;
    },

    async logout() {
        try {
            await request('/auth/logout', { method: 'POST' });
        } finally {
            auth.clear();
        }
    },

    referenceData: () => request('/reference-data'),

    searchCompanies: (q) => request('/companies/search', {
        method: 'POST',
        body: { q },
    }),

    listTrips: () => request('/trips'),

    submitTrip: (formData) => request('/trips', {
        method: 'POST',
        body: formData,
        isFormData: true,
    }),
};
