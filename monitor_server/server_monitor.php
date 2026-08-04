<?php
/* Standalone monitoring dashboard for KVS edge / cache storage servers.

   Drop this single file on any PHP host (the main server or a separate ops
   box), set $monitor_password and $config['cv'] below, and open it in a
   browser. Add each cache server by IP/host + port; the dashboard polls every
   server's remote_control.php in parallel and shows:

     - which servers are currently active as edges (and how busy they are:
       plays in the last 15 minutes)
     - total + free storage per server
     - bandwidth usage per server (down/up rates, computed from the edge's
       cumulative network counters between two dashboard polls)
     - CPU usage per server (same delta technique over /proc/stat jiffies)
     - number of cached videos (+ cache size on disk)
     - optional manual IP + free-text location per server (IP falls back to DNS)
     - historical samples in SQLite (sparklines on cards, full charts on detail)
     - download / view monitoring history (status samples stored on this dashboard host)

   Requirements:
     - the monitored servers must run the edge build of remote_control.php
       (edge_storage_server/edge_remote_control .php), which provides the
       authenticated action=monitor JSON API. Servers running the stock KVS
       remote_control.php are still probed via action=status and show load +
       storage only, flagged as "stock".
     - this host needs the curl extension (preferred; enables parallel polling)
       or allow_url_fopen=On, plus pdo_sqlite for history.
     - this script's directory must be writable: the server list is stored next
       to it in monitor_servers.dat.php and history in monitor_history.db.
*/

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);

// ------------------------------ configuration ------------------------------

// Dashboard login password. REQUIRED - the dashboard refuses to run while this
// is empty. Pick something long: this page reveals your infrastructure layout.
$monitor_password = "GLMuevuTFue7AO6IZ5L8a4r59uov1mth";

// Shared control-plane secret: must equal $config['cv'] in the main server's
// admin/include/setup.php (the same value configured in every edge's
// remote_control.php). It signs the action=monitor probes and is never sent
// to the browser.
$config['cv'] = "5884a0f77f3943836108c7611e7c021b";

// Seconds allowed for each server probe. Servers are polled in parallel, so a
// full refresh takes about this long in the worst case, not timeout x servers.
$probe_timeout = 5;

// Seconds between automatic dashboard refreshes. Also the averaging window for
// the CPU % and bandwidth figures (they are deltas between two polls).
$poll_interval_seconds = 10;

// Where the list of monitored servers is stored (created automatically).
$servers_file = __DIR__ . '/monitor_servers.dat.php';

// SQLite history database (created automatically). Keep out of public listing
// via nginx deny rules.
$history_db_file = __DIR__ . '/monitor_history.db';

// How long to retain samples (seconds). Default: 7 days.
$history_retention_seconds = 7 * 24 * 3600;

// Points returned for card bandwidth sparklines.
$sparkline_points = 48;

// ----------------------------------------------------------------------------

// Use a private session dir owned by the web user. Avoids failures when leftover
// sessions were created as root (e.g. by `php -S`) and are unreadable by php-fpm.
$session_path = __DIR__ . '/sessions';
if (!is_dir($session_path))
{
	@mkdir($session_path, 0700, true);
}
if (is_dir($session_path) && is_writable($session_path))
{
	session_save_path($session_path);
}
session_name('kvs_edge_monitor');
if (!@session_start())
{
	// Cookie pointed at an unreadable/corrupt session file — start fresh.
	session_id(bin2hex(random_bytes(13)));
	session_start();
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['monitor_csrf']))
{
	$_SESSION['monitor_csrf'] = md5(uniqid(mt_rand(), true) . mt_rand());
}
$csrf = $_SESSION['monitor_csrf'];
$is_authed = ($monitor_password !== '' && !empty($_SESSION['monitor_authed']));

// ------------------------------ storage helpers ------------------------------

// The server list is a JSON array stored behind a "<?php die" first line, so it
// stays unreadable even if this directory is web-served with .php execution.
function monitor_load_servers()
{
	global $servers_file;

	$raw = @file_get_contents($servers_file);
	if ($raw === false)
	{
		return array();
	}
	if (strpos($raw, '<?php') === 0)
	{
		$pos = strpos($raw, "\n");
		$raw = ($pos !== false) ? substr($raw, $pos + 1) : '';
	}
	$list = json_decode($raw, true);
	if (!is_array($list))
	{
		return array();
	}
	// Normalize optional fields added over time.
	foreach ($list as &$s)
	{
		if (!isset($s['location']))
		{
			$s['location'] = '';
		}
		if (!isset($s['ip']))
		{
			$s['ip'] = '';
		}
	}
	unset($s);
	return array_values($list);
}

function monitor_save_servers($list)
{
	global $servers_file;

	$raw = "<?php http_response_code(404); die; ?>\n" . json_encode(array_values($list));
	return @file_put_contents($servers_file, $raw, LOCK_EX) !== false;
}

// Base URL of a server's control script, e.g. "https://1.2.3.4:8443/remote_control.php".
function monitor_server_url($s)
{
	$scheme = ($s['scheme'] === 'https') ? 'https' : 'http';
	$url = "$scheme://$s[host]";
	$port = intval($s['port']);
	if ($port > 0 && !($scheme === 'http' && $port == 80) && !($scheme === 'https' && $port == 443))
	{
		$url .= ":$port";
	}
	$path = (isset($s['path']) && trim($s['path']) !== '') ? trim($s['path']) : '/remote_control.php';
	if ($path[0] !== '/')
	{
		$path = "/$path";
	}
	return $url . $path;
}

function monitor_find_server($id)
{
	foreach (monitor_load_servers() as $s)
	{
		if ($s['id'] === $id)
		{
			return $s;
		}
	}
	return null;
}

// Resolve hostname → IPv4 string; if host is already an IP, return it.
function monitor_resolve_ip($host)
{
	$host = trim((string) $host);
	if ($host === '')
	{
		return '';
	}
	// Strip IPv6 brackets if present.
	if ($host[0] === '[' && substr($host, -1) === ']')
	{
		$host = substr($host, 1, -1);
	}
	if (filter_var($host, FILTER_VALIDATE_IP))
	{
		return $host;
	}
	// Prefer getaddrinfo-style resolution when available.
	if (function_exists('dns_get_record'))
	{
		$recs = @dns_get_record($host, DNS_A);
		if (is_array($recs))
		{
			foreach ($recs as $r)
			{
				if (!empty($r['ip']))
				{
					return $r['ip'];
				}
			}
		}
	}
	$ip = @gethostbyname($host);
	if ($ip && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP))
	{
		return $ip;
	}
	return '';
}

// Display IP: manual override (for hosts behind CDN/proxy DNS) wins over DNS.
function monitor_display_ip($s)
{
	$manual = isset($s['ip']) ? trim((string) $s['ip']) : '';
	if ($manual !== '' && filter_var($manual, FILTER_VALIDATE_IP))
	{
		return $manual;
	}
	return monitor_resolve_ip(isset($s['host']) ? $s['host'] : '');
}

// Validate optional IP field from forms. Empty string is allowed (means: use DNS).
function monitor_normalize_ip_input($raw)
{
	$ip = trim((string) $raw);
	if ($ip === '')
	{
		return array('', null);
	}
	// Strip accidental brackets around IPv6.
	if ($ip[0] === '[' && substr($ip, -1) === ']')
	{
		$ip = substr($ip, 1, -1);
	}
	if (!filter_var($ip, FILTER_VALIDATE_IP))
	{
		return array(null, 'Enter a valid IPv4/IPv6 address, or leave IP empty to use DNS.');
	}
	return array($ip, null);
}

// ------------------------------ SQLite history ------------------------------

function monitor_db()
{
	static $pdo = null;
	global $history_db_file;

	if ($pdo instanceof PDO)
	{
		return $pdo;
	}
	if (!extension_loaded('pdo_sqlite'))
	{
		return null;
	}
	try
	{
		$pdo = new PDO('sqlite:' . $history_db_file, null, null, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		));
		$pdo->exec('PRAGMA journal_mode=WAL');
		$pdo->exec('PRAGMA synchronous=NORMAL');
		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS samples (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				server_id TEXT NOT NULL,
				ts INTEGER NOT NULL,
				status TEXT NOT NULL,
				online INTEGER NOT NULL DEFAULT 0,
				ip TEXT,
				cpu_pct REAL,
				load1 REAL,
				cores INTEGER,
				disk_total REAL,
				disk_free REAL,
				rx_bps REAL,
				tx_bps REAL,
				cache_videos INTEGER,
				cache_bytes REAL,
				views_15m INTEGER,
				views_24h INTEGER
			)'
		);
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_samples_server_ts ON samples(server_id, ts)');
		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS last_probe (
				server_id TEXT PRIMARY KEY,
				ts INTEGER NOT NULL,
				cpu_total REAL,
				cpu_idle REAL,
				rx_bytes REAL,
				tx_bytes REAL
			)'
		);
	}
	catch (Exception $e)
	{
		$pdo = null;
		return null;
	}
	return $pdo;
}

function monitor_db_prune($pdo)
{
	global $history_retention_seconds;
	if (!$pdo)
	{
		return;
	}
	$cutoff = time() - intval($history_retention_seconds);
	try
	{
		$st = $pdo->prepare('DELETE FROM samples WHERE ts < ?');
		$st->execute(array($cutoff));
	}
	catch (Exception $e)
	{
		// ignore prune failures
	}
}

function monitor_last_probe($pdo, $server_id)
{
	if (!$pdo)
	{
		return null;
	}
	$st = $pdo->prepare('SELECT * FROM last_probe WHERE server_id = ?');
	$st->execute(array($server_id));
	$row = $st->fetch();
	return $row ? $row : null;
}

function monitor_save_last_probe($pdo, $server_id, $ts, $cpu, $net)
{
	if (!$pdo)
	{
		return;
	}
	$st = $pdo->prepare(
		'INSERT INTO last_probe (server_id, ts, cpu_total, cpu_idle, rx_bytes, tx_bytes)
		 VALUES (?, ?, ?, ?, ?, ?)
		 ON CONFLICT(server_id) DO UPDATE SET
		   ts=excluded.ts, cpu_total=excluded.cpu_total, cpu_idle=excluded.cpu_idle,
		   rx_bytes=excluded.rx_bytes, tx_bytes=excluded.tx_bytes'
	);
	$st->execute(array(
		$server_id,
		$ts,
		$cpu ? $cpu['total'] : null,
		$cpu ? $cpu['idle'] : null,
		$net ? $net['rx_bytes'] : null,
		$net ? $net['tx_bytes'] : null,
	));
}

function monitor_insert_sample($pdo, $row)
{
	if (!$pdo)
	{
		return;
	}
	$st = $pdo->prepare(
		'INSERT INTO samples
		 (server_id, ts, status, online, ip, cpu_pct, load1, cores, disk_total, disk_free,
		  rx_bps, tx_bps, cache_videos, cache_bytes, views_15m, views_24h)
		 VALUES
		 (:server_id, :ts, :status, :online, :ip, :cpu_pct, :load1, :cores, :disk_total, :disk_free,
		  :rx_bps, :tx_bps, :cache_videos, :cache_bytes, :views_15m, :views_24h)'
	);
	$st->execute($row);
}

function monitor_history_sparks($pdo, $server_ids, $limit)
{
	$out = array();
	if (!$pdo || !count($server_ids))
	{
		return $out;
	}
	$limit = max(5, intval($limit));
	$st = $pdo->prepare(
		'SELECT ts, rx_bps, tx_bps, online, cpu_pct FROM samples
		 WHERE server_id = ? ORDER BY ts DESC LIMIT ' . $limit
	);
	foreach ($server_ids as $id)
	{
		$st->execute(array($id));
		$rows = $st->fetchAll();
		$rows = array_reverse($rows);
		$out[$id] = array(
			'ts' => array(),
			'rx' => array(),
			'tx' => array(),
			'online' => array(),
			'cpu' => array(),
		);
		foreach ($rows as $r)
		{
			$out[$id]['ts'][] = intval($r['ts']);
			$out[$id]['rx'][] = $r['rx_bps'] === null ? null : floatval($r['rx_bps']);
			$out[$id]['tx'][] = $r['tx_bps'] === null ? null : floatval($r['tx_bps']);
			$out[$id]['online'][] = intval($r['online']);
			$out[$id]['cpu'][] = $r['cpu_pct'] === null ? null : floatval($r['cpu_pct']);
		}
	}
	return $out;
}

function monitor_history_range($pdo, $server_id, $range)
{
	$now = time();
	$map = array(
		'1h'  => 3600,
		'6h'  => 6 * 3600,
		'24h' => 24 * 3600,
		'7d'  => 7 * 24 * 3600,
	);
	$sec = isset($map[$range]) ? $map[$range] : $map['24h'];
	$since = $now - $sec;

	// Downsample for longer ranges so the chart stays responsive.
	// Target ~400 points max.
	$interval = 1;
	if ($sec > 6 * 3600)
	{
		$interval = 60; // 1 min
	}
	if ($sec > 24 * 3600)
	{
		$interval = 300; // 5 min
	}

	if (!$pdo)
	{
		return array('samples' => array(), 'uptime' => array());
	}

	if ($interval <= 1)
	{
		$st = $pdo->prepare(
			'SELECT ts, status, online, ip, cpu_pct, load1, cores, disk_total, disk_free,
			        rx_bps, tx_bps, cache_videos, cache_bytes, views_15m, views_24h
			 FROM samples WHERE server_id = ? AND ts >= ? ORDER BY ts ASC'
		);
		$st->execute(array($server_id, $since));
		$samples = $st->fetchAll();
	}
	else
	{
		// Bucket averages for continuous metrics; max online in bucket for uptime.
		$st = $pdo->prepare(
			'SELECT
				(ts / :bucket) * :bucket AS ts,
				MAX(online) AS online,
				AVG(cpu_pct) AS cpu_pct,
				AVG(load1) AS load1,
				AVG(cores) AS cores,
				AVG(disk_total) AS disk_total,
				AVG(disk_free) AS disk_free,
				AVG(rx_bps) AS rx_bps,
				AVG(tx_bps) AS tx_bps,
				AVG(cache_videos) AS cache_videos,
				AVG(cache_bytes) AS cache_bytes,
				AVG(views_15m) AS views_15m,
				AVG(views_24h) AS views_24h,
				MAX(status) AS status,
				MAX(ip) AS ip
			 FROM samples
			 WHERE server_id = :sid AND ts >= :since
			 GROUP BY (ts / :bucket)
			 ORDER BY ts ASC'
		);
		$st->execute(array(
			':bucket' => $interval,
			':sid' => $server_id,
			':since' => $since,
		));
		$samples = $st->fetchAll();
	}

	// Uptime windows (always from full resolution samples).
	$uptime = array();
	foreach (array('1h' => 3600, '24h' => 86400, '7d' => 7 * 86400) as $label => $win)
	{
		$st = $pdo->prepare(
			'SELECT COUNT(*) AS n, COALESCE(SUM(online),0) AS up
			 FROM samples WHERE server_id = ? AND ts >= ?'
		);
		$st->execute(array($server_id, $now - $win));
		$row = $st->fetch();
		$n = intval($row['n']);
		$up = intval($row['up']);
		$uptime[$label] = array(
			'samples' => $n,
			'up' => $up,
			'pct' => $n > 0 ? round(100.0 * $up / $n, 2) : null,
		);
	}

	// Latest status stretch (how long current online/offline has lasted).
	$st = $pdo->prepare(
		'SELECT ts, online FROM samples WHERE server_id = ? ORDER BY ts DESC LIMIT 500'
	);
	$st->execute(array($server_id));
	$recent = $st->fetchAll();
	$current_online = null;
	$since_ts = null;
	foreach ($recent as $r)
	{
		$on = intval($r['online']);
		if ($current_online === null)
		{
			$current_online = $on;
			$since_ts = intval($r['ts']);
			continue;
		}
		if ($on !== $current_online)
		{
			break;
		}
		$since_ts = intval($r['ts']);
	}

	return array(
		'samples' => $samples,
		'uptime' => $uptime,
		'current_online' => $current_online,
		'status_since' => $since_ts,
		'range' => $range,
		'since' => $since,
		'now' => $now,
		'bucket' => $interval,
	);
}

