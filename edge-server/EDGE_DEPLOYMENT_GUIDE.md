# Edge Storage Server — Full Deployment Guide

This guide walks through deploying one caching edge server per KVS storage group,
on a platform that already has the default KVS system with 40 storage servers
(one "master" storage server per group), and updating the main server to support
them (pull-through caching, pre-warming, and load-based balancing).

---

## 1. Architecture overview

```
                              ┌────────────────────────────────────────────┐
 visitor ──▶ main server      │  get_file.php picks a server inside the    │
             (get_file.php)   │  video's group by: proximity → weight →    │
                              │  health (error_id) → load threshold        │
                              └───────────────┬────────────────────────────┘
                                              │ signed redirect
                    ┌─────────────────────────┴───────────────────────┐
                    ▼                                                 ▼
        ┌───────────────────────┐                        ┌──────────────────────────┐
        │ master storage N      │                        │ edge N (same group)      │
        │ stock remote_control  │  cache miss: edge      │ modified remote_control  │
        │ always has all files  │◀── pulls via main's ───│ cache hit → serve local  │
        │ of group N            │    get_file.php        │ miss → stream + cache    │
        └───────────────────────┘   (admin_rq_server_id) └──────────────────────────┘
```

How an edge gets content (three ways):

1. **Push (KVS-native mirroring).** KVS uploads every *new* video's files to every
   server in the group — the edge included. This is why the edge needs a working
   FTP upload path just like the existing storage servers.
2. **Pull-through (backfill).** Files that existed *before* the edge joined are not
   on its disk. On the first request the edge pulls the file from the origin
   through `get_file.php` (signed with the shared secret), streams it to the
   visitor, and caches it. No bulk migration of terabytes is ever needed.
3. **Pre-warm (cron).** `edge_prewarm.php` downloads the group's top-N most-viewed
   videos ahead of demand, and evicts stale / least-recently-viewed files to keep
   the disk bounded.

Load-based balancing (new): `get_file.php` on the main server checks each
candidate's load through the edge control script's `action=load` API (cached
60 s in `admin/data/system/server_load_<id>.dat`). A server above the threshold
is skipped in favor of the next healthy candidate. Servers without the API —
your 40 masters running stock `remote_control.php` — always stay eligible, so
an overloaded edge simply fails over to its master.

### Files involved

| File (this repo)                              | Deploys to      | Role |
|-----------------------------------------------|-----------------|------|
| `edge_storage_server/edge_remote_control .php`| each edge, renamed **`remote_control.php`** | control script + pull-through cache + `action=load` API |
| `edge_storage_server/edge_prewarm.php`        | each edge       | cron: pre-warm top videos, evict stale/LRU files |
| `get_top_videos.php`                          | main web root   | top-videos list endpoint used by the prewarm cron |
| `get_file.php` (modified)                     | main web root   | adds load-threshold failover to server selection |
| `admin/include/functions_server_load.php` (new)| main server    | cached load probing for get_file.php |
| `monitor_server/server_monitor.php`           | any PHP host (main server or an ops box) | standalone monitoring dashboard: add servers by IP/port, see edge status, storage, bandwidth, CPU and cached-video counts (uses the edge `action=monitor` API; see §10) |
| `edge_storage_server/remote_control.php`      | (stock KVS — **do not deploy to edges**, it stays on the 40 masters unchanged) | |

---

## 2. Critical facts to understand before starting

These come straight from the KVS code and determine several setup decisions:

* **Group = mirror set.** Conversion pushes each new video's files to **every**
  server of the group and **cancels the task if any push fails**
  (`cron_conversion.php`, `put_file` loop). Consequences:
  * every edge must accept uploads (FTP, like your existing servers), landing in
    the same directory tree the cache uses;
  * an unreachable edge (`error_id=1`) or an edge below the free-space minimum
    (`SERVER_GROUP_MIN_FREE_SPACE_MB` option) **stalls new-video processing for
    its whole group** until fixed or detached (see §9.3).
* **Deactivating a server does NOT detach it.** `status_id` only affects
  delivery. The conversion push loop selects group members *regardless of
  status*. To truly detach an edge, move it to an unused group or delete it.
