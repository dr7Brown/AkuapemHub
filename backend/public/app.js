const apiBase = '/api';

const handleError = async (response) => {
  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.error || 'Server error');
  }
  return response.json();
};

const getToken = () => localStorage.getItem('akuapemhub_token');
const setToken = (token) => localStorage.setItem('akuapemhub_token', token);
const clearToken = () => localStorage.removeItem('akuapemhub_token');

const authHeader = () => {
  const token = getToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
};

const redirectToDashboard = () => {
  window.location.href = 'dashboard.html';
};

const initLogin = () => {
  const form = document.getElementById('login-form');
  if (!form) return;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const message = document.getElementById('form-message');
    message.textContent = '';
    try {
      const response = await fetch(`${apiBase}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
      });
      const data = await handleError(response);
      setToken(data.token);
      redirectToDashboard();
    } catch (error) {
      message.textContent = error.message;
    }
  });
};

const initRegister = () => {
  const form = document.getElementById('register-form');
  if (!form) return;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = document.getElementById('name').value.trim();
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const message = document.getElementById('form-message');
    message.textContent = '';
    try {
      const response = await fetch(`${apiBase}/auth/register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, email, password })
      });
      const data = await handleError(response);
      setToken(data.token);
      redirectToDashboard();
    } catch (error) {
      message.textContent = error.message;
    }
  });
};

const renderListings = (listings) => {
  const container = document.getElementById('listings');
  if (!container) return;
  if (!listings.length) {
    container.innerHTML = '<div class="listing-item"><p>No listings found yet. Post one now!</p></div>';
    return;
  }

  container.innerHTML = listings.map((item) => {
    return `
      <div class="listing-item">
        <h3>${item.title}</h3>
        <div class="listing-meta">
          <span>${item.category}</span>
          <span>${item.location}</span>
          <span>${item.price || 'Free'}</span>
          <span>Posted by ${item.owner_name}</span>
        </div>
        <p>${item.description}</p>
      </div>
    `;
  }).join('');
};

const loadCategories = async () => {
  const selectIds = ['search-category', 'listing-category'];
  try {
    const response = await fetch(`${apiBase}/categories`);
    const data = await handleError(response);
    selectIds.forEach((id) => {
      const select = document.getElementById(id);
      if (!select) return;
      select.innerHTML = `<option value="">All categories</option>` + data.categories.map((cat) => `<option value="${cat.id}">${cat.name}</option>`).join('');
    });
  } catch (error) {
    console.error('Failed to load categories', error);
  }
};

const loadListings = async () => {
  const query = document.getElementById('search-query')?.value.trim();
  const category = document.getElementById('search-category')?.value;
  const params = new URLSearchParams();
  if (query) params.append('query', query);
  if (category) params.append('category', category);
  try {
    const response = await fetch(`${apiBase}/listings?${params.toString()}`);
    const data = await handleError(response);
    renderListings(data.listings || []);
  } catch (error) {
    console.error('Failed to load listings', error);
  }
};

const initDashboard = () => {
  if (window.location.pathname.endsWith('dashboard.html')) {
    if (!getToken()) {
      window.location.href = 'login.html';
      return;
    }
    loadCategories();
    loadListings();

    document.getElementById('logout-button')?.addEventListener('click', () => {
      clearToken();
      window.location.href = 'login.html';
    });

    document.getElementById('refresh-button')?.addEventListener('click', loadListings);

    document.getElementById('search-form')?.addEventListener('submit', (event) => {
      event.preventDefault();
      loadListings();
    });

    document.getElementById('create-form')?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const title = document.getElementById('listing-title').value.trim();
      const description = document.getElementById('listing-description').value.trim();
      const category_id = document.getElementById('listing-category').value;
      const location = document.getElementById('listing-location').value.trim();
      const price = document.getElementById('listing-price').value.trim();
      const message = document.getElementById('form-message');
      message.textContent = '';
      try {
        const response = await fetch(`${apiBase}/listings`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            ...authHeader()
          },
          body: JSON.stringify({ title, description, category_id, location, price })
        });
        await handleError(response);
        message.textContent = 'Listing posted successfully.';
        document.getElementById('create-form').reset();
        loadListings();
      } catch (error) {
        message.textContent = error.message;
      }
    });
  }
};

const initPage = () => {
  initLogin();
  initRegister();
  initDashboard();
};

window.addEventListener('DOMContentLoaded', initPage);