// ------------------------------ HTTP probing ------------------------------

// GET one URL; returns array(http, body, error, headers => lowercased name => value).
function monitor_http_get($url, $timeout)
{
	if (function_exists('curl_init'))
	{
		$ch = curl_init();
		$resp_headers = array();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$resp_headers)
		{
			$len = strlen($line);
			$parts = explode(':', $line, 2);
			if (count($parts) === 2)
			{
				$name = strtolower(trim($parts[0]));
				$resp_headers[$name] = trim($parts[1]);
			}
			return $len;
		});
		$body = curl_exec($ch);
		$res = array(
			'http'    => intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)),
			'body'    => ($body === false) ? '' : (string) $body,
			'error'   => (string) curl_error($ch),
			'headers' => $resp_headers,
		);
		curl_close($ch);
		return $res;
	}

	if (ini_get('allow_url_fopen'))
	{
		$context = stream_context_create(array(
			'http' => array(
				'method' => 'GET',
				'timeout' => $timeout,
				'ignore_errors' => true,
				'follow_location' => 1,
				'max_redirects' => 3,
				'header' => "Connection: close\r\n",
			),
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false,
			),
		));
		$body = @file_get_contents($url, false, $context);
		$http = 0;
		$resp_headers = array();
		if (isset($http_response_header) && is_array($http_response_header))
		{
			foreach ($http_response_header as $line)
			{
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m))
				{
					$http = intval($m[1]);
					continue;
				}
				$parts = explode(':', $line, 2);
				if (count($parts) === 2)
				{
					$resp_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
			}
		}
		return array(
			'http'    => $http,
			'body'    => ($body === false) ? '' : (string) $body,
			'error'   => ($body === false && $http == 0) ? 'connection failed' : '',
			'headers' => $resp_headers,
		);
	}

	return array('http' => 0, 'body' => '', 'error' => 'no HTTP transport on the dashboard host (enable curl or allow_url_fopen)', 'headers' => array());
}

// GET "<control url><query>" for every server concurrently (curl_multi when
// available, sequential otherwise). Returns a map: server id => result array
// in monitor_http_get()'s format.
function monitor_fetch_all($servers, $query, $timeout)
{
	$results = array();
	if (!count($servers))
	{
		return $results;
	}

	if (function_exists('curl_multi_init') && function_exists('curl_init'))
	{
		$mh = curl_multi_init();
		$handles = array();
		foreach ($servers as $s)
		{
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, monitor_server_url($s) . $query);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			curl_multi_add_handle($mh, $ch);
			$handles[$s['id']] = $ch;
		}

		$running = null;
		do
		{
			$status = curl_multi_exec($mh, $running);
			if ($running && curl_multi_select($mh, 0.2) === -1)
			{
				usleep(100000);
			}
		} while ($running > 0 && $status == CURLM_OK);

		foreach ($handles as $id => $ch)
		{
			$body = curl_multi_getcontent($ch);
			$results[$id] = array(
				'http'  => intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)),
				'body'  => ($body === null) ? '' : (string) $body,
				'error' => (string) curl_error($ch),
			);
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);
		return $results;
	}

	foreach ($servers as $s)
	{
		$results[$s['id']] = monitor_http_get(monitor_server_url($s) . $query, $timeout);
	}
	return $results;
}

// Interpret an action=monitor probe: returns array(status, stats-or-null).
// status: edge      - edge API answered, pull-through caching enabled
//         online    - edge API answered, but no origin configured
//         no_api    - HTTP answered, but not the edge build (stock KVS script)
//         auth      - 403: the cv here does not match the server's cv
//         offline   - no/unusable HTTP response
function monitor_classify($res)
{
	if ($res['http'] == 403)
	{
		return array('auth', null);
	}
	if ($res['http'] == 0)
	{
		return array('offline', null);
	}
	$stats = json_decode($res['body'], true);
	if (is_array($stats) && !empty($stats['ok']))
	{
		return array(!empty($stats['edge']) ? 'edge' : 'online', $stats);
	}
	if ($res['http'] >= 200 && $res['http'] < 400)
	{
		return array('no_api', null);
	}
	return array('offline', null);
}

function monitor_is_online_status($status)
{
	return in_array($status, array('edge', 'online', 'no_api'), true);
}

// ------------------------------ request routing ------------------------------

// Refuse to run without a password: this page maps your whole delivery network.
if ($monitor_password === '')
{
	http_response_code(503);
	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><html><head><title>Edge monitor - setup required</title></head>'
		. '<body style="font-family:sans-serif;background:#111;color:#eee;padding:40px">'
		. '<h2>Setup required</h2>'
		. '<p>Open <code>' . htmlspecialchars(basename(__FILE__)) . '</code> and set <code>$monitor_password</code> '
		. '(and verify <code>$config[\'cv\']</code> matches your main server). The dashboard stays disabled until then.</p>'
		. '</body></html>';
	die;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
	$action = isset($_POST['action']) ? $_POST['action'] : '';
	$csrf_ok = isset($_POST['csrf']) && hash_equals($csrf, (string) $_POST['csrf']);
	$is_ajax = (isset($_POST['ajax']) && $_POST['ajax'] == '1');
	$redirect_err = '';
	$redirect_to = strtok($_SERVER['REQUEST_URI'], '?');

	if ($action === 'login')
	{
		if (!$csrf_ok)
		{
			$redirect_err = 'Session expired - refresh the page and try again.';
		} elseif (hash_equals($monitor_password, (string) $_POST['password']))
		{
			session_regenerate_id(true);
			$_SESSION['monitor_authed'] = true;
			$_SESSION['monitor_csrf'] = md5(uniqid(mt_rand(), true) . mt_rand());
		} else
		{
			sleep(1);
			$redirect_err = 'Wrong password.';
		}
	} elseif (!$is_authed || !$csrf_ok)
	{
		if ($is_ajax)
		{
			http_response_code(401);
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('ok' => false, 'error' => 'not authorized'));
			die;
		}
		$redirect_err = 'Session expired - please log in again.';
	} elseif ($action === 'logout')
	{
		$_SESSION = array();
		session_destroy();
	} elseif ($action === 'add')
	{
		list($manual_ip, $ip_err) = monitor_normalize_ip_input(isset($_POST['ip']) ? $_POST['ip'] : '');
		$server = array(
			'id'       => substr(md5(uniqid(mt_rand(), true)), 0, 10),
			'name'     => trim((string) $_POST['name']),
			'scheme'   => ($_POST['scheme'] === 'https') ? 'https' : 'http',
			'host'     => trim((string) $_POST['host']),
			'port'     => trim((string) $_POST['port']),
			'path'     => trim((string) $_POST['path']),
			'location' => trim((string) (isset($_POST['location']) ? $_POST['location'] : '')),
			'ip'       => $manual_ip === null ? '' : $manual_ip,
		);
		if (strlen($server['location']) > 120)
		{
			$server['location'] = substr($server['location'], 0, 120);
		}
		if ($ip_err !== null)
		{
			$redirect_err = $ip_err;
		} elseif ($server['host'] === '' || !preg_match('#^[A-Za-z0-9.\-\[\]:]+$#', $server['host']))
		{
			$redirect_err = 'Enter a valid IP or hostname.';
		} elseif ($server['port'] !== '' && (!ctype_digit($server['port']) || intval($server['port']) < 1 || intval($server['port']) > 65535))
		{
			$redirect_err = 'Port must be 1-65535 (or empty for the scheme default).';
		} elseif ($server['path'] !== '' && !preg_match('#^/?[A-Za-z0-9._\-/]+$#', $server['path']))
		{
			$redirect_err = 'Control script path may only contain letters, digits, dots, dashes and slashes.';
		} else
		{
			$server['port'] = ($server['port'] === '') ? 0 : intval($server['port']);
			if ($server['path'] === '')
			{
				$server['path'] = '/remote_control.php';
			}
			if ($server['name'] === '')
			{
				$server['name'] = $server['host'] . ($server['port'] > 0 ? ":$server[port]" : '');
			}
			$servers = monitor_load_servers();
			$duplicate = false;
			foreach ($servers as $s)
			{
				if (monitor_server_url($s) === monitor_server_url($server))
				{
					$duplicate = true;
					break;
				}
			}
			if ($duplicate)
			{
				$redirect_err = 'That server is already on the dashboard.';
			} else
			{
				$servers[] = $server;
				if (!monitor_save_servers($servers))
				{
					$redirect_err = 'Could not write ' . basename($GLOBALS['servers_file']) . ' - make this directory writable for the web user.';
				}
			}
		}
	} elseif ($action === 'update')
	{
		$id = (string) $_POST['id'];
		$name = trim((string) $_POST['name']);
		$location = trim((string) (isset($_POST['location']) ? $_POST['location'] : ''));
		list($manual_ip, $ip_err) = monitor_normalize_ip_input(isset($_POST['ip']) ? $_POST['ip'] : '');
		if (strlen($location) > 120)
		{
			$location = substr($location, 0, 120);
		}
		if ($ip_err !== null)
		{
			$redirect_err = $ip_err;
		}
		else
		{
			$servers = monitor_load_servers();
			$found = false;
			foreach ($servers as &$s)
			{
				if ($s['id'] === $id)
				{
					if ($name !== '')
					{
						$s['name'] = $name;
					}
					$s['location'] = $location;
					$s['ip'] = $manual_ip;
					$found = true;
					break;
				}
			}
			unset($s);
			if (!$found)
			{
				$redirect_err = 'Server not found.';
			} elseif (!monitor_save_servers($servers))
			{
				$redirect_err = 'Could not write the server list file.';
			}
		}
		$redirect_to = strtok($_SERVER['REQUEST_URI'], '?') . '?server=' . rawurlencode($id);
		if ($is_ajax)
		{
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('ok' => ($redirect_err === ''), 'error' => $redirect_err));
			die;
		}
	} elseif ($action === 'delete')
	{
		$id = (string) $_POST['id'];
		$servers = array();
		foreach (monitor_load_servers() as $s)
		{
			if ($s['id'] !== $id)
			{
				$servers[] = $s;
			}
		}
		if (!monitor_save_servers($servers))
		{
			$redirect_err = 'Could not write the server list file.';
		}
		if ($is_ajax)
		{
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode(array('ok' => ($redirect_err === ''), 'error' => $redirect_err));
			die;
		}
	}

	// POST -> redirect -> GET, so refreshing the page never repeats an action
	header('Location: ' . $redirect_to . ($redirect_err !== '' ? ((strpos($redirect_to, '?') !== false ? '&' : '?') . 'err=' . rawurlencode($redirect_err)) : ''));
	die;
}