* **Server validation is pull-through-friendly.** The nightly server check
  requests a real video through `get_file.php?admin_rq_server_id=<edge>`; the
  edge answers by pulling the file from origin if it isn't cached. So evicted
  files never cause validation errors — but it also means the very first check
  after registration already exercises the whole pull path.
* **Shared secret:** the edge scripts sign and verify everything with a single
  `$config['cv']`, which must equal the **main server's `$config['cv']`**
  (`admin/include/setup.php`). If your main config also defines `$config['cvr']`
  (a separate remote key) with a *different* value, serving links will fail on
  the edge — ensure `cvr` is unset or equal to `cv` before rolling out.
* **Time sync matters.** The server check flags an edge whose clock is more than
  ±300 s off (error 4), and all signed links carry timestamps. Run NTP/chrony.
* **HTTPS required** on the edges if the main site is HTTPS (otherwise the
  server check reports error 7 and get_file skips the edge).

---

## 3. Per-group worksheet

Collect these values for each of the 40 groups before touching anything.
Suggested spreadsheet columns:

| # | Value | Where to find it | Example |
|---|-------|------------------|---------|
| 1 | Group ID (`edge_group_id`, admin "server group") | Admin → Settings → Storage servers → the group of that group's master server | `7` |
| 2 | Master server ID (`origin_server_id`)            | The `server_id` of the group's master storage server (visible in the server edit URL, `servers.php?...&id=N`) | `8` |
| 3 | Edge hostname                                     | your DNS plan, one per group | `edge-g07.example.com` |
| 4 | Content prefix (`origin_content_prefix`)          | path segment of the edge's "URLs" setting; keep `videos` everywhere | `videos` |
| 5 | Shared secret (`cv`)                              | `$config['cv']` in main `admin/include/setup.php` — same for all edges | `ed23...c1c` |
| 6 | Origin URL (`origin_url`)                         | main site base URL — same for all edges | `https://baddies.xxx` |
| 7 | Edge disk size / cache cap                        | your hardware plan | 2 TB, cap 1.8 TB |

Also note once, globally: the value of the KVS option `SERVER_GROUP_MIN_FREE_SPACE_MB`
(Admin → Settings → website settings; used by conversion). Your edge eviction
watermarks must keep free space **above** it (see §4.6).

---

## 4. Part A — Install one edge server (repeat per group)

Assumes Ubuntu/Debian with nginx + PHP-FPM; adapt paths for your distro.

### 4.1 Packages

```bash
apt install nginx php-fpm php-curl php-sqlite3 php-xml vsftpd chrony
```

Required PHP bits: **curl** (or `allow_url_fopen=On` as fallback) for pulls and
probes, **pdo_sqlite** for view tracking / LRU eviction, and a correctly ticking
clock (chrony/ntp). Use 64-bit PHP (file sizes and caches above 2 GB).

php-fpm pool settings worth checking:

```ini
; a cold-cache pull streams a whole video through PHP - do not let fpm kill it
request_terminate_timeout = 0
php_admin_value[max_execution_time] = 0   ; scripts also call set_time_limit(0)
```

### 4.2 Filesystem layout

```
/var/www/edge/                 ← web root, owned by the php-fpm user (www-data)
├── remote_control.php         ← edge_remote_control .php, renamed
├── edge_prewarm.php
├── videos/                    ← content/cache tree (create empty)
├── edge_cache.sqlite          ← created automatically (view db)
├── remote_control_cdn.log     ← created automatically (when $cdn_debug on)
└── edge_prewarm.log           ← created automatically
```

```bash
mkdir -p /var/www/edge/videos
chown -R www-data:www-data /var/www/edge
chmod 775 /var/www/edge /var/www/edge/videos
```

The whole tree must be writable by the PHP user: the cache writes, the eviction
deletes, and the logs all run as php-fpm.

### 4.3 FTP upload access (for KVS content push)

Configure vsftpd (or the same FTP daemon your existing 40 servers use) with a
user whose root maps into the content tree, e.g. local user `kvsftp` with home
`/var/www/edge/videos` (KVS pushes logical paths like `29000/29350/<file>.mp4`,
so the FTP folder must be the `videos/` directory itself, **not** the web root).

