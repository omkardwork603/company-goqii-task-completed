import { useCallback, useEffect, useState } from "react";
import api from "./api/tasksApi";
import authApi from "./api/authApi";
import Login from "./pages/Login";
import Dashboard from "./components/Dashboard";

import "./App.css";

const PAGE_SIZE_OPTIONS = [5, 10, 20];
const ALLOWED_STATUSES = ["todo", "in-progress", "done"];
const ALLOWED_PRIORITIES = ["low", "medium", "high"];

function validateTask({ title, description, status, priority, dueDate }) {
  const errors = {};
  const trimmedTitle = title.trim();

  if (!trimmedTitle) {
    errors.title = "Task title is required.";
  } else if (trimmedTitle.length > 150) {
    errors.title = "Title cannot exceed 150 characters.";
  }

  if (description.length > 10000) {
    errors.description = "Description cannot exceed 10,000 characters.";
  }

  if (!ALLOWED_STATUSES.includes(status)) {
    errors.status = "Please select a valid status.";
  }

  if (!ALLOWED_PRIORITIES.includes(priority)) {
    errors.priority = "Please select a valid priority.";
  }

  if (dueDate) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dueDate);

    if (!match) {
      errors.dueDate = "Use the YYYY-MM-DD date format.";
    } else {
      const [, yearText, monthText, dayText] = match;
      const year = Number(yearText);
      const month = Number(monthText);
      const day = Number(dayText);
      const date = new Date(year, month - 1, day);

      if (
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
      ) {
        errors.dueDate = "Please enter a valid calendar date.";
      }
    }
  }

  return errors;
}

