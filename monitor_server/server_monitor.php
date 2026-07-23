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

   Requirements:
     - the monitored servers must run the edge build of remote_control.php
       (edge_storage_server/edge_remote_control .php), which provides the
       authenticated action=monitor JSON API. Servers running the stock KVS
       remote_control.php are still probed via action=status and show load +
       storage only, flagged as "stock".
     - this host needs the curl extension (preferred; enables parallel polling)
       or allow_url_fopen=On.
     - this script's directory must be writable: the server list is stored next
       to it in monitor_servers.dat.php (self-blocking - answers 404 if opened
       directly).
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
$config['cv'] = "ed2317c592f17c6dcb43dd56cd3e4c1c";

// Seconds allowed for each server probe. Servers are polled in parallel, so a
// full refresh takes about this long in the worst case, not timeout x servers.
$probe_timeout = 5;

// Seconds between automatic dashboard refreshes. Also the averaging window for
// the CPU % and bandwidth figures (they are deltas between two polls).
$poll_interval_seconds = 10;

// Where the list of monitored servers is stored (created automatically).
$servers_file = __DIR__ . '/monitor_servers.dat.php';

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
	return is_array($list) ? array_values($list) : array();
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

// ------------------------------ HTTP probing ------------------------------

// GET one URL; returns array(http => status code (0 = no response), body, error).
function monitor_http_get($url, $timeout)
{
	if (function_exists('curl_init'))
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		$body = curl_exec($ch);
		$res = array(
			'http'  => intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)),
			'body'  => ($body === false) ? '' : (string) $body,
			'error' => (string) curl_error($ch),
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
		if (isset($http_response_header) && is_array($http_response_header))
		{
			// the last status line wins after redirects
			foreach ($http_response_header as $line)
			{
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m))
				{
					$http = intval($m[1]);
				}
			}
		}
		return array(
			'http'  => $http,
			'body'  => ($body === false) ? '' : (string) $body,
			'error' => ($body === false && $http == 0) ? 'connection failed' : '',
		);
	}

	return array('http' => 0, 'body' => '', 'error' => 'no HTTP transport on the dashboard host (enable curl or allow_url_fopen)');
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
				usleep(100000); // select not supported for these handles: avoid a hot loop
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

	if ($action === 'login')
	{
		if (!$csrf_ok)
		{
			// Same symptoms as a bad password when the session cookie is stale
			// (common after switching from php -S as root to nginx/php-fpm).
			$redirect_err = 'Session expired - refresh the page and try again.';
		} elseif (hash_equals($monitor_password, (string) $_POST['password']))
		{
			session_regenerate_id(true);
			$_SESSION['monitor_authed'] = true;
			$_SESSION['monitor_csrf'] = md5(uniqid(mt_rand(), true) . mt_rand());
		} else
		{
			sleep(1); // slow brute forcing down
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
		$server = array(
			'id'     => substr(md5(uniqid(mt_rand(), true)), 0, 10),
			'name'   => trim((string) $_POST['name']),
			'scheme' => ($_POST['scheme'] === 'https') ? 'https' : 'http',
			'host'   => trim((string) $_POST['host']),
			'port'   => trim((string) $_POST['port']),
			'path'   => trim((string) $_POST['path']),
		);
		if ($server['host'] === '' || !preg_match('#^[A-Za-z0-9.\-\[\]:]+$#', $server['host']))
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
	$self = strtok($_SERVER['REQUEST_URI'], '?');
	header('Location: ' . $self . ($redirect_err !== '' ? '?err=' . rawurlencode($redirect_err) : ''));
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

	$out = array();
	$need_basic = array();
	foreach ($servers as $s)
	{
		$res = isset($results[$s['id']]) ? $results[$s['id']] : array('http' => 0, 'body' => '', 'error' => 'no result');
		list($status, $stats) = monitor_classify($res);
		$out[$s['id']] = array(
			'id'     => $s['id'],
			'name'   => $s['name'],
			'url'    => monitor_server_url($s),
			'status' => $status,
			'error'  => trim($res['error']),
			'stats'  => $stats,
			'basic'  => null,
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

	echo json_encode(array('ok' => true, 'time' => time(), 'servers' => array_values($out)));
	die;
}

// ------------------------------ HTML ------------------------------

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$err = isset($_GET['err']) ? (string) $_GET['err'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Edge server monitor</title>
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
	a { color: var(--blue); }
	.muted { color: var(--muted); }
	.small { font-size: 12px; }
	.sub { font-size: 12px; color: var(--muted); margin-top: 2px; }

	/* Page shell */
	.shell { max-width: var(--max); margin: 0 auto; padding: 20px 22px 36px; }

	/* Sticky header */
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

	/* Alerts */
	.banner {
		background: rgba(120, 36, 48, .35);
		border: 1px solid rgba(240, 113, 120, .35);
		color: #ffd5d8;
		border-radius: 10px;
		padding: 11px 14px;
		margin-bottom: 16px;
	}
	.banner.hidden { display: none; }

	/* Sections */
	.section { margin-bottom: 22px; }
	.section-head {
		display: flex; align-items: baseline; justify-content: space-between;
		gap: 12px; margin-bottom: 12px;
	}
	.section-head h2 {
		font-size: 12px; font-weight: 700; letter-spacing: .08em;
		text-transform: uppercase; color: var(--muted);
	}
	.section-head .count {
		font-size: 12px; color: var(--muted-2);
	}

	/* KPI overview */
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

	/* Server cards */
	.cards {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
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
		padding: 16px 16px 12px 18px;
		border-bottom: 1px solid var(--line-soft);
	}
	.card-title { flex: 1; min-width: 0; }
	.card-title .name-row {
		display: flex; align-items: center; gap: 8px; min-width: 0;
	}
	.card-title h3 {
		font-size: 15px; font-weight: 700; letter-spacing: -0.01em;
		white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
	}
	.card-title .url {
		font-size: 12px; color: var(--muted);
		white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
		margin-top: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
	}
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

	/* Modal */
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

	/* Login */
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

	/* Responsive */
	@media (max-width: 1100px) {
		.tiles { grid-template-columns: repeat(3, minmax(0, 1fr)); }
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
	}
</style>
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
			CPU % and bandwidth are averaged between two polls, so they read &ldquo;measuring&hellip;&rdquo; on the first refresh.
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
				<div class="row-3">
					<label>Scheme
						<select name="scheme">
							<option value="https">https</option>
							<option value="http">http</option>
						</select>
					</label>
					<label>IP / host
						<input type="text" name="host" placeholder="203.0.113.10" required autocomplete="off">
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

var STATUS = {
	edge:    { label: 'EDGE',          cls: 'st-edge' },
	online:  { label: 'Origin off',    cls: 'st-online' },
	no_api:  { label: 'Stock',         cls: 'st-stock' },
	auth:    { label: 'Auth failed',   cls: 'st-auth' },
	offline: { label: 'Offline',       cls: 'st-offline' }
};

var prev = {}; // server id -> {time, cpu:{total,idle}, net:{rx,tx}}

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

function fmtRate(b)
{
	return (b == null) ? null : fmtBytes(b) + '/s';
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

// CPU % between the previous and current cumulative jiffie samples.
function cpuPct(p, c)
{
	if (!p || !p.cpu || !c || !c.cpu) return null;
	var dt = c.cpu.total - p.cpu.total, di = c.cpu.idle - p.cpu.idle;
	if (dt <= 0 || di < 0 || di > dt) return null;
	return 100 * (1 - di / dt);
}

// rx/tx bytes-per-second between two polls (null until two samples exist, or
// after a counter reset e.g. on reboot).
function netRates(p, c)
{
	if (!p || !p.net || !c || !c.net) return null;
	var dt = c.time - p.time;
	if (dt <= 0) return null;
	var rx = (c.net.rx_bytes - p.net.rx_bytes) / dt;
	var tx = (c.net.tx_bytes - p.net.tx_bytes) / dt;
	if (rx < 0 || tx < 0) return null;
	return { rx: rx, tx: tx };
}

function metric(label, valueHtml, subHtml, spanClass)
{
	return '<div class="metric' + (spanClass ? ' ' + spanClass : '') + '">'
		+ '<div class="mk">' + esc(label) + '</div>'
		+ '<div class="mv">' + valueHtml + '</div>'
		+ (subHtml ? '<div class="ms">' + subHtml + '</div>' : '')
		+ '</div>';
}

function render(data)
{
	var cards = '', totals = { edges: 0, online: 0, count: 0, disk_total: 0, disk_free: 0, rx: 0, tx: 0, have_rates: false, videos: 0, cache_bytes: 0, plays: 0, have_plays: false, have_videos: false };

	data.servers.forEach(function (srv)
	{
		totals.count++;
		var st = STATUS[srv.status] || STATUS.offline;
		var stats = srv.stats, p = prev[srv.id];
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

		if (stats)
		{
			var pct2 = cpuPct(p, stats);
			if (pct2 != null)
			{
				cpuVal = pct2.toFixed(1) + '%' + bar(pct2);
			} else if (stats.cpu)
			{
				cpuVal = '<span class="muted">measuring…</span>';
			}

			var l1 = (stats.loadavg && stats.loadavg.length) ? Number(stats.loadavg[0]) : null;
			if (l1 != null)
			{
				loadVal = l1.toFixed(2);
				if (stats.cores > 0)
				{
					loadSub = (l1 / stats.cores).toFixed(2) + ' /core · ' + stats.cores + ' cores';
				}
			}

			var rates = netRates(p, stats);
			if (rates != null)
			{
				bwHtml = '<div class="pair">'
					+ '<div><span class="lbl">In</span>↓ ' + esc(fmtRate(rates.rx)) + '</div>'
					+ '<div><span class="lbl">Out</span>↑ ' + esc(fmtRate(rates.tx)) + '</div>'
					+ '</div>';
				totals.rx += rates.rx;
				totals.tx += rates.tx;
				totals.have_rates = true;
			} else if (stats.net)
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
			} else
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

			prev[srv.id] = { time: stats.time, cpu: stats.cpu, net: stats.net };
		} else
		{
			if (srv.basic && srv.basic.load != null) loadVal = Number(srv.basic.load).toFixed(2);
			delete prev[srv.id];
		}

		var statusClass = 'is-' + (srv.status === 'no_api' ? 'stock' : (srv.status || 'offline'));

		cards += '<article class="card ' + statusClass + '" data-id="' + esc(srv.id) + '">'
			+ '<div class="card-head">'
			+ '<div class="card-title">'
			+ '<div class="name-row"><h3 title="' + esc(srv.name) + '">' + esc(srv.name) + '</h3></div>'
			+ '<div class="url" title="' + esc(srv.url) + '">' + esc(srv.url) + '</div>'
			+ '</div>'
			+ '<div class="card-actions">'
			+ '<span class="badge ' + st.cls + '">' + st.label + '</span>'
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
	document.getElementById('updated').textContent = 'updated ' + new Date().toLocaleTimeString();
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

// ---- delete server ----
document.getElementById('cards').addEventListener('click', function (e)
{
	var btn = e.target.closest('button.del');
	if (!btn) return;
	if (!confirm('Remove this server from the dashboard? (Nothing is changed on the server itself.)')) return;
	var body = new URLSearchParams({ action: 'delete', id: btn.getAttribute('data-id'), csrf: CSRF, ajax: '1' });
	fetch('', { method: 'POST', body: body }).then(function () { poll(); });
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

// Re-open add modal when a validation error came back from the add form
<?php
$reopen_add = ($err !== ''
	&& stripos($err, 'password') === false
	&& stripos($err, 'Session') === false
	&& stripos($err, 'authorized') === false);
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

