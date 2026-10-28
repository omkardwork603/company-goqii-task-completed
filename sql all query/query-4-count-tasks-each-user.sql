SELECT
    u.id AS user_id,
    u.name,
    COUNT(t.id) AS total_tasks,
    SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END)
        AS completed_tasks,
    SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END)
        AS todo_tasks,
    SUM(CASE WHEN t.status = 'in-progress' THEN 1 ELSE 0 END)
        AS in_progress_tasks
FROM users AS u
LEFT JOIN tasks AS t
    ON t.user_id = u.id
GROUP BY u.id, u.name
ORDER BY u.id;