Two permission rules:

* files created by FTP must be readable by nginx/php → same group + umask `002`;
* files created by FTP must be **deletable** by php (eviction) → make `kvsftp` a
  member of `www-data`'s group (or run both as the same user), and set
  `local_umask=002` in `vsftpd.conf`.

### 4.4 nginx vhost

```nginx
server {
    listen 443 ssl;
    server_name edge-g07.example.com;

    ssl_certificate     /etc/letsencrypt/live/edge-g07.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/edge-g07.example.com/privkey.pem;

    root /var/www/edge;

    # control script (and prewarm's optional HTTP trigger)
    location ~ ^/(remote_control|edge_prewarm)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_read_timeout 3600s;      # cold-cache streams run long
    }

    # content: only reachable through X-Accel-Redirect from remote_control.php.
    # "internal" also makes the KVS "direct link" validation report the
    # hotlink protection as working (direct URL must NOT be publicly readable).
    location /videos/ {
        internal;
        mp4;                              # pseudo-streaming for ?start= seeks
        sendfile on;
        tcp_nopush on;
        aio threads;
    }

    location / {
        return 404;
    }
}
```

Reload nginx after installing the certificate (`certbot --nginx` works fine).

### 4.5 Configure `remote_control.php` (the edge copy)

Upload `edge_storage_server/edge_remote_control .php` to the edge **renamed to
`remote_control.php`**, then edit its configuration block:

```php
$origin_url            = "https://baddies.xxx"; // main site (same for all edges)
$origin_server_id      = "8";     // ← MASTER server_id of THIS group (worksheet col 2)
$origin_sg_id          = 7;       // any non-zero id; use the group id by convention
$origin_content_prefix = "videos";// path segment of this edge's "URLs" setting
$cdn_debug             = true;    // keep on until verified, then set false
$view_db_path          = __DIR__ . '/edge_cache.sqlite';
$cache_min_free_bytes  = 20 * 1024*1024*1024;  // real-time low-disk guard, see §4.6

$config['cv'] = "<main server's \$config['cv']>";
```

### 4.6 Configure `edge_prewarm.php`

Upload it next to `remote_control.php` and edit the header:

```php
$config['cv']          = "<same cv>";
$origin_url            = "https://baddies.xxx";
$origin_server_id      = "8";      // same as remote_control.php
$origin_sg_id          = 7;
$origin_content_prefix = "videos";

$top_limit     = 200;              // videos kept warm
$edge_group_id = 7;                // ← MUST equal this edge's KVS group

$enable_cleanup = true;
$stale_days     = 14;              // evict files not viewed for 2 weeks
$view_db_path   = __DIR__ . '/edge_cache.sqlite';   // must match remote_control.php

// disk caps - size these so free space NEVER drops below the KVS option
// SERVER_GROUP_MIN_FREE_SPACE_MB, otherwise conversion for this group stalls:
$max_cache_bytes = 1800 * 1024*1024*1024;  // e.g. 1.8 TB cap on a 2 TB disk
$min_free_bytes  =  100 * 1024*1024*1024;  // keep 100 GB free
```

**Sizing rule:** `$min_free_bytes` (prewarm) and `$cache_min_free_bytes`
(remote_control.php) must both be comfortably **larger** than
`SERVER_GROUP_MIN_FREE_SPACE_MB × 1024²`, because conversion refuses to process
new videos for the whole group when any member's free space falls below that
option. The eviction guards are what keep the edge above it.

Cron (system cron, php CLI — not through the web server):

```cron
0 */4 * * *  php /var/www/edge/edge_prewarm.php >/dev/null 2>&1
```

### 4.7 Edge self-test

