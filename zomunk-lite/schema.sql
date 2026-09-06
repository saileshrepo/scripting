-- zomunk-lite schema (SQLite). MySQL equivalent lives in schema.mysql.sql.

CREATE TABLE IF NOT EXISTS routes (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    origin             TEXT    NOT NULL,
    destination        TEXT    NOT NULL,
    label              TEXT    NOT NULL,
    cabin              TEXT    NOT NULL DEFAULT 'ECONOMY',
    currency           TEXT    NOT NULL DEFAULT 'INR',
    typical_fare_seed  REAL    NOT NULL,
    max_duration_hours INTEGER NOT NULL DEFAULT 26,
    active             INTEGER NOT NULL DEFAULT 1,
    UNIQUE (origin, destination, cabin)
);

-- Every qualifying fare we observe. The median of this table is what a route
-- "normally" costs, and therefore what a discount is measured against.
CREATE TABLE IF NOT EXISTS fare_history (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id     INTEGER NOT NULL REFERENCES routes(id),
    depart_month TEXT    NOT NULL,          -- YYYY-MM
    depart_date  TEXT    NOT NULL,
    return_date  TEXT,
    price        REAL    NOT NULL,
    currency     TEXT    NOT NULL,
    cabin        TEXT    NOT NULL,
    observed_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_history_route ON fare_history (route_id, observed_at);
CREATE INDEX IF NOT EXISTS idx_history_month ON fare_history (route_id, depart_month);

CREATE TABLE IF NOT EXISTS deals (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id         INTEGER NOT NULL REFERENCES routes(id),
    dedupe_key       TEXT    NOT NULL,
    price            REAL    NOT NULL,
    currency         TEXT    NOT NULL,
    typical_fare     REAL    NOT NULL,
    discount         REAL    NOT NULL,      -- 0.42 == 42% below typical
    baseline_source  TEXT    NOT NULL,      -- 'observed' | 'seed'
    cabin            TEXT    NOT NULL,
    depart_date      TEXT    NOT NULL,
    return_date      TEXT,
    carrier_code     TEXT,
    carrier_name     TEXT,
    stops            INTEGER NOT NULL DEFAULT 0,
    duration_minutes INTEGER NOT NULL DEFAULT 0,
    layovers         TEXT,                  -- "DXB 1h55m"
    bag_included     INTEGER NOT NULL DEFAULT 0,
    is_mistake       INTEGER NOT NULL DEFAULT 0,
    tier             TEXT    NOT NULL,      -- 'free' | 'premium'
    booking_url      TEXT,
    offer_json       TEXT,
    found_at         TEXT    NOT NULL,
    publish_free_at  TEXT,                  -- free members see it from here
    expires_at       TEXT    NOT NULL,
    status           TEXT    NOT NULL DEFAULT 'active'  -- active | expired
);
CREATE INDEX IF NOT EXISTS idx_deals_found ON deals (found_at);
CREATE INDEX IF NOT EXISTS idx_deals_dedupe ON deals (dedupe_key, found_at);

CREATE TABLE IF NOT EXISTS notifications (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    deal_id     INTEGER NOT NULL REFERENCES deals(id),
    tier        TEXT    NOT NULL,
    channel     TEXT    NOT NULL DEFAULT 'sender',
    campaign_id TEXT,
    status      TEXT    NOT NULL,          -- sent | failed | skipped
    error       TEXT,
    sent_at     TEXT    NOT NULL,
    UNIQUE (deal_id, tier)
);

CREATE TABLE IF NOT EXISTS subscribers (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    email                TEXT    NOT NULL UNIQUE,
    name                 TEXT,
    tier                 TEXT    NOT NULL DEFAULT 'free',
    home_airports        TEXT,
    sender_subscriber_id TEXT,
    sender_status        TEXT,
    status               TEXT    NOT NULL DEFAULT 'active',
    created_at           TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS scan_runs (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    provider       TEXT    NOT NULL,
    started_at     TEXT    NOT NULL,
    finished_at    TEXT,
    routes_scanned INTEGER NOT NULL DEFAULT 0,
    offers_seen    INTEGER NOT NULL DEFAULT 0,
    offers_kept    INTEGER NOT NULL DEFAULT 0,
    deals_found    INTEGER NOT NULL DEFAULT 0,
    errors         TEXT
);