// AJAX: poll all servers and return one combined JSON snapshot.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll')
{
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	if (!$is_authed)
	{
		http_response_code(401);
		echo json_encode(array('ok' => false, 'error' => 'not authorized'));
		die;
	}

	$servers = monitor_load_servers();
	$results = monitor_fetch_all($servers, '?action=monitor&cv=' . rawurlencode($config['cv']), $probe_timeout);
	$pdo = monitor_db();
	$now = time();

	$out = array();
	$need_basic = array();
	foreach ($servers as $s)
	{
		$res = isset($results[$s['id']]) ? $results[$s['id']] : array('http' => 0, 'body' => '', 'error' => 'no result');
		list($status, $stats) = monitor_classify($res);
		$ip = monitor_display_ip($s);
		$out[$s['id']] = array(
			'id'       => $s['id'],
			'name'     => $s['name'],
			'url'      => monitor_server_url($s),
			'host'     => $s['host'],
			'location' => isset($s['location']) ? $s['location'] : '',
			'ip'       => $ip,
			'ip_manual'=> (isset($s['ip']) && trim((string) $s['ip']) !== ''),
			'status'   => $status,
			'error'    => trim($res['error']),
			'stats'    => $stats,
			'basic'    => null,
			'cpu_pct'  => null,
			'rates'    => null,
		);
		if ($status === 'no_api')
		{
			$need_basic[] = $s;
		}
	}

	// Stock KVS control scripts still answer action=status with "load|total|free",
	// so servers without the edge API at least report load + storage.
	if (count($need_basic))
	{
		$basic_results = monitor_fetch_all($need_basic, '?action=status', $probe_timeout);
		foreach ($need_basic as $s)
		{
			$res = isset($basic_results[$s['id']]) ? $basic_results[$s['id']] : null;
			if ($res && $res['http'] >= 200 && $res['http'] < 300)
			{
				$p = explode('|', trim($res['body']));
				if (count($p) >= 3 && is_numeric($p[0]) && is_numeric($p[1]) && is_numeric($p[2]))
				{
					$out[$s['id']]['basic'] = array(
						'load' => floatval($p[0]),
						'disk' => array('total' => floatval($p[1]), 'free' => floatval($p[2])),
					);
				}
			}
		}
	}

	// Compute rates server-side, persist samples, update last_probe.
	if ($pdo)
	{
		try
		{
			$pdo->beginTransaction();
		}
		catch (Exception $e)
		{
		}

		foreach ($servers as $s)
		{
			$row = $out[$s['id']];
			$stats = $row['stats'];
			$cpu_pct = null;
			$rx_bps = null;
			$tx_bps = null;
			$load1 = null;
			$cores = null;
			$disk_total = null;
			$disk_free = null;
			$cache_videos = null;
			$cache_bytes = null;
			$views_15m = null;
			$views_24h = null;
			$probe_ts = $now;
			$cpu = null;
			$net = null;

			if ($stats)
			{
				$probe_ts = !empty($stats['time']) ? intval($stats['time']) : $now;
				$cpu = isset($stats['cpu']) ? $stats['cpu'] : null;
				$net = isset($stats['net']) ? $stats['net'] : null;
				if (isset($stats['loadavg'][0]))
				{
					$load1 = floatval($stats['loadavg'][0]);
				}
				if (isset($stats['cores']))
				{
					$cores = intval($stats['cores']);
				}
				if (isset($stats['disk']))
				{
					$disk_total = floatval($stats['disk']['total']);
					$disk_free = floatval($stats['disk']['free']);
				}
				if (isset($stats['cache']))
				{
					$cache_videos = isset($stats['cache']['videos']) ? intval($stats['cache']['videos']) : null;
					$cache_bytes = isset($stats['cache']['bytes']) ? floatval($stats['cache']['bytes']) : null;
				}
				if (isset($stats['views_15m']))
				{
					$views_15m = intval($stats['views_15m']);
				}
				if (isset($stats['views_24h']))
				{
					$views_24h = intval($stats['views_24h']);
				}

				$prev = monitor_last_probe($pdo, $s['id']);
				if ($prev && $cpu && $prev['cpu_total'] !== null)
				{
					$dt = floatval($cpu['total']) - floatval($prev['cpu_total']);
					$di = floatval($cpu['idle']) - floatval($prev['cpu_idle']);
					if ($dt > 0 && $di >= 0 && $di <= $dt)
					{
						$cpu_pct = 100.0 * (1.0 - $di / $dt);
					}
				}
				if ($prev && $net && $prev['rx_bytes'] !== null && $prev['ts'])
				{
					$dt = $probe_ts - intval($prev['ts']);
					if ($dt > 0)
					{
						$rx = (floatval($net['rx_bytes']) - floatval($prev['rx_bytes'])) / $dt;
						$tx = (floatval($net['tx_bytes']) - floatval($prev['tx_bytes'])) / $dt;
						if ($rx >= 0 && $tx >= 0)
						{
							$rx_bps = $rx;
							$tx_bps = $tx;
						}
					}
				}
				monitor_save_last_probe($pdo, $s['id'], $probe_ts, $cpu, $net);
			}
			elseif ($row['basic'])
			{
				$load1 = isset($row['basic']['load']) ? floatval($row['basic']['load']) : null;
				if (isset($row['basic']['disk']))
				{
					$disk_total = floatval($row['basic']['disk']['total']);
					$disk_free = floatval($row['basic']['disk']['free']);
				}
			}

			$out[$s['id']]['cpu_pct'] = $cpu_pct;
			$out[$s['id']]['rates'] = ($rx_bps !== null) ? array('rx' => $rx_bps, 'tx' => $tx_bps) : null;

			monitor_insert_sample($pdo, array(
				':server_id'    => $s['id'],
				':ts'           => $now,
				':status'       => $row['status'],
				':online'       => monitor_is_online_status($row['status']) ? 1 : 0,
				':ip'           => $row['ip'],
				':cpu_pct'      => $cpu_pct,
				':load1'        => $load1,
				':cores'        => $cores,
				':disk_total'   => $disk_total,
				':disk_free'    => $disk_free,
				':rx_bps'       => $rx_bps,
				':tx_bps'       => $tx_bps,
				':cache_videos' => $cache_videos,
				':cache_bytes'  => $cache_bytes,
				':views_15m'    => $views_15m,
				':views_24h'    => $views_24h,
			));
		}

		try
		{
			if ($pdo->inTransaction())
			{
				$pdo->commit();
			}
		}
		catch (Exception $e)
		{
		}

		// Prune occasionally (every ~30 polls by chance, or always if cheap).
		if (mt_rand(1, 20) === 1)
		{
			monitor_db_prune($pdo);
		}

		$sparks = monitor_history_sparks($pdo, array_keys($out), $sparkline_points);
		foreach ($out as $id => &$row)
		{
			$row['spark'] = isset($sparks[$id]) ? $sparks[$id] : null;
		}
		unset($row);
	}
	else
	{
		foreach ($out as &$row)
		{
			$row['spark'] = null;
		}
		unset($row);
	}

	echo json_encode(array(
		'ok' => true,
		'time' => $now,
		'history' => $pdo ? true : false,
		'servers' => array_values($out),
	));
	die;
}

// AJAX: historical series for a single server (detail page).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history')
{
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	if (!$is_authed)
	{
		http_response_code(401);
		echo json_encode(array('ok' => false, 'error' => 'not authorized'));
		die;
	}
	$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
	$range = isset($_GET['range']) ? (string) $_GET['range'] : '24h';
	$srv = monitor_find_server($id);
	if (!$srv)
	{
		http_response_code(404);
		echo json_encode(array('ok' => false, 'error' => 'unknown server'));
		die;
	}
	$pdo = monitor_db();
	$hist = monitor_history_range($pdo, $id, $range);
	echo json_encode(array(
		'ok' => true,
		'server' => array(
			'id' => $srv['id'],
			'name' => $srv['name'],
			'url' => monitor_server_url($srv),
			'host' => $srv['host'],
			'location' => isset($srv['location']) ? $srv['location'] : '',
			'ip' => monitor_display_ip($srv),
			'ip_manual' => (isset($srv['ip']) && trim((string) $srv['ip']) !== ''),
		),
		'history' => $hist,
	));
	die;
}

// AJAX / download: monitoring history export from this host's SQLite DB
// (no call to the CDN edge). Supports:
//   meta=1            — JSON sample count / time range
//   lines=N&view=1    — last N samples as plain-text status log (inline)
//   range=1h|6h|24h|7d|all  — window for download (default 7d)
//   format=csv|txt    — download format (default csv)
//   download default  — Content-Disposition attachment
if (isset($_GET['ajax']) && ($_GET['ajax'] === 'statuslog' || $_GET['ajax'] === 'cdnlog'))
{
	// ajax=cdnlog kept as alias so old bookmarks still hit history export.
	if (!$is_authed)
	{
		http_response_code(401);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array('ok' => false, 'error' => 'not authorized'));
		die;
	}

	$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
	$srv = monitor_find_server($id);
	if (!$srv)
	{
		http_response_code(404);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array('ok' => false, 'error' => 'unknown server'));
		die;
	}

	$pdo = monitor_db();
	if (!$pdo)
	{
		http_response_code(503);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array('ok' => false, 'error' => 'history database unavailable (pdo_sqlite)'));
		die;
	}

	$meta = isset($_GET['meta']) && (string) $_GET['meta'] === '1';
	$lines = isset($_GET['lines']) ? intval($_GET['lines']) : 0;
	if ($lines < 0)
	{
		$lines = 0;
	}
	if ($lines > 50000)
	{
		$lines = 50000;
	}
	$view = isset($_GET['view']) && (string) $_GET['view'] === '1';
	$range = isset($_GET['range']) ? (string) $_GET['range'] : '7d';
	$format = isset($_GET['format']) ? strtolower((string) $_GET['format']) : 'csv';
	if ($format !== 'txt' && $format !== 'text' && $format !== 'csv')
	{
		$format = 'csv';
	}
	if ($format === 'text')
	{
		$format = 'txt';
	}

	$range_map = array(
		'1h'  => 3600,
		'6h'  => 6 * 3600,
		'24h' => 24 * 3600,
		'7d'  => 7 * 24 * 3600,
		'all' => 0,
	);
	if (!isset($range_map[$range]))
	{
		$range = '7d';
	}
	$since = $range_map[$range] > 0 ? (time() - $range_map[$range]) : 0;

	// ---- meta ----
	if ($meta)
	{
		$st = $pdo->prepare(
			'SELECT COUNT(*) AS n, MIN(ts) AS oldest, MAX(ts) AS newest
			 FROM samples WHERE server_id = ?'
		);
		$st->execute(array($id));
		$row = $st->fetch();
		$n = $row ? intval($row['n']) : 0;
		$oldest = ($row && $row['oldest'] !== null) ? intval($row['oldest']) : null;
		$newest = ($row && $row['newest'] !== null) ? intval($row['newest']) : null;

		$st2 = $pdo->prepare(
			'SELECT COUNT(*) AS n FROM samples WHERE server_id = ? AND ts >= ?'
		);
		$st2->execute(array($id, $since > 0 ? $since : 0));
		$row2 = $st2->fetch();
		$n_range = $row2 ? intval($row2['n']) : 0;

		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array(
			'ok'           => true,
			'source'       => 'monitor_history',
			'server_id'    => $id,
			'server_name'  => $srv['name'],
			'samples'      => $n,
			'samples_7d'   => $n_range,
			'oldest'       => $oldest,
			'newest'       => $newest,
			'range'        => $range,
			'retention_s'  => intval($history_retention_seconds),
		));
		die;
	}

	// ---- fetch rows ----
	// Tail (lines>0): newest N samples, then reverse for chronological display.
	// Full export: all samples in range ascending (capped for safety).
	$max_export = 100000;
	if ($lines > 0)
	{
		$st = $pdo->prepare(
			'SELECT ts, status, online, ip, cpu_pct, load1, cores, disk_total, disk_free,
			        rx_bps, tx_bps, cache_videos, cache_bytes, views_15m, views_24h
			 FROM samples WHERE server_id = ? ORDER BY ts DESC LIMIT ' . intval($lines)
		);
		$st->execute(array($id));
		$samples = array_reverse($st->fetchAll());
	}
	else
	{
		if ($since > 0)
		{
			$st = $pdo->prepare(
				'SELECT ts, status, online, ip, cpu_pct, load1, cores, disk_total, disk_free,
				        rx_bps, tx_bps, cache_videos, cache_bytes, views_15m, views_24h
				 FROM samples WHERE server_id = ? AND ts >= ? ORDER BY ts ASC LIMIT ' . $max_export
			);
			$st->execute(array($id, $since));
		}
		else
		{
			$st = $pdo->prepare(
				'SELECT ts, status, online, ip, cpu_pct, load1, cores, disk_total, disk_free,
				        rx_bps, tx_bps, cache_videos, cache_bytes, views_15m, views_24h
				 FROM samples WHERE server_id = ? ORDER BY ts ASC LIMIT ' . $max_export
			);
			$st->execute(array($id));
		}
		$samples = $st->fetchAll();
	}

	$safe_name = preg_replace('/[^A-Za-z0-9._\-]+/', '_', (string) $srv['name']);
	if ($safe_name === '' || $safe_name === '_')
	{
		$safe_name = 'server-' . preg_replace('/[^A-Za-z0-9]+/', '', $id);
	}

	// Human-readable status lines (view tail or format=txt download).
	$fmt_num = function ($v, $digits = 2)
	{
		if ($v === null || $v === '')
		{
			return '-';
		}
		return is_numeric($v) ? number_format((float) $v, $digits, '.', '') : (string) $v;
	};
	$fmt_bytes = function ($v)
	{
		if ($v === null || $v === '' || !is_numeric($v))
		{
			return '-';
		}
		$b = (float) $v;
		$u = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
		$i = 0;
		while ($b >= 1024 && $i < count($u) - 1)
		{
			$b /= 1024;
			$i++;
		}
		return ($i === 0 ? (string) intval($b) : number_format($b, 2, '.', '')) . $u[$i];
	};
	// bytes/sec → bits/sec (SI 1000), e.g. 2.6 Gbps not 325 MB/s
	$fmt_rate = function ($v)
	{
		if ($v === null || $v === '' || !is_numeric($v) || (float) $v < 0)
		{
			return '-';
		}
		$bits = (float) $v * 8;
		$u = array('bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps');
		$i = 0;
		while ($bits >= 1000 && $i < count($u) - 1)
		{
			$bits /= 1000;
			$i++;
		}
		if ($i === 0)
		{
			return intval(round($bits)) . ' ' . $u[$i];
		}
		$digits = ($bits >= 100) ? 0 : (($bits >= 10) ? 1 : 2);
		return number_format($bits, $digits, '.', '') . ' ' . $u[$i];
	};

	$to_text_line = function ($r) use ($fmt_num, $fmt_bytes, $fmt_rate)
	{
		$ts = intval($r['ts']);
		$when = gmdate('Y-m-d H:i:s', $ts) . 'Z';
		$online = intval($r['online']) ? 'up' : 'down';
		$status = isset($r['status']) ? (string) $r['status'] : '-';
		$ip = isset($r['ip']) && $r['ip'] !== null && $r['ip'] !== '' ? (string) $r['ip'] : '-';
		return $when
			. '  status=' . $status
			. ' online=' . $online
			. ' ip=' . $ip
			. ' cpu=' . $fmt_num(isset($r['cpu_pct']) ? $r['cpu_pct'] : null, 1) . '%'
			. ' load=' . $fmt_num(isset($r['load1']) ? $r['load1'] : null, 2)
			. ' cores=' . $fmt_num(isset($r['cores']) ? $r['cores'] : null, 0)
			. ' disk_free=' . $fmt_bytes(isset($r['disk_free']) ? $r['disk_free'] : null)
			. ' disk_total=' . $fmt_bytes(isset($r['disk_total']) ? $r['disk_total'] : null)
			. ' rx=' . $fmt_rate(isset($r['rx_bps']) ? $r['rx_bps'] : null)
			. ' tx=' . $fmt_rate(isset($r['tx_bps']) ? $r['tx_bps'] : null)
			. ' cache_videos=' . $fmt_num(isset($r['cache_videos']) ? $r['cache_videos'] : null, 0)
			. ' cache=' . $fmt_bytes(isset($r['cache_bytes']) ? $r['cache_bytes'] : null)
			. ' plays_15m=' . $fmt_num(isset($r['views_15m']) ? $r['views_15m'] : null, 0)
			. ' plays_24h=' . $fmt_num(isset($r['views_24h']) ? $r['views_24h'] : null, 0);
	};

	// Inline tail viewer always returns text.
	if ($view || $format === 'txt')
	{
		$body = '';
		foreach ($samples as $r)
		{
			$body .= $to_text_line($r) . "\n";
		}
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Status-Log-Samples: ' . count($samples));
		header('X-Status-Log-Source: monitor_history');
		if (!$view)
		{
			header('Content-Disposition: attachment; filename="' . $safe_name . '-status-' . $range . '.log"');
		}
		echo $body;
		die;
	}

	// CSV download
	$fh = fopen('php://temp', 'r+');
	$headers = array(
		'ts', 'time_utc', 'status', 'online', 'ip', 'cpu_pct', 'load1', 'cores',
		'disk_total', 'disk_free', 'rx_bps', 'tx_bps', 'cache_videos', 'cache_bytes',
		'views_15m', 'views_24h',
	);
	fputcsv($fh, $headers);
	foreach ($samples as $r)
	{
		$ts = intval($r['ts']);
		fputcsv($fh, array(
			$ts,
			gmdate('Y-m-d\TH:i:s\Z', $ts),
			isset($r['status']) ? $r['status'] : '',
			isset($r['online']) ? intval($r['online']) : 0,
			isset($r['ip']) ? $r['ip'] : '',
			isset($r['cpu_pct']) ? $r['cpu_pct'] : '',
			isset($r['load1']) ? $r['load1'] : '',
			isset($r['cores']) ? $r['cores'] : '',
			isset($r['disk_total']) ? $r['disk_total'] : '',
			isset($r['disk_free']) ? $r['disk_free'] : '',
			isset($r['rx_bps']) ? $r['rx_bps'] : '',
			isset($r['tx_bps']) ? $r['tx_bps'] : '',
			isset($r['cache_videos']) ? $r['cache_videos'] : '',
			isset($r['cache_bytes']) ? $r['cache_bytes'] : '',
			isset($r['views_15m']) ? $r['views_15m'] : '',
			isset($r['views_24h']) ? $r['views_24h'] : '',
		));
	}
	rewind($fh);
	$csv = stream_get_contents($fh);
	fclose($fh);

	header('Content-Type: text/csv; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Status-Log-Samples: ' . count($samples));
	header('X-Status-Log-Source: monitor_history');
	header('Content-Disposition: attachment; filename="' . $safe_name . '-status-' . $range . '.csv"');
	echo $csv;
	die;
}