```bash
curl https://edge-g07.example.com/remote_control.php
# → connected.

curl https://edge-g07.example.com/remote_control.php?action=version
# → 5.3.0

curl https://edge-g07.example.com/remote_control.php?action=load
# → e.g. 0.42|8      (loadavg|cores - this is the load-balancing probe)

curl "https://edge-g07.example.com/remote_control.php?action=cdncheck&cv=<cv>"
# → plain-text permission report; every line must say yes/ok

curl "https://edge-g07.example.com/remote_control.php?action=monitor&cv=<cv>"
# → JSON stats blob (cpu/net/disk/cache) used by the monitoring dashboard (§10)

php /var/www/edge/edge_prewarm.php          # first warm run (may take a while)
tail /var/www/edge/edge_prewarm.log         # expect "cached=..." lines, no FAILs
```

Also verify direct content access is blocked (should be 404, because of
`internal`): `curl -I https://edge-g07.example.com/videos/some/path.mp4`

---

## 5. Part B — Update the main server (once)

1. Deploy the three changed/new files from this repo to the main server:
   * `get_file.php` (adds the load-threshold failover in server selection)
   * `admin/include/functions_server_load.php` (new helper)
   * `get_top_videos.php` (prewarm list endpoint; skip if already deployed)
2. Optional tuning — add to `admin/include/setup.php` only if the defaults
   don't suit you:

   ```php
   $config['lb_load_threshold']     = 3.0; // load PER CORE above which a server is skipped
   $config['lb_load_cache_ttl']     = 60;  // seconds a probed value is reused
   $config['lb_load_check_timeout'] = 2;   // seconds per live probe
   ```

3. Confirm `admin/data/system/` is writable by the web user (it normally is —
   KVS writes `cluster.dat` there). The load cache files
   `server_load_<id>.dat` appear there once traffic flows.

Behavior notes:

* Only servers whose control script answers `action=load` participate in load
  skipping — i.e. only your edges. The 40 masters (stock `remote_control.php`)
  return an empty response for it, are cached as "unknown", and stay always
  eligible. Overloaded edge → traffic shifts to the master; never the reverse.
* Worst-case latency added to a playback request: one 2 s probe per 60 s per
  edge (all other requests read the cache). If everything in a group is
  overloaded, the original weighted pick is kept — serving beats failing.

---

## 6. Part C — Register the edge in the KVS admin (per group)

**Order matters: warm first, register second.** Run `edge_prewarm.php` once
(§4.7) *before* activating the server so the hottest content is already local
and the first hours don't hammer the origin.

Admin → Settings → Storage servers → **Add server**:

| Field | Value | Why |
|-------|-------|-----|
| Title | `Edge G07 (<location>)` | your naming |
| Content type | Videos | matches the group |
| **Server group** | **same group as the master** (worksheet col 1) | this is what makes get_file.php balance between master and edge — and what subscribes the edge to content push |
| Status | Active | required for delivery (`status_id=1`) |
| Server URLs | `https://edge-g07.example.com/videos` | the path segment must equal `$origin_content_prefix` and map onto the cache dir |
| Remote server | yes, control script URL `https://edge-g07.example.com/remote_control.php` | HTTPS mandatory if the site is HTTPS |
| Connection (FTP) | host/user/password from §4.3, folder = the `videos/` dir | conversion pushes new content through this |
| Load balancing weight | master `1`, edge `2`–`3` | send the bulk of traffic to the edge; tune later |
| Load balancing countries | the country the edge is physically in, e.g. `DE` | proximity routing treats `lb_countries` as the server's location; tag the master with *its* country too, or untagged servers count as `DE` (`PROXIMITY_DEFAULT_COUNTRY`) |

Saving regenerates `cluster.dat`, so get_file.php sees the new server
immediately. Then run the server check (Admin → the "server check" cron /
`admin/include/cron_servers.php`) and confirm the new row shows **no error and
no warning** — the check validates connection, clock, control script, HTTPS,
and requests a real video through the edge (which exercises pull-through).

---

## 7. Part D — Verification (per edge, before moving on)

1. **Pull-through:** from a browser (or `curl -L` with a valid signed link),
   play a video of that group that is *not* yet cached. Expect:
   * response header `KVS-CDN: stream-and-cache` on the first request;
   * the file appears under `/var/www/edge/videos/<dir>/<id>/`;
   * a `CACHED ok` line in `remote_control_cdn.log`;
   * the second request serves instantly from disk (no `KVS-CDN` header,
     X-Accel path).
