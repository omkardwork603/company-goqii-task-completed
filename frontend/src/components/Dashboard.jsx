import "./Dashboard.css";

function Dashboard({ tasks = [], onAddTask }) {
  const totalTasks = tasks.length;
  const todoTasks = tasks.filter((task) => task.status === "todo").length;
  const inProgressTasks = tasks.filter((task) => task.status === "in-progress").length;
  const completedTasks = tasks.filter((task) => task.status === "done").length;

  const recentTasks = [...tasks]
    .sort((a, b) => Number(b.id) - Number(a.id))
    .slice(0, 5);

  const cards = [
    {
      title: "Total Tasks",
      count: totalTasks,
      description: "All loaded tasks",
      theme: "theme-total",
      icon: (
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z" />
          <path d="M7 7h.01" />
        </svg>
      ),
    },
    {
      title: "To Do",
      count: todoTasks,
      description: "Awaiting start",
      theme: "theme-todo",
      icon: (
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <circle cx="12" cy="12" r="10" />
          <polyline points="12 6 12 12 14 14" />
        </svg>
      ),
    },
    {
      title: "In Progress",
      count: inProgressTasks,
      description: "Active execution",
      theme: "theme-progress",
      icon: (
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67" />
        </svg>
      ),
    },
    {
      title: "Completed",
      count: completedTasks,
      description: "Finished goals",
      theme: "theme-done",
      icon: (
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
          <polyline points="22 4 12 14.01 9 11.01" />
        </svg>
      ),
    },
  ];

  function formatStatus(status) {
    const labels = {
      todo: "To Do",
      "in-progress": "In Progress",
      done: "Done",
    };
    return labels[status] || status;
  }

  function formatDate(date) {
    if (!date || date === "0000-00-00") {
      return "No due date";
    }

    const parsedDate = new Date(`${date}T00:00:00`);
    if (Number.isNaN(parsedDate.getTime())) {
      return date;
    }

    return parsedDate.toLocaleDateString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
    });
  }

  return (
    <section className="dashboard-wrapper">
      {/* Metrics Overview Cards */}
      <div className="dashboard-metrics-grid">
        {cards.map((card) => (
          <article key={card.title} className={`metric-card ${card.theme}`}>
            <div className="metric-info">
              <span className="metric-title">{card.title}</span>
              <span className="metric-count">{card.count}</span>
              <span className="metric-description">{card.description}</span>
            </div>
            <div className="metric-icon-box">
              {card.icon}
            </div>
          </article>
        ))}
      </div>

      {/* Recent Tasks Card */}
      <div className="recent-tasks-card">
        <div className="recent-tasks-header">
          <div style={{ display: "flex", alignItems: "center", gap: "0.6rem" }}>
            <h3>Recent Activity</h3>
            <span className="recent-badge-count">{recentTasks.length} latest</span>
          </div>
        </div>

        {recentTasks.length === 0 ? (
          <div className="recent-empty-state">
            <p>No recent tasks found yet.</p>
            <button type="button" className="btn-sm-primary" onClick={onAddTask}>
              Create your first task
            </button>
          </div>
        ) : (
          <div className="table-responsive">
            <table className="styled-table">
              <thead>
                <tr>
                  <th>Task Name</th>
                  <th>Status</th>
                  <th>Priority</th>
                  <th>Deadline</th>
                </tr>
              </thead>
              <tbody>
                {recentTasks.map((task) => (
                  <tr key={task.id}>
                    <td className="task-title-cell">{task.title}</td>
                    <td>
                      <span className={`status-pill ${task.status}`}>
                        <span className="status-dot"></span>
                        {formatStatus(task.status)}
                      </span>
                    </td>
                    <td>
                      <span className={`badge badge-${task.priority || "medium"}`}>
                        {task.priority || "medium"}
                      </span>
                    </td>
                    <td>{formatDate(task.due_date)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}

export default Dashboard;
