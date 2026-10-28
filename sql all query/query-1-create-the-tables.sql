CREATE TABLE roles (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (name)
VALUES ('admin'), ('user');


CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id TINYINT UNSIGNED NOT NULL DEFAULT 2,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status ENUM('todo', 'in-progress', 'done')
        NOT NULL DEFAULT 'todo',
    priority ENUM('low', 'medium', 'high')
        NOT NULL DEFAULT 'medium',
    due_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_tasks_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,

    INDEX idx_tasks_user_status_id (user_id, status, id),
    INDEX idx_tasks_user_id (user_id, id)
) ENGINE=InnoDB;