2. **Seek on cold file:** jump mid-video on an uncached file — expect a `PROXY
   miss ... range=` log line and correct playback (partials are proxied, never
   cached).
3. **Push:** upload/convert a test video assigned to this group — its format
   files must appear on **both** the master and the edge (check the FTP tree),
   and the conversion log must show `Copying video file to "Edge G07 ..."`.
4. **Load balancing:** on the main server temporarily set
   `$config['lb_load_threshold'] = 0.01;` — within a minute
   `admin/data/system/server_load_<edge id>.dat` appears and traffic for that
   group shifts to the master (any measurable load now exceeds the threshold).
   Remove the override; traffic rebalances within the 60 s TTL.
5. **Eviction:** confirm `edge_prewarm.log` cleanup lines and that
   `disk free` stays above `SERVER_GROUP_MIN_FREE_SPACE_MB`.
6. Set `$cdn_debug = false;` in the edge's `remote_control.php` once satisfied
   (debug mode turns cache-permission problems into visible 503s, which you
   want during bring-up but not in production).

---

## 8. Part E — Rolling out all 40 groups

1. **Pilot one group** end-to-end (Parts A–D) and run it for a few days.
   Watch: origin bandwidth (pull-through backfill), edge disk fill rate,
   admin server page for errors, `remote_control_cdn.log` size.
2. **Template the rest.** Between edges only these values change:
   * DNS name / TLS cert
   * `$origin_server_id` (master id of the group)
   * `$origin_sg_id` / `$edge_group_id` (group id)
   * admin registration fields (group, URLs, control script, FTP)
   Everything else — nginx vhost, FTP daemon, cron line, `cv`, `origin_url` —
   is identical, so bake an image or a small provisioning script.
3. **Batch by 5–10 groups**, running the server check after each batch. Avoid
   registering all 40 at once: each new edge's backfill pulls through the main
   server, so spread the cache-warming load over days.
4. Keep a copy of the filled worksheet (§3) — it is your source of truth for
   which `origin_server_id` belongs to which edge.

---

## 9. Operations

### 9.1 Monitoring

* **The standalone dashboard (§10)** — live per-edge status, storage,
  bandwidth, CPU and cached-video counts on one page.
* Admin → Settings → Storage servers: error/warning columns after each server
  check (connection=1, control script=2/3, clock=4, validation=5, CDN api=6,
  https=7).
* Edge logs: `remote_control_cdn.log` (misses, caches, evictions, failures),
  `edge_prewarm.log` (warm/cleanup summary per run).
* Main: `admin/data/system/server_load_<id>.dat` (last probe: `ts|load|cores|ok`),
  and `admin/logs/get_file.txt` when `$config['enable_debug_get_file']` is on.
* The admin panel's server "load" column comes from the periodic server check —
  the 60 s balancing cache is separate and more current.

### 9.2 Tuning

* `lb_weight`: raise the edge's weight to push more of the group's traffic to
  it; the master then mostly serves overflow and cold misses.
* `lb_load_threshold`: streaming boxes run high loadavg from I/O wait — if
  edges get skipped too eagerly, raise it (per core); if they melt before
  skipping, lower it.
* `$top_limit` / `$stale_days` / cache caps: balance hit-rate against disk.
  If `edge_prewarm.log` warns the top list alone exceeds the cap, lower
  `$top_limit` or add disk.

### 9.3 Emergency: taking a broken edge out of service

Setting the server **inactive is not enough** — conversion still pushes to (and
is blocked by) every group member regardless of status. If an edge dies:

1. Admin → Storage servers → edit the edge → **move it to an unused/empty
   group** (or delete the server entry). This removes it from both delivery and
   the conversion push in one step; `cluster.dat` updates on save.
2. Group delivery instantly falls back to the master (which always has all
   files). No data is lost — the edge only ever holds copies.
3. When repaired, move it back into its group and re-run the server check.

### 9.4 Failure behavior cheat-sheet