function TaskManager() {
  const [tasks, setTasks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  // Task form fields
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [status, setStatus] = useState("todo");
  const [priority, setPriority] = useState("medium");
  const [dueDate, setDueDate] = useState("");
  const [validationErrors, setValidationErrors] = useState({});

  // Task actions
  const [saving, setSaving] = useState(false);
  const [editingTask, setEditingTask] = useState(null);
  const [changingStatusId, setChangingStatusId] = useState(null);
  const [deletingTaskId, setDeletingTaskId] = useState(null);
  const [showForm, setShowForm] = useState(false);

  // Search, filters and pagination
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(5);

  const [pagination, setPagination] = useState({
    page: 1,
    perPage: 5,
    total: 0,
    totalPages: 0,
  });

  const fetchTasks = useCallback(
    async (showLoader = true) => {
      try {
        if (showLoader) {
          setLoading(true);
        }

        setError("");

        const response = await api.get("", {
          params: {
            search: search.trim() || undefined,
            status: statusFilter || undefined,
            page,
            per_page: perPage,
          },
        });

        const result = response.data;

        const taskList = Array.isArray(result)
          ? result
          : Array.isArray(result?.data)
            ? result.data
            : null;

        if (!taskList) {
          throw new Error("Invalid task data received from API.");
        }

        const paginationData = result.pagination || {};
        const total = Number(paginationData.total ?? taskList.length);

        const totalPages = Number(
          paginationData.total_pages ??
            paginationData.totalPages ??
            Math.ceil(total / perPage),
        );

        const lastValidPage = Math.max(totalPages, 1);

        if (page > lastValidPage) {
          setPage(lastValidPage);
          return;
        }

        setTasks(taskList);

        setPagination({
          page: Number(paginationData.page ?? page),
          perPage: Number(
            paginationData.per_page ?? paginationData.perPage ?? perPage,
          ),
          total,
          totalPages,
        });
      } catch (err) {
        console.error("Fetch tasks error:", err);
        setError(
          err.response?.data?.message ||
            err.message ||
            "Failed to load tasks. Please check your PHP API.",
        );
      } finally {
        if (showLoader) {
          setLoading(false);
        }
      }
    },
    [search, statusFilter, page, perPage],
  );

  useEffect(() => {
    fetchTasks();
  }, [fetchTasks]);

  function resetForm(closeForm = true) {
    setTitle("");
    setDescription("");
    setStatus("todo");
    setPriority("medium");
    setDueDate("");
    setEditingTask(null);
    setValidationErrors({});

    if (closeForm) {
      setShowForm(false);
    }
  }

  function updateField(setter, field, value) {
    setter(value);

    setValidationErrors((current) => {
      const updated = { ...current };
      delete updated[field];
      return updated;
    });
  }

  function renderFieldError(field) {
    if (!validationErrors[field]) {
      return null;
    }

    return (
      <p id={`${field}-error`} role="alert" className="field-error">
        {validationErrors[field]}
      </p>
    );
  }

  function handleAddTask() {
    resetForm(false);
    setShowForm(true);
    setError("");
    setSuccess("");

    window.setTimeout(() => {
      document.getElementById("task-form")?.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    }, 0);
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setError("");
    setSuccess("");

    const errors = validateTask({
      title,
      description,
      status,
      priority,
      dueDate,
    });

    setValidationErrors(errors);

    if (Object.keys(errors).length > 0) {
      return;
    }

    try {
      setSaving(true);
      const isEditing = Boolean(editingTask);

      const taskData = {
        title: title.trim(),
        description: description.trim(),
        status,
        priority,
        due_date: dueDate || null,
      };

      if (isEditing) {
        await api.put(`?id=${editingTask.id}`, taskData);
      } else {
        await api.post("", taskData);
      }

      resetForm(true);
      setSuccess(
        isEditing ? "Task updated successfully!" : "Task created successfully!",
      );

      if (page !== 1) {
        setPage(1);
      } else {
        await fetchTasks(false);
      }
    } catch (err) {
      console.error("Save task error:", err);
      setError(err.response?.data?.message || "Failed to save task.");
    } finally {
      setSaving(false);
    }
  }

  function handleEdit(task) {
    setEditingTask(task);
    setTitle(task.title || "");
    setDescription(task.description || "");
    setStatus(task.status || "todo");
    setPriority(task.priority || "medium");

    setDueDate(
      task.due_date && task.due_date !== "0000-00-00"
        ? task.due_date.slice(0, 10)
        : "",
    );

    setShowForm(true);
    setError("");
    setSuccess("");
    setValidationErrors({});

    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleStatusChange(task, newStatus) {
    if (!ALLOWED_STATUSES.includes(newStatus)) {
      setError("Please select a valid status.");
      return;
    }

    try {
      setChangingStatusId(task.id);
      setError("");
      setSuccess("");

      await api.patch(`?id=${task.id}`, {
        status: newStatus,
      });

      setSuccess("Task status updated successfully!");
      await fetchTasks(false);
    } catch (err) {
      console.error("Update status error:", err);
      setError(err.response?.data?.message || "Failed to update task status.");
    } finally {
      setChangingStatusId(null);
    }
  }

  async function handleDelete(task) {
    const confirmed = window.confirm(
      `Are you sure you want to delete "${task.title}"?`,
    );

    if (!confirmed) {
      return;
    }

    try {
      setDeletingTaskId(task.id);
      setError("");
      setSuccess("");

      await api.delete(`?id=${task.id}`);

      if (editingTask?.id === task.id) {
        resetForm(true);
      }

      setSuccess("Task deleted successfully!");
      await fetchTasks(false);
    } catch (err) {
      console.error("Delete task error:", err);
      setError(
        err.response?.data?.message ||
          "Failed to delete task. Please try again.",
      );
    } finally {
      setDeletingTaskId(null);
    }
  }

  function handleFilterChange(value) {
    setStatusFilter(value);
    setPage(1);
    setError("");
  }

  function handleSearchChange(value) {
    setSearch(value);
    setPage(1);
    setError("");
  }

  function clearSearch() {
    setSearch("");
    setPage(1);
    setError("");
  }

  function handlePageSizeChange(value) {
    setPerPage(Number(value));
    setPage(1);
  }

  const hasActiveFilters = Boolean(search.trim() || statusFilter);

  return (
    <main className="app-container">
      {/* Hero Title & Primary Action */}
      <div className="page-hero">
        <div className="page-hero-title">
          <h1>Task Overview</h1>
          <p>Organize, prioritize, and track your daily work efficiency</p>
        </div>
        <button
          type="button"
          className="btn-add-primary"
          onClick={handleAddTask}
        >
          <svg
            width="18"
            height="18"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <line x1="12" y1="5" x2="12" y2="19" />
            <line x1="5" y1="12" x2="19" y2="12" />
          </svg>
          Create Task
        </button>
      </div>

      {/* Dashboard KPI stats */}
      <Dashboard tasks={tasks} onAddTask={handleAddTask} />

      {/* Error & Success Feedback */}
      {error && (
        <div role="alert" className="alert-card alert-error">
          <div style={{ display: "flex", alignItems: "center", gap: "0.5rem" }}>
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <span>{error}</span>
          </div>
          <button
            type="button"
            className="alert-retry-btn"
            onClick={() => fetchTasks(true)}
            disabled={loading}
          >
            {loading ? "Retrying..." : "Retry"}
          </button>
        </div>
      )}

      {success && (
        <div role="status" className="alert-card alert-success">
          <div style={{ display: "flex", alignItems: "center", gap: "0.5rem" }}>
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
              <polyline points="22 4 12 14.01 9 11.01" />
            </svg>
            <span>{success}</span>
          </div>
          <button
            type="button"
            onClick={() => setSuccess("")}
            style={{
              background: "transparent",
              border: "none",
              color: "inherit",
              cursor: "pointer",
              fontWeight: "bold",
            }}
          >
            ✕
          </button>
        </div>
      )}

      {/* Add / Edit Task Form */}
      {showForm && (
        <section id="task-form" className="form-card">
          <div className="form-card-header">
            <h2>{editingTask ? "Edit Task Details" : "Create New Task"}</h2>
            <button
              type="button"
              className="btn-icon-action"
              onClick={() => resetForm(true)}
              aria-label="Close form"
            >
              ✕
            </button>
          </div>

          <form onSubmit={handleSubmit} noValidate>
            <div className="form-grid">
              <div className="input-block form-group-full">
                <label htmlFor="title">Task Title *</label>
                <input
                  id="title"
                  className="input-control"
                  placeholder="e.g. Design Landing Page Wireframes"
                  value={title}
                  onChange={(event) =>
                    updateField(setTitle, "title", event.target.value)
                  }
                  maxLength={150}
                  required
                  aria-invalid={Boolean(validationErrors.title)}
                  aria-describedby={
                    validationErrors.title ? "title-error" : undefined
                  }
                />
                {renderFieldError("title")}
              </div>

              <div className="input-block form-group-full">
                <label htmlFor="description">Task Description</label>
                <textarea
                  id="description"
                  className="input-control"
                  placeholder="Provide additional context or requirements..."
                  value={description}
                  onChange={(event) =>
                    updateField(setDescription, "description", event.target.value)
                  }
                  maxLength={10000}
                  rows={3}
                  aria-invalid={Boolean(validationErrors.description)}
                  aria-describedby={
                    validationErrors.description ? "description-error" : undefined
                  }
                />
                {renderFieldError("description")}
              </div>

              <div className="input-block">
                <label htmlFor="formStatus">Status</label>
                <select
                  id="formStatus"
                  className="input-control"
                  value={status}
                  onChange={(event) =>
                    updateField(setStatus, "status", event.target.value)
                  }
                >
                  <option value="todo">To Do</option>
                  <option value="in-progress">In Progress</option>
                  <option value="done">Done</option>
                </select>
                {renderFieldError("status")}
              </div>

              <div className="input-block">
                <label htmlFor="priority">Priority</label>
                <select
                  id="priority"
                  className="input-control"
                  value={priority}
                  onChange={(event) =>
                    updateField(setPriority, "priority", event.target.value)
                  }
                >
                  <option value="low">Low Priority</option>
                  <option value="medium">Medium Priority</option>
                  <option value="high">High Priority</option>
                </select>
                {renderFieldError("priority")}
              </div>

              <div className="input-block">
                <label htmlFor="dueDate">Due Date</label>
                <input
                  id="dueDate"
                  type="date"
                  className="input-control"
                  value={dueDate}
                  onChange={(event) =>
                    updateField(setDueDate, "dueDate", event.target.value)
                  }
                />
                {renderFieldError("dueDate")}
              </div>
            </div>

            <div className="form-action-footer">
              <button
                type="submit"
                disabled={saving}
                className="btn-add-primary"
              >
                {saving
                  ? editingTask
                    ? "Updating..."
                    : "Creating..."
                  : editingTask
                    ? "Update Task"
                    : "Create Task"}
              </button>

              <button
                type="button"
                onClick={() => resetForm(true)}
                disabled={saving}
                className="btn-secondary"
              >
                Cancel
              </button>
            </div>
          </form>
        </section>
      )}

      {/* Search & Filter Toolbar */}
      <section className="toolbar-card">
        <div className="toolbar-inputs">
          <div className="toolbar-item" style={{ flex: 1.5 }}>
            <label htmlFor="taskSearch">Search</label>
            <div className="search-input-wrapper">
              <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
              >
                <circle cx="11" cy="11" r="8" />
                <line x1="21" y1="21" x2="16.65" y2="16.65" />
              </svg>
              <input
                id="taskSearch"
                className="input-control"
                type="search"
                placeholder="Search tasks..."
                value={search}
                onChange={(event) => handleSearchChange(event.target.value)}
                maxLength={150}
              />
            </div>
          </div>

          <div className="toolbar-item">
            <label htmlFor="statusFilter">Status Filter</label>
            <select
              id="statusFilter"
              className="input-control"
              value={statusFilter}
              onChange={(event) => handleFilterChange(event.target.value)}
            >
              <option value="">All Statuses</option>
              <option value="todo">To Do</option>
              <option value="in-progress">In Progress</option>
              <option value="done">Done</option>
            </select>
          </div>

          <div className="toolbar-item" style={{ minWidth: "120px" }}>
            <label htmlFor="perPage">Per Page</label>
            <select
              id="perPage"
              className="input-control"
              value={perPage}
              onChange={(event) => handlePageSizeChange(event.target.value)}
            >
              {PAGE_SIZE_OPTIONS.map((size) => (
                <option key={size} value={size}>
                  {size} per page
                </option>
              ))}
            </select>
          </div>
        </div>

        {hasActiveFilters && (
          <button
            type="button"
            className="btn-secondary"
            onClick={() => {
              setSearch("");
              setStatusFilter("");
              setPage(1);
              setError("");
            }}
          >
            Reset Filters
          </button>
        )}
      </section>

      {/* Task List */}
      <section>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "1rem" }}>
          <h2 style={{ margin: 0, fontSize: "1.25rem", fontWeight: 700 }}>
            Task Directory ({pagination.total})
          </h2>
        </div>

        {loading ? (
          <div className="empty-box">
            <div className="spinner" style={{ margin: "0 auto 1rem auto" }}></div>
            <p>Fetching your task records...</p>
          </div>
        ) : tasks.length === 0 ? (
          <div className="empty-box">
            <div className="empty-icon-wrap">
              <svg
                width="26"
                height="26"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
              >
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
              </svg>
            </div>
            <h3>No tasks found</h3>
            <p>
              {search.trim()
                ? `No tasks found matching "${search.trim()}".`
                : statusFilter
                  ? `There are no tasks with status "${statusFilter}".`
                  : "You don't have any tasks right now. Create one to get started!"}
            </p>
            {hasActiveFilters ? (
              <button type="button" className="btn-secondary" onClick={clearSearch}>
                Clear Search & Filters
              </button>
            ) : (
              <button
                type="button"
                className="btn-add-primary"
                onClick={handleAddTask}
              >
                + Create Task
              </button>
            )}
          </div>
        ) : (
          <div className="tasks-container">
            {tasks.map((task) => (
              <article key={task.id} className="task-item-card">
                <div className="task-content-area">
                  <div className="task-top-meta">
                    <span className={`badge badge-${task.priority || "medium"}`}>
                      {task.priority || "medium"}
                    </span>
                    <h3 className="task-title">{task.title}</h3>
                  </div>

                  {task.description && (
                    <p className="task-description">{task.description}</p>
                  )}

                  <div className="task-bottom-info">
                    <span className="meta-chip">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                      </svg>
                      Due: {task.due_date && task.due_date !== "0000-00-00" ? task.due_date.slice(0, 10) : "No deadline"}
                    </span>
                  </div>
                </div>

                <div className="task-actions-area">
                  <select
                    className="status-dropdown"
                    aria-label={`Status for ${task.title}`}
                    value={task.status}
                    disabled={changingStatusId === task.id || deletingTaskId === task.id}
                    onChange={(event) => handleStatusChange(task, event.target.value)}
                  >
                    <option value="todo">To Do</option>
                    <option value="in-progress">In Progress</option>
                    <option value="done">Done</option>
                  </select>

                  <button
                    type="button"
                    className="btn-icon-action"
                    title="Edit task"
                    onClick={() => handleEdit(task)}
                    disabled={saving || changingStatusId === task.id || deletingTaskId === task.id}
                  >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
                  </button>

                  <button
                    type="button"
                    className="btn-icon-action danger"
                    title="Delete task"
                    onClick={() => handleDelete(task)}
                    disabled={deletingTaskId === task.id || changingStatusId === task.id}
                  >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                      <polyline points="3 6 5 6 21 6" />
                      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    </svg>
                  </button>
                </div>
              </article>
            ))}
          </div>
        )}

        {/* Pagination Bar */}
        {!loading && pagination.totalPages > 1 && (
          <nav aria-label="Task pagination" className="pagination-bar">
            <button
              type="button"
              className="pagination-btn"
              disabled={page <= 1}
              onClick={() => setPage((current) => current - 1)}
            >
              ← Previous
            </button>

            <span className="pagination-text">
              Page <strong>{pagination.page}</strong> of <strong>{pagination.totalPages}</strong>
            </span>

            <button
              type="button"
              className="pagination-btn"
              disabled={page >= pagination.totalPages}
              onClick={() => setPage((current) => current + 1)}
            >
              Next →
            </button>
          </nav>
        )}
      </section>
    </main>
  );
}

