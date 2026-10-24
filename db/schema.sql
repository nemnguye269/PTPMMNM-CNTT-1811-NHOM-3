-- Lược đồ CSDL (SQLite 3). Nguồn chạy thật: src/Db.php (Db::migrate); tệp này là bản tham chiếu
-- và được test `Lược đồ db/schema.sql khớp Db::migrate` đối chiếu để không lệch nhau.
-- Dùng thử:  sqlite3 data/demo.sqlite < db/schema.sql
-- Mọi bảng dữ liệu GTFS có version_id -> versions(id) ON DELETE CASCADE: xóa một phiên bản
-- chỉ xóa dữ liệu của phiên bản đó.
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  source_name TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('valid','invalid')),
  error_count INTEGER NOT NULL DEFAULT 0,
  warning_count INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS import_issues (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  severity TEXT NOT NULL, code TEXT NOT NULL, file TEXT NOT NULL,
  line INTEGER, message TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL,
  actor TEXT NOT NULL, action TEXT NOT NULL, detail TEXT
);
CREATE TABLE IF NOT EXISTS saved_queries (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
  kind TEXT NOT NULL, params TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS agency (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  agency_id TEXT, agency_name TEXT, agency_url TEXT, agency_timezone TEXT
);
CREATE TABLE IF NOT EXISTS stops (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  stop_id TEXT NOT NULL, stop_name TEXT NOT NULL, name_norm TEXT NOT NULL,
  stop_lat REAL, stop_lon REAL,
  PRIMARY KEY (version_id, stop_id)
);
CREATE TABLE IF NOT EXISTS routes (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  route_id TEXT NOT NULL, route_short_name TEXT, route_long_name TEXT,
  route_type INTEGER, name_norm TEXT NOT NULL,
  PRIMARY KEY (version_id, route_id)
);
CREATE TABLE IF NOT EXISTS trips (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  trip_id TEXT NOT NULL, route_id TEXT NOT NULL, service_id TEXT NOT NULL,
  trip_headsign TEXT, headsign_norm TEXT, direction_id INTEGER,
  PRIMARY KEY (version_id, trip_id)
);
CREATE TABLE IF NOT EXISTS stop_times (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  trip_id TEXT NOT NULL, stop_sequence INTEGER NOT NULL, stop_id TEXT NOT NULL,
  arrival_secs INTEGER NOT NULL, departure_secs INTEGER NOT NULL,
  arrival_text TEXT NOT NULL, departure_text TEXT NOT NULL,
  is_last INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (version_id, trip_id, stop_sequence)
);
CREATE TABLE IF NOT EXISTS calendar (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  service_id TEXT NOT NULL,
  monday INTEGER, tuesday INTEGER, wednesday INTEGER, thursday INTEGER,
  friday INTEGER, saturday INTEGER, sunday INTEGER,
  start_date TEXT NOT NULL, end_date TEXT NOT NULL,
  PRIMARY KEY (version_id, service_id)
);
CREATE TABLE IF NOT EXISTS calendar_dates (
  version_id INTEGER NOT NULL REFERENCES versions(id) ON DELETE CASCADE,
  service_id TEXT NOT NULL, date TEXT NOT NULL, exception_type INTEGER NOT NULL,
  PRIMARY KEY (version_id, service_id, date)
);
CREATE INDEX IF NOT EXISTS idx_st_stop ON stop_times(version_id, stop_id, departure_secs);
CREATE INDEX IF NOT EXISTS idx_trips_route ON trips(version_id, route_id);
CREATE INDEX IF NOT EXISTS idx_cd_date ON calendar_dates(version_id, date);