| Failure | Effect | Automatic handling |
|---------|--------|--------------------|
| Edge overloaded (load API above threshold) | skipped for up to 60 s at a time | traffic shifts to master; edge rejoins when load drops |
| Edge origin-pull fails mid-stream | viewer gets 502/partial | file not cached (temp file discarded); next request retries |
| Edge disk low | real-time LRU eviction (`$cache_min_free_bytes`), then prewarm-cron eviction | keep watermarks above `SERVER_GROUP_MIN_FREE_SPACE_MB` |
| Edge unreachable | server check sets error 1 → get_file skips it → **conversion for the group stalls** | fix fast or detach (§9.3) |
| Load API missing/timeout (e.g. master servers) | treated as not overloaded | cached 60 s, no repeated timeout cost |
| Clock drift > 5 min | error 4, signed links may fail | run chrony/ntp |

### 9.5 KVS-Errno quick reference (edge responses)

`2` bad/expired B64 payload · `3` link timestamp outside TTL · `4` IP/signature
mismatch · `5` referer rejected · `6` file signature mismatch. A
`KVS-CDN-Errno: origin-unreachable` with 502 means the edge could not reach the
main server's `get_file.php` during a pull.

---

## 10. Monitoring dashboard (optional)

`monitor_server/server_monitor.php` is a self-contained, single-file dashboard
for watching all cache servers at once. It is independent of KVS — host it on
the main server, or on any box with PHP (7.0+ recommended) and either the curl
extension (preferred, polls all servers in parallel) or `allow_url_fopen=On`.

### 10.1 Install

1. Copy `monitor_server/server_monitor.php` to a web-served directory that is
   **writable by the PHP user** (it stores its server list next to itself in
   `monitor_servers.dat.php`, a self-blocking file that answers 404 if opened
   directly).
2. Edit the config block at the top:
   * `$monitor_password` — **required**; the dashboard refuses to run while it
     is empty (the page maps your whole delivery network — treat it like an
     admin page and serve it over HTTPS).
   * `$config['cv']` — the same shared secret as everywhere else (main
     server's `$config['cv']`). It signs the probes; it is never sent to the
     browser.
   * optionally `$probe_timeout` (default 5 s) and `$poll_interval_seconds`
     (default 10 s — also the averaging window for CPU % / bandwidth).
3. Open the page, log in, and add each cache server: scheme + IP/host + port
   (empty = 80/443 by scheme) + control script path (default
   `/remote_control.php`).

### 10.2 What it shows

| Column | Source | Notes |
|--------|--------|-------|
| Status | `action=monitor` probe | **EDGE · active** = edge build answering with pull-through enabled; **stock (no edge API)** = plain KVS `remote_control.php` (masters); **Auth failed** = the dashboard's `cv` doesn't match the server's; **Offline** = no HTTP answer |
| CPU | `/proc/stat` jiffies, delta between two polls | first refresh shows "measuring…" |
| Load | 1-min loadavg + per-core value | same probe |
| Bandwidth ↓/↑ | `/proc/net/dev` byte counters (all non-`lo` NICs), delta between two polls | includes *all* traffic on the box, not just video delivery |
| Storage | `disk_total_space`/`disk_free_space` of the content volume | stock servers report this too (via `action=status`) |
| Cached videos | walk of the edge's content tree | counts distinct per-video directories holding video files; recounted at most every 5 min per edge (cached in `edge_monitor_stats.dat`), so a full disk never gets re-walked on every poll |
| Plays 15m / 24h | the edge's SQLite view db | distinct files viewed — a direct "is this edge actually being used" signal |

Servers running the stock control script still appear (with load + storage
only), so you can add the 40 masters alongside the edges and see the whole
fleet on one page.

### 10.3 How it works / security notes

* The dashboard polls server-side (browser → dashboard → edges), so the shared
  secret stays off the client and no CORS/mixed-content issues arise.
* The edge `action=monitor` endpoint is `cv`-authenticated and returns
  cumulative counters; the dashboard's browser JS computes rates from the
  delta between its own polls, so the endpoint stays stateless and two
  dashboards polling the same edge never skew each other's numbers.
* All state-changing dashboard actions are POST with a CSRF token; login is
  rate-limited (1 s sleep on failure). Log out when done on shared machines.
