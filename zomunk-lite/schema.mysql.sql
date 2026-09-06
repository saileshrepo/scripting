-- zomunk-lite schema (MySQL 5.7+ / MariaDB). SQLite version is schema.sql.

CREATE TABLE IF NOT EXISTS routes (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    origin             CHAR(3)      NOT NULL,
    destination        CHAR(3)      NOT NULL,
    label              VARCHAR(120) NOT NULL,
    cabin              VARCHAR(20)  NOT NULL DEFAULT 'ECONOMY',
    currency           CHAR(3)      NOT NULL DEFAULT 'INR',
    typical_fare_seed  DECIMAL(12,2) NOT NULL,
    max_duration_hours INT          NOT NULL DEFAULT 26,
    active             TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_route (origin, destination, cabin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fare_history (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    route_id     INT           NOT NULL,
    depart_month CHAR(7)       NOT NULL,
    depart_date  DATE          NOT NULL,
    return_date  DATE          NULL,
    price        DECIMAL(12,2) NOT NULL,
    currency     CHAR(3)       NOT NULL,
    cabin        VARCHAR(20)   NOT NULL,
    observed_at  DATETIME      NOT NULL,
    KEY idx_history_route (route_id, observed_at),
    KEY idx_history_month (route_id, depart_month),
    CONSTRAINT fk_history_route FOREIGN KEY (route_id) REFERENCES routes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deals (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    route_id         INT           NOT NULL,
    dedupe_key       VARCHAR(120)  NOT NULL,
    price            DECIMAL(12,2) NOT NULL,
    currency         CHAR(3)       NOT NULL,
    typical_fare     DECIMAL(12,2) NOT NULL,
    discount         DECIMAL(5,4)  NOT NULL,
    baseline_source  VARCHAR(16)   NOT NULL,
    cabin            VARCHAR(20)   NOT NULL,
    depart_date      DATE          NOT NULL,
    return_date      DATE          NULL,
    carrier_code     VARCHAR(8)    NULL,
    carrier_name     VARCHAR(80)   NULL,
    stops            INT           NOT NULL DEFAULT 0,
    duration_minutes INT           NOT NULL DEFAULT 0,
    layovers         VARCHAR(255)  NULL,
    bag_included     TINYINT(1)    NOT NULL DEFAULT 0,
    is_mistake       TINYINT(1)    NOT NULL DEFAULT 0,
    tier             VARCHAR(10)   NOT NULL,
    booking_url      TEXT          NULL,
    offer_json       MEDIUMTEXT    NULL,
    found_at         DATETIME      NOT NULL,
    publish_free_at  DATETIME      NULL,
    expires_at       DATETIME      NOT NULL,
    status           VARCHAR(16)   NOT NULL DEFAULT 'active',
    KEY idx_deals_found (found_at),
    KEY idx_deals_dedupe (dedupe_key, found_at),
    CONSTRAINT fk_deals_route FOREIGN KEY (route_id) REFERENCES routes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    deal_id     INT          NOT NULL,
    tier        VARCHAR(10)  NOT NULL,
    channel     VARCHAR(20)  NOT NULL DEFAULT 'sender',
    campaign_id VARCHAR(64)  NULL,
    status      VARCHAR(16)  NOT NULL,
    error       TEXT         NULL,
    sent_at     DATETIME     NOT NULL,
    UNIQUE KEY uniq_deal_tier (deal_id, tier),
    CONSTRAINT fk_notify_deal FOREIGN KEY (deal_id) REFERENCES deals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscribers (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    email                VARCHAR(190) NOT NULL UNIQUE,
    name                 VARCHAR(120) NULL,
    tier                 VARCHAR(10)  NOT NULL DEFAULT 'free',
    home_airports        VARCHAR(120) NULL,
    sender_subscriber_id VARCHAR(64)  NULL,
    sender_status        VARCHAR(32)  NULL,
    status               VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at           DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scan_runs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    provider       VARCHAR(20) NOT NULL,
    started_at     DATETIME    NOT NULL,
    finished_at    DATETIME    NULL,
    routes_scanned INT         NOT NULL DEFAULT 0,
    offers_seen    INT         NOT NULL DEFAULT 0,
    offers_kept    INT         NOT NULL DEFAULT 0,
    deals_found    INT         NOT NULL DEFAULT 0,
    errors         TEXT        NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
