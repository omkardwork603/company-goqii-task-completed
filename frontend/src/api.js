const API_URL = "http://localhost/Comp/backend/api/tasks.php";

import axios from "axios";

const api = axios.create({
  baseURL: "http://localhost/Comp/backend/api",
  withCredentials: true,
  headers: {
    "Content-Type": "application/json",
  },
});

async function request(url = "", options = {}) {
  let response;

  try {
    response = await fetch(`${API_URL}${url}`, {
      ...options,
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...options.headers,
      },
    });
  } catch {
    throw new Error("Cannot connect to the PHP API. Check WAMP.");
  }

  let result;

  try {
    result = await response.json();
  } catch {
    throw new Error("The API returned an invalid JSON response.");
  }

  if (!response.ok) {
    throw new Error(result.message || `HTTP error ${response.status}`);
  }

  return result;
}

export const taskApi = {
  async list({ page = 1, perPage = 10, status = "" } = {}) {
    const params = new URLSearchParams({
      page: String(page),
      per_page: String(perPage),
    });

    if (status) {
      params.set("status", status);
    }

    const result = await request(`?${params.toString()}`);

    return {
      data: result.data || [],
      pagination: {
        page: Number(result.pagination?.page ?? page),
        perPage: Number(result.pagination?.per_page ?? perPage),
        total: Number(result.pagination?.total ?? 0),
        totalPages: Number(result.pagination?.total_pages ?? 0),
      },
    };
  },

  create(task) {
    return request("", {
      method: "POST",
      body: JSON.stringify(task),
    });
  },

  update(id, task) {
    return request(`?id=${encodeURIComponent(id)}`, {
      method: "PUT",
      body: JSON.stringify(task),
    });
  },

  patch(id, changes) {
    return request(`?id=${encodeURIComponent(id)}`, {
      method: "PATCH",
      body: JSON.stringify(changes),
    });
  },

  remove(id) {
    return request(`?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
    });
  },
};

export default api;