// ------------------------------ HTML ------------------------------

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$err = isset($_GET['err']) ? (string) $_GET['err'] : '';
$detail_id = isset($_GET['server']) ? (string) $_GET['server'] : '';
$detail_server = ($detail_id !== '' && $is_authed) ? monitor_find_server($detail_id) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $detail_server ? htmlspecialchars($detail_server['name']) . ' · ' : ''; ?>Edge server monitor</title>
<style>
	:root {
		--bg: #0a0d12;
		--bg-elev: #11161e;
		--bg-soft: #0e131a;
		--bg-metric: #0c1016;
		--line: #222a36;
		--line-soft: #1a212c;
		--text: #e6ebf2;
		--muted: #8b95a5;
		--muted-2: #667081;
		--blue: #4f9cf0;
		--blue-deep: #1f6fd1;
		--green: #3ecf8e;
		--amber: #e8b84a;
		--red: #f07178;
		--radius: 14px;
		--shadow: 0 10px 30px rgba(0,0,0,.28);
		--max: 1320px;
	}
	* { box-sizing: border-box; margin: 0; padding: 0; }
	body {
		font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Arial, sans-serif;
		background:
			radial-gradient(1200px 500px at 10% -10%, rgba(47,108,180,.16), transparent 55%),
			radial-gradient(900px 400px at 100% 0%, rgba(36,120,90,.10), transparent 45%),
			var(--bg);
		color: var(--text);
		min-height: 100vh;
	}
	a { color: var(--blue); text-decoration: none; }
	a:hover { text-decoration: underline; }
	.muted { color: var(--muted); }
	.small { font-size: 12px; }
	.sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

	.shell { max-width: var(--max); margin: 0 auto; padding: 20px 22px 36px; }
	.shell.wide { max-width: 1440px; }

	.topbar {
		position: sticky;
		top: 0;
		z-index: 50;
		display: flex;
		align-items: center;
		gap: 16px;
		flex-wrap: wrap;
		margin: -20px -22px 20px;
		padding: 14px 22px;
		background: rgba(10, 13, 18, 0.82);
		backdrop-filter: blur(12px);
		border-bottom: 1px solid rgba(34, 42, 54, 0.9);
	}
	.brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
	.brand-mark {
		width: 36px; height: 36px; border-radius: 10px;
		background: linear-gradient(145deg, #2f7ad1, #1a4f8f);
		box-shadow: 0 0 0 1px rgba(255,255,255,.08) inset, 0 8px 18px rgba(31,111,209,.28);
		display: grid; place-items: center;
		font-weight: 800; font-size: 13px; color: #fff; letter-spacing: .04em;
		flex-shrink: 0;
	}
	.brand h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; }
	.brand .meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
	.live-dot {
		width: 7px; height: 7px; border-radius: 50%;
		background: var(--green);
		box-shadow: 0 0 0 0 rgba(62,207,142,.55);
		animation: pulse 2s infinite;
		display: inline-block;
	}
	.live-dot.err { background: var(--red); box-shadow: none; animation: none; }
	@keyframes pulse {
		0% { box-shadow: 0 0 0 0 rgba(62,207,142,.45); }
		70% { box-shadow: 0 0 0 8px rgba(62,207,142,0); }
		100% { box-shadow: 0 0 0 0 rgba(62,207,142,0); }
	}
	.pill {
		display: inline-flex; align-items: center; gap: 6px;
		padding: 3px 9px; border-radius: 999px;
		background: rgba(255,255,255,.03);
		border: 1px solid var(--line);
		color: var(--muted);
		font-size: 12px;
	}
	.topbar-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; }

	.banner {
		background: rgba(120, 36, 48, .35);
		border: 1px solid rgba(240, 113, 120, .35);
		color: #ffd5d8;
		border-radius: 10px;
		padding: 11px 14px;
		margin-bottom: 16px;
	}
	.banner.hidden { display: none; }

	.section { margin-bottom: 22px; }
	.section-head {
		display: flex; align-items: baseline; justify-content: space-between;
		gap: 12px; margin-bottom: 12px;
	}
	.section-head h2 {
		font-size: 12px; font-weight: 700; letter-spacing: .08em;
		text-transform: uppercase; color: var(--muted);
	}
	.section-head .count { font-size: 12px; color: var(--muted-2); }

	.tiles {
		display: grid;
		grid-template-columns: repeat(6, minmax(0, 1fr));
		gap: 12px;
	}
	.tile {
		background: linear-gradient(180deg, #141a23 0%, #10151d 100%);
		border: 1px solid var(--line);
		border-radius: 12px;
		padding: 14px 14px 13px;
		min-width: 0;
		position: relative;
		overflow: hidden;
	}
	.tile::before {
		content: "";
		position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
		background: var(--accent, #3a4658);
		opacity: .9;
	}
	.tile:nth-child(1) { --accent: var(--green); }
	.tile:nth-child(2) { --accent: var(--blue); }
	.tile:nth-child(3) { --accent: #6ec8ff; }
	.tile:nth-child(4) { --accent: #9b7bff; }
	.tile:nth-child(5) { --accent: var(--amber); }
	.tile:nth-child(6) { --accent: #ff8f6b; }
	.tile .k {
		font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
		color: var(--muted); margin-bottom: 8px; font-weight: 600;
		padding-left: 6px;
	}
	.tile .v {
		font-size: 22px; font-weight: 700; white-space: nowrap;
		letter-spacing: -0.03em; padding-left: 6px;
		overflow: hidden; text-overflow: ellipsis;
	}
	.tile .s { font-size: 12px; color: var(--muted); margin-top: 4px; padding-left: 6px; }

	.cards {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
		gap: 14px;
	}
	.card {
		background: linear-gradient(180deg, #141a23 0%, #0f141c 100%);
		border: 1px solid var(--line);
		border-radius: var(--radius);
		padding: 0;
		display: flex;
		flex-direction: column;
		box-shadow: var(--shadow);
		transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease;
		min-height: 100%;
		overflow: hidden;
		position: relative;
		cursor: pointer;
	}
	.card::before {
		content: "";
		position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
		background: var(--status, #3a4658);
	}
	.card.is-edge { --status: var(--green); }
	.card.is-online { --status: var(--blue); }
	.card.is-stock { --status: var(--amber); }
	.card.is-auth,
	.card.is-offline { --status: var(--red); }
	.card:hover {
		border-color: #334155;
		transform: translateY(-1px);
		box-shadow: 0 14px 34px rgba(0,0,0,.34);
	}

	.card-head {
		display: flex;
		align-items: flex-start;
		gap: 12px;
		padding: 16px 16px 10px 18px;
		border-bottom: 1px solid var(--line-soft);
	}
	.card-title { flex: 1; min-width: 0; }
	.card-title .name-row {
		display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap;
	}
	.card-title h3 {
		font-size: 15px; font-weight: 700; letter-spacing: -0.01em;
		white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
		max-width: 100%;
	}
	.ip-chip {
		display: inline-flex; align-items: center;
		font-size: 11px; font-weight: 600;
		font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
		color: #a8c4e8;
		background: rgba(79,156,240,.10);
		border: 1px solid rgba(79,156,240,.22);
		border-radius: 6px;
		padding: 2px 7px;
		white-space: nowrap;
	}
	.card-title .url {
		font-size: 12px; color: var(--muted);
		white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
		margin-top: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	}
	.card-title .loc {
		font-size: 12px; color: var(--muted);
		margin-top: 3px;
		white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
	}
	.card-title .loc .pin { opacity: .7; margin-right: 4px; }
	.card-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
	.card-error {
		margin: 0 16px 0 18px;
		padding: 8px 10px;
		border-radius: 8px;
		background: rgba(240,113,120,.08);
		border: 1px solid rgba(240,113,120,.22);
		color: #f3a3a8;
		font-size: 12px;
	}

	.card-metrics {
		display: grid;
		grid-template-columns: repeat(3, minmax(0, 1fr));
		gap: 1px;
		background: var(--line-soft);
		border-top: 1px solid var(--line-soft);
		margin-top: auto;
	}
	.metric {
		background: var(--bg-metric);
		padding: 12px 12px 13px;
		min-width: 0;
	}
	.metric.span-2 { grid-column: span 2; }
	.metric.span-3 { grid-column: 1 / -1; }
	.metric .mk {
		font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
		color: var(--muted-2); margin-bottom: 5px; font-weight: 700;
	}
	.metric .mv {
		font-size: 14px; font-weight: 650; line-height: 1.25;
		word-break: break-word; letter-spacing: -0.01em;
	}
	.metric .ms {
		font-size: 11px; color: var(--muted); margin-top: 3px; font-weight: 400;
	}
	.metric .pair {
		display: flex; gap: 14px; flex-wrap: wrap;
	}
	.metric .pair > div { min-width: 0; }
	.metric .pair .lbl {
		display: block; font-size: 10px; color: var(--muted-2);
		text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px;
	}
	.spark-wrap {
		margin-top: 8px;
		height: 44px;
		position: relative;
	}
	.spark-wrap canvas {
		width: 100%;
		height: 44px;
		display: block;
	}
	.spark-legend {
		display: flex; gap: 12px; margin-top: 6px;
		font-size: 10px; color: var(--muted-2); text-transform: uppercase; letter-spacing: .05em;
	}
	.spark-legend span::before {
		content: ""; display: inline-block; width: 8px; height: 2px;
		margin-right: 5px; vertical-align: middle; border-radius: 1px;
	}
	.spark-legend .tx::before { background: #4f9cf0; }
	.spark-legend .rx::before { background: #3ecf8e; }

	.empty-state {
		grid-column: 1 / -1;
		text-align: center;
		padding: 56px 24px;
		background: linear-gradient(180deg, #121820, #0e131a);
		border: 1px dashed #2a3340;
		border-radius: var(--radius);
		color: var(--muted);
	}
	.empty-state strong { display: block; color: var(--text); font-size: 16px; margin-bottom: 8px; }
	.empty-state .cta-hint { margin-top: 14px; }

	.badge {
		display: inline-flex; align-items: center; gap: 6px;
		font-size: 11px; font-weight: 700; letter-spacing: .04em;
		border-radius: 999px; padding: 4px 10px; white-space: nowrap; flex-shrink: 0;
	}
	.badge::before {
		content: ""; width: 6px; height: 6px; border-radius: 50%;
		background: currentColor; opacity: .9;
	}
	.st-edge    { background: rgba(62,207,142,.10); color: var(--green); border: 1px solid rgba(62,207,142,.28); }
	.st-online  { background: rgba(79,156,240,.10); color: #6db3f2; border: 1px solid rgba(79,156,240,.28); }
	.st-stock   { background: rgba(232,184,74,.10); color: var(--amber); border: 1px solid rgba(232,184,74,.28); }
	.st-auth    { background: rgba(240,113,120,.10); color: var(--red); border: 1px solid rgba(240,113,120,.28); }
	.st-offline { background: rgba(240,113,120,.10); color: var(--red); border: 1px solid rgba(240,113,120,.28); }

	.bar {
		background: #1c2430; border-radius: 999px; height: 5px;
		width: 100%; overflow: hidden; margin-top: 7px;
	}
	.bar i { display: block; height: 100%; background: var(--green); border-radius: 999px; }
	.bar i.warn { background: var(--amber); }
	.bar i.crit { background: var(--red); }

	.del {
		background: transparent;
		border: 1px solid transparent;
		color: var(--muted-2);
		border-radius: 8px;
		width: 30px; height: 30px;
		cursor: pointer;
		font-size: 15px; line-height: 1;
		flex-shrink: 0;
		transition: .12s ease;
	}
	.del:hover { color: var(--red); border-color: rgba(240,113,120,.35); background: rgba(240,113,120,.08); }

	button.primary {
		background: linear-gradient(180deg, #3b86d8 0%, #1f6fd1 100%);
		border: 1px solid #4f96e0;
		color: #fff;
		border-radius: 10px;
		padding: 9px 15px;
		font-size: 13px;
		font-weight: 650;
		cursor: pointer;
		box-shadow: 0 1px 0 rgba(255,255,255,.1) inset, 0 6px 16px rgba(31,111,209,.28);
		white-space: nowrap;
	}
	button.primary:hover { filter: brightness(1.06); }
	button.primary:disabled { opacity: .6; cursor: not-allowed; filter: none; }
	button.ghost {
		background: rgba(255,255,255,.03);
		border: 1px solid var(--line);
		color: var(--text);
		border-radius: 10px;
		padding: 9px 14px;
		font-size: 13px;
		font-weight: 550;
		cursor: pointer;
	}
	button.ghost:hover { border-color: #455266; background: rgba(255,255,255,.05); }
	button.linklike {
		background: rgba(255,255,255,.03);
		border: 1px solid var(--line);
		color: var(--muted);
		cursor: pointer;
		font-size: 13px;
		padding: 9px 12px;
		border-radius: 10px;
	}
	button.linklike:hover { color: var(--text); border-color: #455266; }
	.seg {
		display: inline-flex; background: rgba(255,255,255,.03);
		border: 1px solid var(--line); border-radius: 10px; overflow: hidden;
	}
	.seg button {
		background: transparent; border: 0; color: var(--muted);
		padding: 7px 12px; font-size: 12px; font-weight: 650; cursor: pointer;
	}
	.seg button.active { background: rgba(79,156,240,.14); color: var(--text); }
	.seg button:hover:not(.active) { color: var(--text); }

	.modal-backdrop {
		position: fixed; inset: 0;
		background: rgba(4, 7, 12, 0.72);
		backdrop-filter: blur(6px);
		display: none;
		align-items: center;
		justify-content: center;
		padding: 20px;
		z-index: 1000;
	}
	.modal-backdrop.open { display: flex !important; }
	.modal-backdrop[hidden] { display: none !important; }
	.modal-backdrop.open[hidden] { display: flex !important; }
	.modal {
		width: 100%; max-width: 500px;
		background: #121820;
		border: 1px solid #2a3340;
		border-radius: 16px;
		box-shadow: 0 28px 70px rgba(0,0,0,0.5);
		overflow: hidden;
		animation: modalIn .16s ease-out;
	}
	@keyframes modalIn {
		from { opacity: 0; transform: translateY(8px) scale(0.98); }
		to { opacity: 1; transform: none; }
	}
	.modal-header {
		display: flex; align-items: center; gap: 12px;
		padding: 16px 18px;
		border-bottom: 1px solid var(--line);
		background: rgba(255,255,255,.02);
	}
	.modal-header h2 { font-size: 16px; font-weight: 700; flex: 1; letter-spacing: -0.01em; }
	.modal-close {
		background: none; border: 1px solid transparent; color: var(--muted);
		width: 32px; height: 32px; border-radius: 8px; cursor: pointer; font-size: 18px; line-height: 1;
	}
	.modal-close:hover { color: var(--text); background: #1c2430; border-color: #2a3340; }
	.modal-body { padding: 18px; display: grid; gap: 13px; }
	.modal-body label {
		display: flex; flex-direction: column; gap: 6px;
		font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
		color: var(--muted); font-weight: 700;
	}
	.modal-body .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
	.modal-body .row-3 { display: grid; grid-template-columns: 110px 1fr 100px; gap: 12px; }
	.modal-body input, .modal-body select {
		background: #0a0e14;
		border: 1px solid #3a4350;
		color: var(--text);
		border-radius: 9px;
		padding: 10px 11px;
		font-size: 14px;
		width: 100%;
		text-transform: none;
		letter-spacing: normal;
		font-weight: 400;
	}
	.modal-body input:focus, .modal-body select:focus {
		outline: none; border-color: var(--blue);
		box-shadow: 0 0 0 3px rgba(79,156,240,0.14);
	}
	.modal-footer {
		display: flex; justify-content: flex-end; gap: 10px;
		padding: 14px 18px 18px;
	}
	body.modal-open { overflow: hidden; }

	.login-wrap {
		min-height: 100vh; display: grid; place-items: center; padding: 24px;
	}
	.login {
		width: 100%; max-width: 380px;
		background: linear-gradient(180deg, #141a23, #10151d);
		border: 1px solid var(--line);
		border-radius: 16px;
		padding: 28px;
		box-shadow: var(--shadow);
	}
	.login .brand { margin-bottom: 18px; }
	.login h1 { margin-bottom: 0; }
	.login p.lead { color: var(--muted); font-size: 13px; margin: -8px 0 18px; }
	.login input {
		width: 100%; background: #0a0e14; border: 1px solid #3a4350;
		color: var(--text); border-radius: 9px; padding: 11px 12px;
		font-size: 14px; margin-bottom: 12px;
	}
	.login input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(79,156,240,0.14); }
	.login button { width: 100%; }

	.hint {
		margin-top: 8px; color: var(--muted-2); font-size: 12px; line-height: 1.55;
		padding: 0 2px;
	}

	/* Detail page */
	.back-link {
		display: inline-flex; align-items: center; gap: 6px;
		color: var(--muted); font-size: 13px; margin-bottom: 14px;
	}
	.back-link:hover { color: var(--text); text-decoration: none; }
	.detail-hero {
		background: linear-gradient(180deg, #141a23 0%, #0f141c 100%);
		border: 1px solid var(--line);
		border-radius: var(--radius);
		padding: 20px 22px;
		margin-bottom: 18px;
		box-shadow: var(--shadow);
		position: relative;
		overflow: hidden;
	}
	.detail-hero::before {
		content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
		background: var(--status, #3a4658);
	}
	.detail-hero.is-edge { --status: var(--green); }
	.detail-hero.is-online { --status: var(--blue); }
	.detail-hero.is-stock { --status: var(--amber); }
	.detail-hero.is-auth,
	.detail-hero.is-offline { --status: var(--red); }
	.detail-hero-top {
		display: flex; align-items: flex-start; gap: 14px; flex-wrap: wrap;
	}
	.detail-hero h1 {
		font-size: 22px; font-weight: 750; letter-spacing: -0.02em;
		line-height: 1.2;
	}
	.detail-meta {
		display: flex; flex-wrap: wrap; gap: 8px 14px;
		margin-top: 10px; color: var(--muted); font-size: 13px;
	}
	.detail-meta code {
		font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
		color: #a8c4e8; font-size: 12px;
	}
	.detail-edit {
		margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: end;
	}
	.detail-edit label {
		display: flex; flex-direction: column; gap: 5px;
		font-size: 10px; text-transform: uppercase; letter-spacing: .06em;
		color: var(--muted); font-weight: 700;
	}
	.detail-edit input {
		background: #0a0e14; border: 1px solid #3a4350; color: var(--text);
		border-radius: 8px; padding: 8px 10px; font-size: 13px; min-width: 180px;
	}
	.detail-edit input:focus { outline: none; border-color: var(--blue); }

	.log-panel {
		margin: 18px 0 8px;
		background: linear-gradient(180deg, #141a23 0%, #0f141c 100%);
		border: 1px solid var(--line);
		border-radius: var(--radius);
		box-shadow: var(--shadow);
		overflow: hidden;
	}
	.log-panel-head {
		display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
		padding: 12px 16px;
		border-bottom: 1px solid var(--line-soft);
	}
	.log-panel.is-collapsed .log-panel-head {
		border-bottom: none;
	}
	.log-panel-toggle {
		display: inline-flex; align-items: center; gap: 8px;
		background: transparent; border: 0; padding: 0; margin: 0;
		color: inherit; cursor: pointer; flex: 1; min-width: 160px;
		text-align: left;
	}
	.log-panel-toggle:hover h3 { color: var(--text); }
	.log-panel-toggle:focus-visible {
		outline: 2px solid var(--blue); outline-offset: 2px; border-radius: 6px;
	}
	.log-panel-chevron {
		display: inline-flex; align-items: center; justify-content: center;
		width: 20px; height: 20px; flex-shrink: 0;
		color: var(--muted);
		transition: transform .15s ease;
		font-size: 11px; line-height: 1;
	}
	.log-panel:not(.is-collapsed) .log-panel-chevron {
		transform: rotate(90deg);
	}
	.log-panel-head h3 {
		font-size: 13px; font-weight: 700; letter-spacing: .04em;
		text-transform: uppercase; color: var(--muted); margin: 0;
	}
	.log-panel-meta {
		font-size: 12px; color: var(--muted);
		font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	}
	.log-panel-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
	.log-panel.is-collapsed .log-panel-fold { display: none; }
	.log-panel-body {
		padding: 0;
		background: #0a0e14;
	}
	.log-panel pre {
		margin: 0;
		padding: 14px 16px;
		max-height: 420px;
		overflow: auto;
		font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
		color: #c5cedb;
		white-space: pre-wrap;
		word-break: break-word;
	}
	.log-panel pre.empty { color: var(--muted-2); font-style: italic; }
	.log-panel-err {
		padding: 12px 16px;
		color: #f3a3a8;
		font-size: 13px;
		background: rgba(240,113,120,.08);
		border-top: 1px solid rgba(240,113,120,.22);
	}
	.log-panel-err:empty { display: none; }
	button.ghost:disabled, button.linklike:disabled {
		opacity: .55; cursor: not-allowed;
	}

	.uptime-strip {
		display: grid;
		grid-template-columns: repeat(4, minmax(0, 1fr));
		gap: 12px;
		margin-bottom: 18px;
	}
	.uptime-card {
		background: linear-gradient(180deg, #141a23, #10151d);
		border: 1px solid var(--line);
		border-radius: 12px;
		padding: 14px;
	}
	.uptime-card .k {
		font-size: 11px; text-transform: uppercase; letter-spacing: .07em;
		color: var(--muted); font-weight: 600; margin-bottom: 6px;
	}
	.uptime-card .v { font-size: 22px; font-weight: 750; letter-spacing: -0.03em; }
	.uptime-card .s { font-size: 12px; color: var(--muted); margin-top: 4px; }
	.uptime-bar {
		margin-top: 10px; height: 28px; border-radius: 6px;
		background: #1c2430; overflow: hidden; display: flex;
	}
	.uptime-bar i {
		display: block; height: 100%;
		flex: 0 0 auto;
	}
	.uptime-bar i.up { background: rgba(62,207,142,.75); }
	.uptime-bar i.down { background: rgba(240,113,120,.7); }
	.uptime-bar i.gap { background: transparent; }

	.charts {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 14px;
	}
	.chart-card {
		background: linear-gradient(180deg, #141a23, #0f141c);
		border: 1px solid var(--line);
		border-radius: var(--radius);
		padding: 14px 16px 12px;
		box-shadow: var(--shadow);
		min-width: 0;
	}
	.chart-card.full { grid-column: 1 / -1; }
	.chart-card h3 {
		font-size: 12px; font-weight: 700; letter-spacing: .06em;
		text-transform: uppercase; color: var(--muted); margin-bottom: 10px;
	}
	.chart-card .chart-box { position: relative; height: 220px; }
	.chart-card.full .chart-box { height: 260px; }
	.chart-card canvas { width: 100% !important; height: 100% !important; }
	.chart-empty {
		position: absolute; inset: 0; display: grid; place-items: center;
		color: var(--muted-2); font-size: 13px;
	}

	@media (max-width: 1100px) {
		.tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); }
		.uptime-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
		.charts { grid-template-columns: 1fr; }
	}
	@media (max-width: 720px) {
		.tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
		.cards { grid-template-columns: 1fr; }
		.card-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
		.metric.span-2, .metric.span-3 { grid-column: 1 / -1; }
		.modal-body .row-3 { grid-template-columns: 1fr 1fr; }
		.modal-body .row-3 label:last-child { grid-column: 1 / -1; }
	}
	@media (max-width: 520px) {
		.shell { padding: 14px 14px 28px; }
		.topbar { margin: -14px -14px 16px; padding: 12px 14px; }
		.tiles { grid-template-columns: 1fr 1fr; gap: 8px; }
		.tile .v { font-size: 18px; }
		.topbar-actions { width: 100%; }
		.topbar-actions .primary { flex: 1; }
		.modal-body .row, .modal-body .row-3 { grid-template-columns: 1fr; }
		.modal-body .row-3 label:last-child { grid-column: auto; }
		.uptime-strip { grid-template-columns: 1fr 1fr; }
	}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
</head>
<body>

<?php if (!$is_authed) { ?>

<div class="login-wrap">
	<div class="login">
		<div class="brand">
			<div class="brand-mark">EM</div>
			<div>
				<h1>Edge monitor</h1>
			</div>
		</div>
		<p class="lead">Sign in to view cache edge health, storage, and bandwidth.</p>
		<?php if ($err !== '') { ?><div class="banner"><?php echo htmlspecialchars($err); ?></div><?php } ?>
		<form method="post" action="">
			<input type="hidden" name="action" value="login">
			<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
			<input type="password" name="password" placeholder="Password" autofocus>
			<button class="primary" type="submit">Log in</button>
		</form>
	</div>
</div>

<?php } elseif ($detail_server) {
	$ds = $detail_server;
	$ds_ip = monitor_display_ip($ds);
	$ds_ip_manual = isset($ds['ip']) ? trim((string) $ds['ip']) : '';
	$ds_loc = isset($ds['location']) ? $ds['location'] : '';
?>

<div class="shell wide">
	<header class="topbar">
		<div class="brand">
			<div class="brand-mark">EM</div>
			<div>
				<h1>Server detail</h1>
				<div class="meta">
					<span class="pill"><span class="live-dot" id="live-dot"></span><span id="updated">loading&hellip;</span></span>
					<span class="pill">history 7d retention</span>
				</div>
			</div>
		</div>
		<div class="topbar-actions">
			<div class="seg" id="range-seg">
				<button type="button" data-range="1h">1h</button>
				<button type="button" data-range="6h">6h</button>
				<button type="button" data-range="24h" class="active">24h</button>
				<button type="button" data-range="7d">7d</button>
			</div>
			<button type="button" class="ghost" id="btn-download-log" title="Download monitoring history CSV from this dashboard">Download status CSV</button>
			<form method="post" action="" style="display:inline">
				<input type="hidden" name="action" value="logout">
				<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
				<button class="linklike" type="submit">Log out</button>
			</form>
		</div>
	</header>

	<a class="back-link" href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>">&larr; All servers</a>

	<?php if ($err !== '') { ?><div class="banner"><?php echo htmlspecialchars($err); ?></div><?php } ?>
	<div class="banner hidden" id="conn-error">Failed to load history &mdash; retrying&hellip;</div>

	<div class="detail-hero" id="detail-hero">
		<div class="detail-hero-top">
			<div style="flex:1;min-width:0">
				<div class="name-row" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
					<h1 id="d-name"><?php echo htmlspecialchars($ds['name']); ?></h1>
					<span class="badge st-offline" id="d-badge">…</span>
				</div>
				<div class="detail-meta">
					<span>IP <code id="d-ip"><?php echo $ds_ip !== '' ? htmlspecialchars($ds_ip) : '—'; ?></code></span>
					<span>Host <code id="d-host"><?php echo htmlspecialchars($ds['host']); ?></code></span>
					<span>Location <span id="d-loc"><?php echo $ds_loc !== '' ? htmlspecialchars($ds_loc) : '—'; ?></span></span>
					<span class="muted" id="d-url" style="font-family:ui-monospace,monospace;font-size:12px"><?php echo htmlspecialchars(monitor_server_url($ds)); ?></span>
				</div>
			</div>
		</div>
		<form class="detail-edit" method="post" action="" id="edit-form">
			<input type="hidden" name="action" value="update">
			<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
			<input type="hidden" name="id" value="<?php echo htmlspecialchars($ds['id']); ?>">
			<label>Display name
				<input type="text" name="name" value="<?php echo htmlspecialchars($ds['name']); ?>" maxlength="80">
			</label>
			<label>Server IP
				<input type="text" name="ip" value="<?php echo htmlspecialchars($ds_ip_manual); ?>" placeholder="real IP (not proxy)" maxlength="45" autocomplete="off">
			</label>
			<label>Location
				<input type="text" name="location" value="<?php echo htmlspecialchars($ds_loc); ?>" placeholder="e.g. Los Angeles, US" maxlength="120">
			</label>
			<button type="submit" class="ghost">Save</button>
		</form>
		<p class="hint" style="margin-top:10px">Set <strong>Server IP</strong> to the real machine address when DNS points at a CDN/proxy. Leave empty to show the DNS-resolved IP.</p>
	</div>

	<section class="log-panel is-collapsed" id="log-panel" aria-label="Monitoring history">
		<div class="log-panel-head">
			<button type="button" class="log-panel-toggle" id="btn-log-fold" aria-expanded="false" aria-controls="log-panel-fold" title="Expand or collapse monitoring history">
				<span class="log-panel-chevron" aria-hidden="true">&#9654;</span>
				<h3>Monitoring history</h3>
			</button>
			<span class="log-panel-meta" id="log-meta">checking&hellip;</span>
			<div class="log-panel-actions log-panel-fold" id="log-panel-actions">
				<button type="button" class="linklike" id="btn-log-tail">View last 300 samples</button>
				<button type="button" class="ghost" id="btn-log-download">Download CSV (7d)</button>
				<button type="button" class="linklike" id="btn-log-download-txt" title="Plain-text status log">Download TXT</button>
			</div>
		</div>
		<div class="log-panel-fold" id="log-panel-fold">
			<div class="log-panel-body">
				<pre class="empty" id="log-view">Click “View last 300 samples” to load status history collected by this dashboard (not from the CDN edge).</pre>
			</div>
			<div class="log-panel-err" id="log-err"></div>
		</div>
	</section>

	<div class="uptime-strip" id="uptime-strip">
		<div class="uptime-card"><div class="k">Uptime 1h</div><div class="v" id="u-1h">–</div><div class="s" id="u-1h-s"></div></div>
		<div class="uptime-card"><div class="k">Uptime 24h</div><div class="v" id="u-24h">–</div><div class="s" id="u-24h-s"></div></div>
		<div class="uptime-card"><div class="k">Uptime 7d</div><div class="v" id="u-7d">–</div><div class="s" id="u-7d-s"></div></div>
		<div class="uptime-card">
			<div class="k">Current streak</div>
			<div class="v" id="u-streak">–</div>
			<div class="s" id="u-streak-s"></div>
			<div class="uptime-bar" id="uptime-bar" title="Recent online/offline samples"></div>
		</div>
	</div>

	<div class="charts">
		<div class="chart-card full">
			<h3>Bandwidth</h3>
			<div class="chart-box"><canvas id="c-bw"></canvas><div class="chart-empty" id="e-bw" hidden>No samples yet — wait for a few polls.</div></div>
		</div>
		<div class="chart-card">
			<h3>CPU %</h3>
			<div class="chart-box"><canvas id="c-cpu"></canvas><div class="chart-empty" id="e-cpu" hidden>No data</div></div>
		</div>
		<div class="chart-card">
			<h3>Load average</h3>
			<div class="chart-box"><canvas id="c-load"></canvas><div class="chart-empty" id="e-load" hidden>No data</div></div>
		</div>
		<div class="chart-card">
			<h3>Storage free</h3>
			<div class="chart-box"><canvas id="c-disk"></canvas><div class="chart-empty" id="e-disk" hidden>No data</div></div>
		</div>
		<div class="chart-card">
			<h3>Cached videos</h3>
			<div class="chart-box"><canvas id="c-cache"></canvas><div class="chart-empty" id="e-cache" hidden>No data</div></div>
		</div>
		<div class="chart-card full">
			<h3>Plays (15 min window)</h3>
			<div class="chart-box"><canvas id="c-plays"></canvas><div class="chart-empty" id="e-plays" hidden>No data</div></div>
		</div>
		<div class="chart-card full">
			<h3>Uptime (online / offline)</h3>
			<div class="chart-box"><canvas id="c-up"></canvas><div class="chart-empty" id="e-up" hidden>No data</div></div>
		</div>
	</div>
</div>

<script>
var SERVER_ID = <?php echo json_encode($ds['id']); ?>;
var POLL_MS = <?php echo max(3, intval($poll_interval_seconds)) * 1000; ?>;
var range = '24h';
var charts = {};

var STATUS = {
	edge:    { label: 'EDGE',        cls: 'st-edge', card: 'is-edge' },
	online:  { label: 'Origin off',  cls: 'st-online', card: 'is-online' },
	no_api:  { label: 'Stock',       cls: 'st-stock', card: 'is-stock' },
	auth:    { label: 'Auth failed', cls: 'st-auth', card: 'is-auth' },
	offline: { label: 'Offline',     cls: 'st-offline', card: 'is-offline' }
};

function esc(s)
{
	return String(s == null ? '' : s).replace(/[&<>"']/g, function (c)
	{
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
	});
}

function fmtBytes(b)
{
	if (b == null || isNaN(b) || b === false || b < 0) return '–';
	var u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
	b = Number(b);
	while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
	return (i === 0 ? Math.round(b) : b.toFixed(b >= 100 ? 0 : 1)) + ' ' + u[i];
}

// bytes/sec → bits/sec (SI 1000), e.g. 2.6 Gbps not 325 MB/s
function fmtRate(bytesPerSec)
{
	if (bytesPerSec == null || isNaN(bytesPerSec) || bytesPerSec === false || bytesPerSec < 0) return '–';
	var bits = Number(bytesPerSec) * 8;
	var u = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'], i = 0;
	while (bits >= 1000 && i < u.length - 1) { bits /= 1000; i++; }
	var n = (i === 0) ? Math.round(bits) : bits.toFixed(bits >= 100 ? 0 : (bits >= 10 ? 1 : 2));
	return n + ' ' + u[i];
}

function fmtDur(sec)
{
	if (sec == null || sec < 0) return '–';
	sec = Math.floor(sec);
	if (sec < 60) return sec + 's';
	if (sec < 3600) return Math.floor(sec / 60) + 'm ' + (sec % 60) + 's';
	if (sec < 86400) return Math.floor(sec / 3600) + 'h ' + Math.floor((sec % 3600) / 60) + 'm';
	return Math.floor(sec / 86400) + 'd ' + Math.floor((sec % 86400) / 3600) + 'h';
}

function fmtPct(p)
{
	if (p == null) return '–';
	return Number(p).toFixed(2) + '%';
}

var chartDefaults = {
	responsive: true,
	maintainAspectRatio: false,
	animation: false,
	interaction: { mode: 'index', intersect: false },
	plugins: {
		legend: {
			labels: { color: '#8b95a5', boxWidth: 12, font: { size: 11 } }
		},
		tooltip: {
			backgroundColor: 'rgba(12,16,22,.95)',
			borderColor: '#2a3340',
			borderWidth: 1,
			titleColor: '#e6ebf2',
			bodyColor: '#c5cedb'
		}
	},
	scales: {
		x: {
			ticks: { color: '#667081', maxRotation: 0, autoSkipPadding: 12, font: { size: 10 } },
			grid: { color: 'rgba(34,42,54,.7)' }
		},
		y: {
			ticks: { color: '#667081', font: { size: 10 } },
			grid: { color: 'rgba(34,42,54,.7)' }
		}
	}
};

function makeChart(canvasId, type, datasets, yTickFn)
{
	var el = document.getElementById(canvasId);
	if (!el || typeof Chart === 'undefined') return null;
	if (charts[canvasId])
	{
		charts[canvasId].destroy();
	}
	var opts = JSON.parse(JSON.stringify(chartDefaults));
	if (yTickFn)
	{
		opts.scales.y.ticks.callback = yTickFn;
		// Match tooltip labels to tick formatter (e.g. bandwidth in bits, not raw bytes).
		opts.plugins.tooltip = opts.plugins.tooltip || {};
		opts.plugins.tooltip.callbacks = {
			label: function (ctx)
			{
				var label = (ctx.dataset && ctx.dataset.label) ? ctx.dataset.label : '';
				var v = ctx.parsed && ctx.parsed.y != null ? ctx.parsed.y : ctx.raw;
				var shown = (typeof yTickFn === 'function') ? yTickFn(v) : v;
				return (label ? label + ': ' : '') + shown;
			}
		};
	}
	if (type === 'bar')
	{
		opts.datasets = opts.datasets || {};
	}
	charts[canvasId] = new Chart(el, {
		type: type,
		data: { labels: [], datasets: datasets },
		options: opts
	});
	return charts[canvasId];
}

function setChartData(chart, labels, seriesArrays, emptyId)
{
	var empty = document.getElementById(emptyId);
	var has = labels && labels.length > 0;
	if (empty) empty.hidden = has;
	if (!chart) return;
	chart.data.labels = labels || [];
	seriesArrays.forEach(function (arr, i)
	{
		if (chart.data.datasets[i]) chart.data.datasets[i].data = arr || [];
	});
	chart.update();
}

function initCharts()
{
	makeChart('c-bw', 'line', [
		{ label: 'Out', data: [], borderColor: '#4f9cf0', backgroundColor: 'rgba(79,156,240,.12)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 },
		{ label: 'In', data: [], borderColor: '#3ecf8e', backgroundColor: 'rgba(62,207,142,.08)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 }
	], function (v) { return fmtRate(v); });

	makeChart('c-cpu', 'line', [
		{ label: 'CPU %', data: [], borderColor: '#e8b84a', backgroundColor: 'rgba(232,184,74,.12)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 }
	], function (v) { return v.toFixed(0) + '%'; });

	makeChart('c-load', 'line', [
		{ label: 'Load 1m', data: [], borderColor: '#9b7bff', backgroundColor: 'rgba(155,123,255,.10)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 }
	]);

	makeChart('c-disk', 'line', [
		{ label: 'Free', data: [], borderColor: '#6ec8ff', backgroundColor: 'rgba(110,200,255,.10)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 }
	], function (v) { return fmtBytes(v); });

	makeChart('c-cache', 'line', [
		{ label: 'Videos', data: [], borderColor: '#ff8f6b', backgroundColor: 'rgba(255,143,107,.10)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 }
	]);

	makeChart('c-plays', 'line', [
		{ label: 'Plays 15m', data: [], borderColor: '#3ecf8e', backgroundColor: 'rgba(62,207,142,.10)', fill: true, tension: .25, pointRadius: 0, borderWidth: 2 }
	]);

	makeChart('c-up', 'bar', [
		{ label: 'Online', data: [], backgroundColor: 'rgba(62,207,142,.75)', borderWidth: 0, barPercentage: 1, categoryPercentage: 1 }
	], function (v) { return v ? 'up' : 'down'; });
	if (charts['c-up'])
	{
		charts['c-up'].options.scales.y.min = 0;
		charts['c-up'].options.scales.y.max = 1;
		charts['c-up'].options.scales.y.ticks.stepSize = 1;
		charts['c-up'].options.plugins.legend.display = false;
	}
}

function labelsFromSamples(samples)
{
	return samples.map(function (s)
	{
		var d = new Date(Number(s.ts) * 1000);
		if (range === '7d') return (d.getMonth() + 1) + '/' + d.getDate() + ' ' + d.getHours() + ':00';
		return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	});
}

function renderHistory(payload)
{
	var h = payload.history || {};
	var samples = h.samples || [];
	var up = h.uptime || {};
	var labels = labelsFromSamples(samples);

	function col(key)
	{
		return samples.map(function (s)
		{
			var v = s[key];
			return (v === null || v === undefined || v === '') ? null : Number(v);
		});
	}

	setChartData(charts['c-bw'], labels, [col('tx_bps'), col('rx_bps')], 'e-bw');
	setChartData(charts['c-cpu'], labels, [col('cpu_pct')], 'e-cpu');
	setChartData(charts['c-load'], labels, [col('load1')], 'e-load');
	setChartData(charts['c-disk'], labels, [col('disk_free')], 'e-disk');
	setChartData(charts['c-cache'], labels, [col('cache_videos')], 'e-cache');
	setChartData(charts['c-plays'], labels, [col('views_15m')], 'e-plays');
	setChartData(charts['c-up'], labels, [samples.map(function (s) { return Number(s.online) ? 1 : 0; })], 'e-up');

	['1h', '24h', '7d'].forEach(function (k)
	{
		var u = up[k] || {};
		var el = document.getElementById('u-' + k);
		var sub = document.getElementById('u-' + k + '-s');
		if (el) el.textContent = fmtPct(u.pct);
		if (sub) sub.textContent = u.samples ? (u.up + ' / ' + u.samples + ' samples up') : 'no samples yet';
	});

	var streakEl = document.getElementById('u-streak');
	var streakS = document.getElementById('u-streak-s');
	if (h.current_online != null && h.status_since)
	{
		var age = (h.now || Math.floor(Date.now() / 1000)) - Number(h.status_since);
		if (streakEl) streakEl.textContent = h.current_online ? 'Online' : 'Offline';
		if (streakS) streakS.textContent = 'for ' + fmtDur(age);
	}
	else
	{
		if (streakEl) streakEl.textContent = '–';
		if (streakS) streakS.textContent = 'waiting for samples';
	}

	// Mini uptime bar from last ~80 samples in this range
	var bar = document.getElementById('uptime-bar');
	if (bar)
	{
		var slice = samples.slice(-80);
		if (!slice.length)
		{
			bar.innerHTML = '';
		}
		else
		{
			var html = '';
			var w = (100 / slice.length).toFixed(4);
			slice.forEach(function (s)
			{
				html += '<i class="' + (Number(s.online) ? 'up' : 'down') + '" style="width:' + w + '%"></i>';
			});
			bar.innerHTML = html;
		}
	}

	document.getElementById('updated').textContent = 'updated ' + new Date().toLocaleTimeString();
}

function applyLiveStatus(srv)
{
	if (!srv) return;
	var st = STATUS[srv.status] || STATUS.offline;
	var badge = document.getElementById('d-badge');
	var hero = document.getElementById('detail-hero');
	if (badge)
	{
		badge.className = 'badge ' + st.cls;
		badge.textContent = st.label;
	}
	if (hero)
	{
		hero.className = 'detail-hero ' + st.card;
	}
	if (srv.ip)
	{
		document.getElementById('d-ip').textContent = srv.ip;
	}
}

function loadHistory()
{
	fetch('?ajax=history&id=' + encodeURIComponent(SERVER_ID) + '&range=' + encodeURIComponent(range), { cache: 'no-store' })
		.then(function (r)
		{
			if (r.status === 401) { location.reload(); throw new Error('unauthorized'); }
			return r.json();
		})
		.then(function (data)
		{
			document.getElementById('conn-error').classList.add('hidden');
			var dot = document.getElementById('live-dot');
			if (dot) dot.classList.remove('err');
			if (data.ok) renderHistory(data);
		})
		.catch(function ()
		{
			document.getElementById('conn-error').classList.remove('hidden');
			var dot = document.getElementById('live-dot');
			if (dot) dot.classList.add('err');
		});
}

function livePoll()
{
	// Trigger a sample write + refresh live badge.
	fetch('?ajax=poll', { cache: 'no-store' })
		.then(function (r) { return r.json(); })
		.then(function (data)
		{
			if (!data.ok) return;
			var srv = (data.servers || []).filter(function (s) { return s.id === SERVER_ID; })[0];
			applyLiveStatus(srv);
			loadHistory();
		})
		.catch(function () { loadHistory(); });
}

document.getElementById('range-seg').addEventListener('click', function (e)
{
	var btn = e.target.closest('button[data-range]');
	if (!btn) return;
	range = btn.getAttribute('data-range');
	Array.prototype.forEach.call(document.querySelectorAll('#range-seg button'), function (b)
	{
		b.classList.toggle('active', b === btn);
	});
	loadHistory();
});

// ---- Monitoring history export (SQLite on this dashboard host; not from the edge) ----
var LOG_FOLD_KEY = 'kvs_monitor_history_open_' + SERVER_ID;

function setLogPanelOpen(open)
{
	var panel = document.getElementById('log-panel');
	var btn = document.getElementById('btn-log-fold');
	if (!panel) return;
	panel.classList.toggle('is-collapsed', !open);
	if (btn)
	{
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		btn.title = open ? 'Collapse monitoring history' : 'Expand monitoring history';
	}
	try { localStorage.setItem(LOG_FOLD_KEY, open ? '1' : '0'); } catch (e) {}
}

function initLogPanelFold()
{
	var btn = document.getElementById('btn-log-fold');
	if (!btn) return;
	var open = false;
	try { open = localStorage.getItem(LOG_FOLD_KEY) === '1'; } catch (e) {}
	setLogPanelOpen(open);
	btn.addEventListener('click', function ()
	{
		var panel = document.getElementById('log-panel');
		var isOpen = panel && !panel.classList.contains('is-collapsed');
		setLogPanelOpen(!isOpen);
	});
}

function fmtLogTime(ts)
{
	if (!ts) return '–';
	try { return new Date(Number(ts) * 1000).toLocaleString(); }
	catch (e) { return String(ts); }
}

function setLogErr(msg)
{
	var el = document.getElementById('log-err');
	if (el) el.textContent = msg || '';
}

function setLogBusy(busy)
{
	['btn-log-tail', 'btn-log-download', 'btn-log-download-txt', 'btn-download-log'].forEach(function (id)
	{
		var b = document.getElementById(id);
		if (b) b.disabled = !!busy;
	});
}

function statuslogUrl(extra)
{
	var q = '?ajax=statuslog&id=' + encodeURIComponent(SERVER_ID);
	if (extra) q += extra;
	return q;
}

function loadLogMeta()
{
	var metaEl = document.getElementById('log-meta');
	fetch(statuslogUrl('&meta=1'), { cache: 'no-store' })
		.then(function (r)
		{
			if (r.status === 401) { location.reload(); throw new Error('unauthorized'); }
			return r.json().then(function (data)
			{
				return { http: r.status, data: data };
			});
		})
		.then(function (res)
		{
			if (!res.data || !res.data.ok)
			{
				var err = (res.data && res.data.error) ? res.data.error : ('HTTP ' + res.http);
				if (metaEl) metaEl.textContent = 'unavailable';
				setLogErr(err);
				return;
			}
			setLogErr('');
			if (metaEl)
			{
				var n = res.data.samples != null ? res.data.samples : 0;
				var parts = [n + ' sample' + (n === 1 ? '' : 's')];
				if (res.data.oldest && res.data.newest)
				{
					parts.push(fmtLogTime(res.data.oldest) + ' → ' + fmtLogTime(res.data.newest));
				}
				else if (n === 0)
				{
					parts.push('no data yet — wait for a few polls');
				}
				metaEl.textContent = parts.join(' · ');
			}
		})
		.catch(function ()
		{
			if (metaEl) metaEl.textContent = 'unavailable';
			setLogErr('Could not load monitoring history metadata.');
		});
}

function downloadStatusLog(opts)
{
	opts = opts || {};
	var format = opts.format || 'csv';
	var range = opts.range || '7d';
	setLogBusy(true);
	setLogErr('');
	var url = statuslogUrl('&range=' + encodeURIComponent(range) + '&format=' + encodeURIComponent(format));
	fetch(url, { cache: 'no-store' })
		.then(function (r)
		{
			if (r.status === 401) { location.reload(); throw new Error('unauthorized'); }
			var ct = (r.headers.get('content-type') || '').toLowerCase();
			if (!r.ok || ct.indexOf('application/json') !== -1)
			{
				return r.text().then(function (t)
				{
					var msg = 'Download failed (HTTP ' + r.status + ')';
					try
					{
						var j = JSON.parse(t);
						if (j && j.error) msg = j.error;
					}
					catch (e) {}
					throw new Error(msg);
				});
			}
			var disp = r.headers.get('content-disposition') || '';
			var fname = SERVER_ID + '-status.' + (format === 'txt' ? 'log' : 'csv');
			var m = /filename="([^"]+)"/i.exec(disp);
			if (m) fname = m[1];
			return r.blob().then(function (blob)
			{
				return { blob: blob, fname: fname };
			});
		})
		.then(function (res)
		{
			var a = document.createElement('a');
			a.href = URL.createObjectURL(res.blob);
			a.download = res.fname;
			document.body.appendChild(a);
			a.click();
			setTimeout(function ()
			{
				URL.revokeObjectURL(a.href);
				a.remove();
			}, 1000);
			setLogBusy(false);
		})
		.catch(function (e)
		{
			setLogBusy(false);
			setLogErr(e && e.message ? e.message : 'Download failed');
		});
}

function viewLogTail()
{
	var view = document.getElementById('log-view');
	setLogBusy(true);
	setLogErr('');
	if (view)
	{
		view.classList.add('empty');
		view.textContent = 'Loading last 300 samples…';
	}
	fetch(statuslogUrl('&lines=300&view=1'), { cache: 'no-store' })
		.then(function (r)
		{
			if (r.status === 401) { location.reload(); throw new Error('unauthorized'); }
			var ct = (r.headers.get('content-type') || '').toLowerCase();
			if (!r.ok || ct.indexOf('application/json') !== -1)
			{
				return r.text().then(function (t)
				{
					var msg = 'Failed to load history (HTTP ' + r.status + ')';
					try
					{
						var j = JSON.parse(t);
						if (j && j.error) msg = j.error;
					}
					catch (e) {}
					throw new Error(msg);
				});
			}
			return r.text();
		})
		.then(function (text)
		{
			if (view)
			{
				if (!text || !String(text).trim())
				{
					view.classList.add('empty');
					view.textContent = '(no samples yet — wait for a few dashboard polls)';
				}
				else
				{
					view.classList.remove('empty');
					view.textContent = text;
					view.scrollTop = view.scrollHeight;
				}
			}
			setLogBusy(false);
			loadLogMeta();
		})
		.catch(function (e)
		{
			if (view)
			{
				view.classList.add('empty');
				view.textContent = 'Failed to load history.';
			}
			setLogBusy(false);
			setLogErr(e && e.message ? e.message : 'Failed to load history');
		});
}

var btnTail = document.getElementById('btn-log-tail');
var btnDl = document.getElementById('btn-log-download');
var btnDlTxt = document.getElementById('btn-log-download-txt');
var btnDlTop = document.getElementById('btn-download-log');
if (btnTail) btnTail.addEventListener('click', viewLogTail);
if (btnDl) btnDl.addEventListener('click', function () { downloadStatusLog({ format: 'csv', range: '7d' }); });
if (btnDlTxt) btnDlTxt.addEventListener('click', function () { downloadStatusLog({ format: 'txt', range: '7d' }); });
if (btnDlTop) btnDlTop.addEventListener('click', function () { downloadStatusLog({ format: 'csv', range: '7d' }); });
initLogPanelFold();
loadLogMeta();

initCharts();
livePoll();
setInterval(livePoll, POLL_MS);
</script>

<?php } else { ?>

<div class="shell">
	<header class="topbar">
		<div class="brand">
			<div class="brand-mark">EM</div>
			<div>
				<h1>Edge server monitor</h1>
				<div class="meta">
					<span class="pill"><span class="live-dot" id="live-dot"></span><span id="updated">loading&hellip;</span></span>
					<span class="pill">auto-refresh <?php echo intval($poll_interval_seconds); ?>s</span>
					<span class="pill">history SQLite</span>
				</div>
			</div>
		</div>
		<div class="topbar-actions">
			<button type="button" class="primary" id="btn-add-server">+ Add server</button>
			<form method="post" action="" style="display:inline">
				<input type="hidden" name="action" value="logout">
				<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
				<button class="linklike" type="submit">Log out</button>
			</form>
		</div>
	</header>

	<?php if ($err !== '') { ?><div class="banner"><?php echo htmlspecialchars($err); ?></div><?php } ?>
	<?php if ($detail_id !== '' && !$detail_server) { ?><div class="banner">Server not found — it may have been removed.</div><?php } ?>
	<div class="banner hidden" id="conn-error">Dashboard poll failed &mdash; retrying at the next interval.</div>

	<section class="section">
		<div class="section-head">
			<h2>Overview</h2>
		</div>
		<div class="tiles">
			<div class="tile"><div class="k">Edges active</div><div class="v" id="t-edges">&ndash;</div><div class="s" id="t-servers"></div></div>
			<div class="tile"><div class="k">Total storage</div><div class="v" id="t-total">&ndash;</div></div>
			<div class="tile"><div class="k">Free storage</div><div class="v" id="t-free">&ndash;</div></div>
			<div class="tile"><div class="k">Bandwidth out</div><div class="v" id="t-tx">&ndash;</div><div class="s" id="t-rx"></div></div>
			<div class="tile"><div class="k">Cached videos</div><div class="v" id="t-videos">&ndash;</div><div class="s" id="t-cachebytes"></div></div>
			<div class="tile"><div class="k">Plays (15 min)</div><div class="v" id="t-plays">&ndash;</div></div>
		</div>
	</section>

	<section class="section">
		<div class="section-head">
			<h2>Servers</h2>
			<span class="count" id="server-count"></span>
		</div>
		<div class="cards" id="cards">
			<div class="empty-state"><strong>Loading…</strong>Polling registered servers</div>
		</div>
		<p class="hint">
			Click a server card for full historical charts and uptime.
			CPU % and bandwidth are averaged between two polls (and stored in SQLite).
			Cached-video counts are recounted on each edge at most every 5 minutes.
		</p>
	</section>
</div>

<!-- Add server modal -->
<div class="modal-backdrop" id="add-modal" role="dialog" aria-modal="true" aria-labelledby="add-modal-title" hidden>
	<div class="modal">
		<div class="modal-header">
			<h2 id="add-modal-title">Add server</h2>
			<button type="button" class="modal-close" id="add-modal-close" aria-label="Close">&times;</button>
		</div>
		<form method="post" action="" id="add-form">
			<input type="hidden" name="action" value="add">
			<input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
			<div class="modal-body">
				<label>Name (optional)
					<input type="text" name="name" placeholder="Edge G07" autocomplete="off">
				</label>
				<div class="row">
					<label>Server IP (optional)
						<input type="text" name="ip" placeholder="real IP if DNS is proxied" maxlength="45" autocomplete="off">
					</label>
					<label>Location (optional)
						<input type="text" name="location" placeholder="e.g. Los Angeles, US" maxlength="120" autocomplete="off">
					</label>
				</div>
				<div class="row-3">
					<label>Scheme
						<select name="scheme">
							<option value="https">https</option>
							<option value="http">http</option>
						</select>
					</label>
					<label>Hostname / host
						<input type="text" name="host" placeholder="edge.example.com" required autocomplete="off">
					</label>
					<label>Port
						<input type="text" name="port" placeholder="default" inputmode="numeric" autocomplete="off">
					</label>
				</div>
				<label>Control script path
					<input type="text" name="path" value="/remote_control.php" autocomplete="off">
				</label>
			</div>
			<div class="modal-footer">
				<button type="button" class="ghost" id="add-modal-cancel">Cancel</button>
				<button type="submit" class="primary">Add server</button>
			</div>
		</form>
	</div>
</div>

<script>
var POLL_MS = <?php echo max(3, intval($poll_interval_seconds)) * 1000; ?>;
var CSRF = <?php echo json_encode($csrf); ?>;
var SELF = <?php echo json_encode(strtok($_SERVER['REQUEST_URI'], '?')); ?>;

var STATUS = {
	edge:    { label: 'EDGE',          cls: 'st-edge' },
	online:  { label: 'Origin off',    cls: 'st-online' },
	no_api:  { label: 'Stock',         cls: 'st-stock' },
	auth:    { label: 'Auth failed',   cls: 'st-auth' },
	offline: { label: 'Offline',       cls: 'st-offline' }
};

function esc(s)
{
	return String(s == null ? '' : s).replace(/[&<>"']/g, function (c)
	{
		return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
	});
}

function fmtBytes(b)
{
	if (b == null || isNaN(b) || b === false || b < 0) return '–';
	var u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
	b = Number(b);
	while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
	return (i === 0 ? Math.round(b) : b.toFixed(b >= 100 ? 0 : 1)) + ' ' + u[i];
}

// bytes/sec → bits/sec (SI 1000), e.g. 2.6 Gbps not 325 MB/s
function fmtRate(bytesPerSec)
{
	if (bytesPerSec == null || isNaN(bytesPerSec) || bytesPerSec === false || bytesPerSec < 0) return null;
	var bits = Number(bytesPerSec) * 8;
	var u = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'], i = 0;
	while (bits >= 1000 && i < u.length - 1) { bits /= 1000; i++; }
	var n = (i === 0) ? Math.round(bits) : bits.toFixed(bits >= 100 ? 0 : (bits >= 10 ? 1 : 2));
	return n + ' ' + u[i];
}

function fmtNum(n)
{
	if (n == null || isNaN(n)) return '–';
	return Number(n).toLocaleString('en-US');
}

function bar(pct)
{
	if (pct == null) return '';
	var cls = pct >= 90 ? 'crit' : (pct >= 70 ? 'warn' : '');
	return '<div class="bar"><i class="' + cls + '" style="width:' + Math.max(0, Math.min(100, pct)).toFixed(1) + '%"></i></div>';
}

function metric(label, valueHtml, subHtml, spanClass)
{
	return '<div class="metric' + (spanClass ? ' ' + spanClass : '') + '">'
		+ '<div class="mk">' + esc(label) + '</div>'
		+ '<div class="mv">' + valueHtml + '</div>'
		+ (subHtml ? '<div class="ms">' + subHtml + '</div>' : '')
		+ '</div>';
}

function drawSpark(canvas, tx, rx)
{
	if (!canvas) return;
	var dpr = window.devicePixelRatio || 1;
	var w = canvas.clientWidth || 280;
	var h = canvas.clientHeight || 44;
	canvas.width = Math.max(1, Math.floor(w * dpr));
	canvas.height = Math.max(1, Math.floor(h * dpr));
	var ctx = canvas.getContext('2d');
	ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
	ctx.clearRect(0, 0, w, h);

	var n = Math.max(tx.length, rx.length);
	if (n < 2)
	{
		ctx.fillStyle = '#3a4658';
		ctx.font = '11px sans-serif';
		ctx.fillText('Collecting history…', 4, h / 2 + 4);
		return;
	}

	var vals = [];
	for (var i = 0; i < n; i++)
	{
		if (tx[i] != null) vals.push(tx[i]);
		if (rx[i] != null) vals.push(rx[i]);
	}
	var max = 0;
	vals.forEach(function (v) { if (v > max) max = v; });
	if (max <= 0) max = 1;

	function series(arr, color, fill)
	{
		var pts = [];
		for (var i = 0; i < n; i++)
		{
			var v = arr[i];
			if (v == null) continue;
			var x = (n === 1) ? 0 : (i / (n - 1)) * (w - 2) + 1;
			var y = h - 3 - (v / max) * (h - 8);
			pts.push([x, y]);
		}
		if (pts.length < 2) return;
		ctx.beginPath();
		ctx.moveTo(pts[0][0], pts[0][1]);
		for (var j = 1; j < pts.length; j++) ctx.lineTo(pts[j][0], pts[j][1]);
		ctx.strokeStyle = color;
		ctx.lineWidth = 1.6;
		ctx.lineJoin = 'round';
		ctx.stroke();
		if (fill)
		{
			ctx.lineTo(pts[pts.length - 1][0], h);
			ctx.lineTo(pts[0][0], h);
			ctx.closePath();
			ctx.fillStyle = fill;
			ctx.fill();
		}
	}

	// Draw rx first (under), then tx.
	series(rx, '#3ecf8e', 'rgba(62,207,142,.10)');
	series(tx, '#4f9cf0', 'rgba(79,156,240,.12)');
}

function render(data)
{
	var cards = '', totals = { edges: 0, online: 0, count: 0, disk_total: 0, disk_free: 0, rx: 0, tx: 0, have_rates: false, videos: 0, cache_bytes: 0, plays: 0, have_plays: false, have_videos: false };
	var sparkJobs = [];

	data.servers.forEach(function (srv)
	{
		totals.count++;
		var st = STATUS[srv.status] || STATUS.offline;
		var stats = srv.stats;
		if (srv.status === 'edge') totals.edges++;
		if (srv.status === 'edge' || srv.status === 'online') totals.online++;

		var cpuVal = '–', loadVal = '–', loadSub = '';
		var diskVal = '–', diskSub = '', diskBar = '';
		var cacheVal = '–', cacheSub = '', playsVal = '–', playsSub = '';
		var bwHtml = '<span class="muted">–</span>';

		var disk = stats ? stats.disk : (srv.basic ? srv.basic.disk : null);
		if (disk && disk.total > 0)
		{
			var used = disk.total - disk.free, pct = 100 * used / disk.total;
			totals.disk_total += Number(disk.total);
			totals.disk_free += Number(disk.free);
			diskVal = fmtBytes(disk.free) + ' free';
			diskSub = fmtBytes(disk.total) + ' total · ' + pct.toFixed(1) + '% used';
			diskBar = bar(pct);
		}

		if (srv.cpu_pct != null)
		{
			cpuVal = Number(srv.cpu_pct).toFixed(1) + '%' + bar(srv.cpu_pct);
		}
		else if (stats && stats.cpu)
		{
			cpuVal = '<span class="muted">measuring…</span>';
		}

		if (stats)
		{
			var l1 = (stats.loadavg && stats.loadavg.length) ? Number(stats.loadavg[0]) : null;
			if (l1 != null)
			{
				loadVal = l1.toFixed(2);
				if (stats.cores > 0)
				{
					loadSub = (l1 / stats.cores).toFixed(2) + ' /core · ' + stats.cores + ' cores';
				}
			}

			if (srv.rates)
			{
				bwHtml = '<div class="pair">'
					+ '<div><span class="lbl">In</span>↓ ' + esc(fmtRate(srv.rates.rx)) + '</div>'
					+ '<div><span class="lbl">Out</span>↑ ' + esc(fmtRate(srv.rates.tx)) + '</div>'
					+ '</div>';
				totals.rx += Number(srv.rates.rx) || 0;
				totals.tx += Number(srv.rates.tx) || 0;
				totals.have_rates = true;
			}
			else if (stats.net)
			{
				bwHtml = '<span class="muted">measuring…</span>';
			}

			if (stats.cache)
			{
				cacheVal = fmtNum(stats.cache.videos);
				cacheSub = fmtNum(stats.cache.video_files) + ' files · ' + fmtBytes(stats.cache.bytes);
				totals.videos += Number(stats.cache.videos) || 0;
				totals.cache_bytes += Number(stats.cache.bytes) || 0;
				totals.have_videos = true;
			}
			else
			{
				cacheVal = '<span class="muted">counting…</span>';
			}

			if (stats.views_15m != null)
			{
				playsVal = fmtNum(stats.views_15m);
				if (stats.views_24h != null) playsSub = fmtNum(stats.views_24h) + ' / 24h';
				totals.plays += Number(stats.views_15m) || 0;
				totals.have_plays = true;
			}
		}
		else if (srv.basic && srv.basic.load != null)
		{
			loadVal = Number(srv.basic.load).toFixed(2);
		}

		var sparkId = 'spark-' + srv.id;
		bwHtml += '<div class="spark-wrap"><canvas id="' + sparkId + '"></canvas></div>'
			+ '<div class="spark-legend"><span class="tx">Out</span><span class="rx">In</span></div>';

		var statusClass = 'is-' + (srv.status === 'no_api' ? 'stock' : (srv.status || 'offline'));
		// IP under the name/URL (more room than the badge row).
		var ipLine = srv.ip
			? '<div class="loc" style="font-family:ui-monospace,Menlo,Consolas,monospace"><span class="muted">IP</span> <span class="ip-chip" style="margin-left:4px">' + esc(srv.ip) + '</span></div>'
			: '';
		var locHtml = srv.location
			? '<div class="loc" title="' + esc(srv.location) + '"><span class="pin">📍</span>' + esc(srv.location) + '</div>'
			: '';

		cards += '<article class="card ' + statusClass + '" data-id="' + esc(srv.id) + '" tabindex="0" role="link" aria-label="Open details for ' + esc(srv.name) + '">'
			+ '<div class="card-head">'
			+ '<div class="card-title">'
			+ '<div class="name-row"><h3 title="' + esc(srv.name) + '">' + esc(srv.name) + '</h3></div>'
			+ '<div class="url" title="' + esc(srv.url) + '">' + esc(srv.url) + '</div>'
			+ ipLine
			+ locHtml
			+ '</div>'
			+ '<div class="card-actions">'
			+ '<span class="badge ' + st.cls + '">' + st.label + '</span>'
			+ '<button class="ghost log-dl" data-id="' + esc(srv.id) + '" data-name="' + esc(srv.name) + '" title="Download monitoring history CSV" type="button" aria-label="Download status history" style="padding:6px 9px;font-size:12px">Log</button>'
			+ '<button class="del" data-id="' + esc(srv.id) + '" title="Remove from dashboard" type="button" aria-label="Remove">&#10005;</button>'
			+ '</div>'
			+ '</div>'
			+ (srv.error ? '<div class="card-error">' + esc(srv.error) + '</div>' : '')
			+ '<div class="card-metrics">'
			+ metric('CPU', cpuVal, '', '')
			+ metric('Load', loadVal, loadSub, '')
			+ metric('Plays 15m', playsVal, playsSub, '')
			+ metric('Bandwidth', bwHtml, '', 'span-3')
			+ metric('Storage', diskVal + diskBar, diskSub, 'span-2')
			+ metric('Cached videos', cacheVal, cacheSub, '')
			+ '</div>'
			+ '</article>';

		sparkJobs.push({
			id: sparkId,
			tx: (srv.spark && srv.spark.tx) ? srv.spark.tx : [],
			rx: (srv.spark && srv.spark.rx) ? srv.spark.rx : []
		});
	});

	if (!cards)
	{
		cards = '<div class="empty-state">'
			+ '<strong>No servers yet</strong>'
			+ 'Register a cache edge to start monitoring health, storage, and traffic.'
			+ '<div class="cta-hint"><button type="button" class="primary" id="empty-add-btn">+ Add server</button></div>'
			+ '</div>';
	}
	document.getElementById('cards').innerHTML = cards;

	var emptyBtn = document.getElementById('empty-add-btn');
	if (emptyBtn) emptyBtn.addEventListener('click', openAddModal);

	// Draw sparklines after layout so canvas widths are known.
	requestAnimationFrame(function ()
	{
		sparkJobs.forEach(function (j)
		{
			drawSpark(document.getElementById(j.id), j.tx, j.rx);
		});
	});

	var countEl = document.getElementById('server-count');
	if (countEl)
	{
		countEl.textContent = totals.count
			? (totals.count + ' registered · ' + totals.online + ' responding')
			: 'none registered';
	}

	document.getElementById('t-edges').textContent = totals.edges + ' / ' + totals.count;
	document.getElementById('t-servers').textContent = totals.online + ' responding';
	document.getElementById('t-total').textContent = totals.disk_total > 0 ? fmtBytes(totals.disk_total) : '–';
	document.getElementById('t-free').textContent = totals.disk_total > 0 ? fmtBytes(totals.disk_free) : '–';
	document.getElementById('t-tx').textContent = totals.have_rates ? '↑ ' + fmtRate(totals.tx) : '–';
	document.getElementById('t-rx').textContent = totals.have_rates ? '↓ ' + fmtRate(totals.rx) + ' in' : '';
	document.getElementById('t-videos').textContent = totals.have_videos ? fmtNum(totals.videos) : '–';
	document.getElementById('t-cachebytes').textContent = totals.cache_bytes > 0 ? fmtBytes(totals.cache_bytes) + ' cached' : '';
	document.getElementById('t-plays').textContent = totals.have_plays ? fmtNum(totals.plays) : '–';
	document.getElementById('updated').textContent = 'updated ' + new Date().toLocaleTimeString()
		+ (data.history ? '' : ' · history unavailable (pdo_sqlite)');
}

function poll()
{
	fetch('?ajax=poll', { cache: 'no-store' }).then(function (r)
	{
		if (r.status === 401) { location.reload(); throw new Error('unauthorized'); }
		return r.json();
	}).then(function (data)
	{
		document.getElementById('conn-error').classList.add('hidden');
		var dot = document.getElementById('live-dot');
		if (dot) dot.classList.remove('err');
		render(data);
	}).catch(function ()
	{
		document.getElementById('conn-error').classList.remove('hidden');
		var dot = document.getElementById('live-dot');
		if (dot) dot.classList.add('err');
	});
}

// ---- card click → detail; status history download; delete ----
function downloadServerLog(id, btn)
{
	if (btn) btn.disabled = true;
	fetch('?ajax=statuslog&id=' + encodeURIComponent(id) + '&range=7d&format=csv', { cache: 'no-store' })
		.then(function (r)
		{
			if (r.status === 401) { location.reload(); throw new Error('unauthorized'); }
			var ct = (r.headers.get('content-type') || '').toLowerCase();
			if (!r.ok || ct.indexOf('application/json') !== -1)
			{
				return r.text().then(function (t)
				{
					var msg = 'History download failed (HTTP ' + r.status + ')';
					try
					{
						var j = JSON.parse(t);
						if (j && j.error) msg = j.error;
					}
					catch (e) {}
					throw new Error(msg);
				});
			}
			var disp = r.headers.get('content-disposition') || '';
			var fname = id + '-status-7d.csv';
			var m = /filename="([^"]+)"/i.exec(disp);
			if (m) fname = m[1];
			return r.blob().then(function (blob) { return { blob: blob, fname: fname }; });
		})
		.then(function (res)
		{
			var a = document.createElement('a');
			a.href = URL.createObjectURL(res.blob);
			a.download = res.fname;
			document.body.appendChild(a);
			a.click();
			setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
			if (btn) btn.disabled = false;
		})
		.catch(function (err)
		{
			if (btn) btn.disabled = false;
			alert(err && err.message ? err.message : 'History download failed');
		});
}

document.getElementById('cards').addEventListener('click', function (e)
{
	var logBtn = e.target.closest('button.log-dl');
	if (logBtn)
	{
		e.preventDefault();
		e.stopPropagation();
		downloadServerLog(logBtn.getAttribute('data-id'), logBtn);
		return;
	}
	var btn = e.target.closest('button.del');
	if (btn)
	{
		e.preventDefault();
		e.stopPropagation();
		if (!confirm('Remove this server from the dashboard? (Nothing is changed on the server itself.)')) return;
		var body = new URLSearchParams({ action: 'delete', id: btn.getAttribute('data-id'), csrf: CSRF, ajax: '1' });
		fetch('', { method: 'POST', body: body }).then(function () { poll(); });
		return;
	}
	var card = e.target.closest('article.card');
	if (card && card.getAttribute('data-id'))
	{
		location.href = SELF + '?server=' + encodeURIComponent(card.getAttribute('data-id'));
	}
});

document.getElementById('cards').addEventListener('keydown', function (e)
{
	if (e.key !== 'Enter' && e.key !== ' ') return;
	var card = e.target.closest('article.card');
	if (!card || !card.getAttribute('data-id')) return;
	e.preventDefault();
	location.href = SELF + '?server=' + encodeURIComponent(card.getAttribute('data-id'));
});

// ---- add-server modal ----
var addModal = document.getElementById('add-modal');
var addForm = document.getElementById('add-form');
var hostInput = addForm.querySelector('input[name="host"]');

function openAddModal()
{
	addModal.hidden = false;
	addModal.classList.add('open');
	document.body.classList.add('modal-open');
	setTimeout(function () { hostInput && hostInput.focus(); }, 30);
}

function closeAddModal()
{
	addModal.classList.remove('open');
	addModal.hidden = true;
	document.body.classList.remove('modal-open');
}

document.getElementById('btn-add-server').addEventListener('click', openAddModal);
document.getElementById('add-modal-close').addEventListener('click', closeAddModal);
document.getElementById('add-modal-cancel').addEventListener('click', closeAddModal);

addModal.addEventListener('click', function (e)
{
	if (e.target === addModal) closeAddModal();
});

document.addEventListener('keydown', function (e)
{
	if (e.key === 'Escape' && addModal.classList.contains('open')) closeAddModal();
});

<?php
$reopen_add = ($err !== ''
	&& stripos($err, 'password') === false
	&& stripos($err, 'Session') === false
	&& stripos($err, 'authorized') === false
	&& stripos($err, 'not found') === false);
if ($reopen_add) {
	echo "openAddModal();\n";
}
?>

poll();
setInterval(poll, POLL_MS);
</script>

<?php } ?>

</body>
</html>
