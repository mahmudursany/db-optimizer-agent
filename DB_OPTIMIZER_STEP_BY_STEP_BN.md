# DB Optimizer Tool — Step-by-Step Process (Bangla)

এই ডকুমেন্টে `db-optimizer-tool` কীভাবে end-to-end কাজ করে, কোন request-এ কোন method hit হয়, এবং কীভাবে full project optimize করতে হয়—সব কিছু ধাপে ধাপে দেয়া হলো।

---

## 1) High-Level Architecture

```text
User Request / API Call
        |
        v
Laravel App
        |
        v
DB::listen() -> QueryInterceptor::capture()
        |
        +--> query metadata capture
        +--> detectors (N+1, Missing Index, Cache)
        +--> slow হলে EXPLAIN
        |
        v
QueryMetricsStore (buffer)
        |
        v
Request end -> flushSnapshot() -> NDJSON storage
        |
        +--> Dashboard (/_db-optimizer)
        +--> Agent API (/_db-optimizer/agent/*)
                   |
                   v
           Remote Scanner (/_db-optimizer/scanner)
```

---

## 2) Boot Flow (App start হলে)

### Step 2.1: Provider load
- `App\\DbOptimizer\\Providers\\DbOptimizerServiceProvider` boot হয়।

### Step 2.2: Config merge
- `register()` এ `config/db_optimizer.php` merge হয়।

### Step 2.3: Runtime hooks attach
- `boot()` এ দুটি গুরুত্বপূর্ণ hook বসে:
  1. `DB::listen(...)` → প্রতিটি query-তে `capture()` চালু
  2. `app()->terminating(...)` → request শেষে `flushRequestSnapshot()`

---

## 3) Per Query Capture Flow

যখন কোনো SQL execute হয়, Laravel `QueryExecuted` event দেয়।

### Step 3.1: `capture()` hit
- `QueryInterceptor::capture(QueryExecuted $event)` call হয়।

### Step 3.2: enable/sampling check
- `isEnabled()` check করে feature চালু কিনা
- `sample_rate` check করে query নেওয়া হবে কিনা

### Step 3.3: fingerprint + repetition
- normalized SQL থেকে fingerprint
- exact SQL+bindings signature
- repetition counter update

### Step 3.4: origin resolve
- `QueryOriginResolver::resolve()` call
- query কোন file/line থেকে এসেছে তা best-effort বের করে

### Step 3.5: detector run
`capture()` এর detector block-এ তিনটি method call হয়:

1. `nPlusOneSignal($sql, $repetition, $origin)`
   - repeated single-row fetch pattern detect
2. `suggestMissingIndexes($sql, $connectionName)`
   - WHERE/JOIN column থেকে index suggestion
3. `cacheSignal($sql, $repetition)`
   - same static query repeated হলে cache hint

### Step 3.6: slow query হলে EXPLAIN
- `time_ms >= slow_query_threshold_ms` হলে:
  - `buildExplainSummary($event)`
  - MySQL হলে `EXPLAIN` run
  - `humanizeExplain()` দিয়ে readable summary

### Step 3.7: in-memory buffer record
- `QueryMetricsStore::record($metric)` call

---

## 4) Request End Flow

Request complete হলে:

### Step 4.1: `flushRequestSnapshot()`
- URL, method, route, query_count meta build করে

### Step 4.2: `flushSnapshot($meta)`
- সব buffered query NDJSON line হিসেবে append হয়
- storage path: `db_optimizer.storage_path`
- file format: `queries-YYYY-MM-DD.ndjson`

### Step 4.3: counters reset
- request-level counters clear হয়

---

## 5) Dashboard Flow

Routes:
- `GET /_db-optimizer`
- `GET /_db-optimizer/snapshots/{snapshotId}`

### Step 5.1: list page
- `DbOptimizerDashboardController::index()`
- `QuerySnapshotReader::latest(50)`

### Step 5.2: detail page
- `DbOptimizerDashboardController::show($snapshotId)`
- `QuerySnapshotReader::findById($snapshotId)`

### Step 5.3: summary বানানো
- `QuerySnapshotReader::buildSummary()`
  - total query count
  - total/avg time
  - slow count
  - n+1 count
  - missing index hints
  - cache candidates

