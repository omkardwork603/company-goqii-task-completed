SELECT
    id,
    title,
    description,
    status,
    priority,
    due_date,
    created_at
FROM tasks
WHERE user_id = 5
  AND status = 'todo'
ORDER BY id DESC
LIMIT 10;