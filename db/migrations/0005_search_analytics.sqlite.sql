-- Faza 6: Hybrid arch — track user searches so cron can pre-fetch popular keywords.

CREATE TABLE IF NOT EXISTS search_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    "query"    TEXT NOT NULL,
    results    INTEGER NOT NULL DEFAULT 0,
    live_used  INTEGER NOT NULL DEFAULT 0,
    ip_hash    TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_search_logs_query   ON search_logs("query", created_at);
CREATE INDEX IF NOT EXISTS idx_search_logs_created ON search_logs(created_at);

CREATE TABLE IF NOT EXISTS hot_keywords (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    keyword         TEXT NOT NULL UNIQUE,
    priority        INTEGER NOT NULL DEFAULT 10,
    is_active       INTEGER NOT NULL DEFAULT 1,
    last_fetched_at DATETIME,
    last_results    INTEGER NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_hot_keywords_active ON hot_keywords(is_active, priority);