function App() {
  const [user, setUser] = useState(null);
  const [checkingSession, setCheckingSession] = useState(true);
  const [loggingOut, setLoggingOut] = useState(false);

  useEffect(() => {
    let active = true;

    async function checkSession() {
      try {
        const response = await authApi.get("", {
          params: { action: "me" },
        });

        const result = response.data;
        const currentUser =
          result?.user ??
          result?.data?.user ??
          (result?.data?.id ? result.data : null);

        if (active) {
          setUser(currentUser?.id ? currentUser : null);
        }
      } catch (err) {
        if (active) {
          setUser(null);
        }
      } finally {
        if (active) {
          setCheckingSession(false);
        }
      }
    }

    checkSession();

    return () => {
      active = false;
    };
  }, []);

  async function handleLogout() {
    if (loggingOut) {
      return;
    }

    try {
      setLoggingOut(true);
      await authApi.post("", null, {
        params: { action: "logout" },
      });
    } catch (err) {
      console.error("Logout error:", err);
    } finally {
      setUser(null);
      setLoggingOut(false);
    }
  }

  if (checkingSession) {
    return (
      <div
        style={{
          display: "flex",
          minHeight: "100vh",
          alignItems: "center",
          justifyContent: "center",
          background: "#f8fafc",
          flexDirection: "column",
          gap: "1rem",
        }}
      >
        <div className="spinner"></div>
        <p style={{ color: "#64748b", fontWeight: 500 }}>Connecting to Task Manager...</p>
      </div>
    );
  }

  if (!user) {
    return <Login onLogin={setUser} />;
  }

  const userInitial = user.name ? user.name.charAt(0).toUpperCase() : "U";

  return (
    <>
      {/* Modern Sticky Navigation Bar */}
      <header className="app-navbar">
        <div className="nav-brand">
          <div className="nav-brand-logo">
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z" />
              <path d="m9 12 2 2 4-4" />
            </svg>
          </div>
          <span>TaskManager</span>
        </div>

        <div className="nav-user-panel">
          <div className="user-badge">
            <div className="user-avatar">{userInitial}</div>
            <div className="user-info-text">
              <span className="user-name">{user.name}</span>
              <span className="user-role-badge">{user.role || "Member"}</span>
            </div>
          </div>

          <button
            type="button"
            className="btn-logout"
            onClick={handleLogout}
            disabled={loggingOut}
          >
            <svg
              width="15"
              height="15"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
            >
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            {loggingOut ? "Signing out..." : "Logout"}
          </button>
        </div>
      </header>

      {/* Main Task Application */}
      <TaskManager />
    </>
  );
}

export default App;
