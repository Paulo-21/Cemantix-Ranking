CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    api_token CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS submissions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    submitted_word VARCHAR(120) NOT NULL,
    submitted_day DATE NOT NULL,
    submitted_at TIMESTAMP NOT NULL,
    CONSTRAINT uniq_user_submitted_day UNIQUE (user_id, submitted_day)
);

CREATE INDEX IF NOT EXISTS idx_submitted_at ON submissions (submitted_at);
CREATE INDEX IF NOT EXISTS idx_submitted_day_time ON submissions (submitted_day, submitted_at, id);