---

## 6) Agent API Flow (Remote scanner-এর জন্য)

Routes:
- `GET /_db-optimizer/agent/ping`
- `GET /_db-optimizer/agent/snapshots`
- `POST /_db-optimizer/agent/reset`

### Security
- `EnsureDbOptimizerAgentToken` middleware Bearer token verify করে
- token config: `DB_OPTIMIZER_AGENT_TOKEN`

### Endpoints
1. `ping()` → health/status
2. `snapshots()` → `latest(limit, since)` data return
3. `reset()` → stored snapshots clear

---

## 7) Scanner Flow (Remote project scan)

Route:
- `POST /_db-optimizer/scanner/run`

### Step-by-step
1. `DbOptimizerScannerController::run()` input validate করে
2. `RemoteProjectScanner::scan(targetUrl, token, paths)` call করে
3. scanner flow:
   - `buildClient(token)`
   - `pingAgent()`
   - `normalizePaths()`
   - path loop করে `GET` hit
   - `fetchSnapshots(since=startedAt)`
   - `buildReport()`
4. report session-এ save হয়
5. scanner page-এ report দেখায়

---

## 8) Full Project Optimization Workflow (Practical)

শুধু dashboard open করলে full project query আসবে না।
**যে flow hit হবে, শুধু সেই flow-এর query capture হবে।**

### Recommended workflow
1. baseline reset করুন (`agent/reset`)
2. sample rate `1` রাখুন
3. public + auth + admin + api + checkout সব flow চালান
4. report analyze করুন priority অনুযায়ী:
   - slow queries
   - N+1
   - missing index
   - cache candidates
5. fix দিন (eager loading/index/cache/sql rewrite)
6. same flow rerun করুন
7. before/after compare করুন

---

## 9) Config Reference

Common env keys:

```dotenv
DB_OPTIMIZER_ENABLED=true
DB_OPTIMIZER_CAPTURE_CONSOLE=false
DB_OPTIMIZER_SAMPLE_RATE=1
DB_OPTIMIZER_SLOW_MS=50
DB_OPTIMIZER_N1_THRESHOLD=5
DB_OPTIMIZER_CACHE_REPEAT_THRESHOLD=8
DB_OPTIMIZER_STORAGE_DISK=local
DB_OPTIMIZER_STORAGE_PATH=db-optimizer
DB_OPTIMIZER_AGENT_TOKEN=your-secret-token
DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer
DB_OPTIMIZER_SCANNER_TIMEOUT=20
```

---

## 10) Troubleshooting Quick Notes

1. **No data দেখায়**
   - `DB_OPTIMIZER_ENABLED=true` কিনা দেখুন
   - app routes hit করেছেন কিনা দেখুন
   - sample rate বেশি restrictive কিনা দেখুন

2. **Agent unauthorized (401)**
   - Bearer token mismatch

3. **Missing index suggestions কম/না আসা**
   - query pattern parse-able কিনা
   - driver MySQL কিনা (index introspection MySQL-focused)

4. **EXPLAIN run হচ্ছে না**
   - threshold cross হয়নি
   - connection MySQL না

---

## 11) Method Call Map (Quick Reference)

```text
DbOptimizerServiceProvider::boot
  -> DB::listen -> QueryInterceptor::capture
      -> isEnabled
      -> normalizeSql
      -> incrementCounter
      -> QueryOriginResolver::resolve
      -> nPlusOneSignal
      -> suggestMissingIndexes
          -> extractColumnReferences
          -> hasLeadingIndex
              -> fetchTableIndexes
      -> cacheSignal
      -> buildExplainSummary (conditional)
          -> humanizeExplain
      -> QueryMetricsStore::record

app()->terminating
  -> QueryInterceptor::flushRequestSnapshot
      -> QueryMetricsStore::flushSnapshot
```

---

## 12) Developer Checklist

- [ ] provider registered
- [ ] middleware aliases set
- [ ] env configured
- [ ] dashboard routes reachable
- [ ] agent token set
- [ ] snapshots being written
- [ ] scanner can ping remote agent
- [ ] first optimization cycle completed

---

Last updated: 2026-03-28
