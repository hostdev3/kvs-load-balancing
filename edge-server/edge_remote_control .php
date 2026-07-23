<?php
/* Developed by Kernel Team.
   http://kernel-team.com
*/

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);
$api_version = '5.3.0';

// comma separated list of whitelisted IPs
$whitelist_ips = "";

// comma separated list of whitelisted referers
$whitelist_referers = "";

// the number of seconds temp links are valid
$ttl = 3600;

// ------------------------------- CDN origin -------------------------------
// This script can act as a caching edge: files it does not have yet are pulled
// on demand from the origin (main) server through its get_file.php, cached to
// local disk, and served. The pull link is signed with $config['cv'] below
// (the same secret the main server uses), so no extra credentials are needed.
//
// $origin_url        base URL of the main server, e.g. "https://www.example.com"
//                    (leave empty to disable pull-through caching and only serve
//                    files that already exist locally).
// $origin_server_id  the "admin_rq_server_id" of the server that holds the
//                    master copy of the files (usually the main server). This
//                    forces get_file.php to deliver that server's copy directly.
// $origin_sg_id      any non-zero storage-group id (its value is irrelevant when
//                    $origin_server_id matches, but it must not be 0).
// $origin_content_prefix
//                    the path prefix under which this edge stores content (this
//                    server's "urls" path portion). The incoming "file" arrives
//                    storage-prefixed (e.g. "/videos/29000/29350/f.mp4"); this
//                    prefix is stripped to recover the origin's logical file
//                    ("29000/29350/f.mp4") that get_file.php signs its hash over.
//                    Leave empty if this script lives inside the content folder.
$origin_url = "https://baddies.xxx";
$origin_server_id = "8";
$origin_sg_id = 7;
$origin_content_prefix = "videos";

// set true to append pull-through cache diagnostics to remote_control_cdn.log
// (in this script's directory). Turn off once caching is verified.
$cdn_debug = true;

// SQLite database (next to this script) in which each served file's last-view
// time is recorded, so the edge_prewarm.php cleanup cron can evict files nobody
// watches. Must match $view_db_path in edge_prewarm.php. Requires the pdo_sqlite
// PHP extension; leave empty (or if the extension is missing) to disable tracking.
$view_db_path = __DIR__ . '/edge_cache.sqlite';

// Real-time disk guard. When caching a newly-watched file would leave less than
// this many bytes free on the content volume, the least-recently-viewed cached
// files (per the view db above) are evicted until free space recovers - so a burst
// of first-time views between cron runs cannot fill the disk. Top-list videos that
// edge_prewarm.php publishes to the db are never evicted. 0 disables the real-time
// guard (edge_prewarm.php still enforces its limits on schedule). Needs pdo_sqlite.
$cache_min_free_bytes = 0;   // e.g. 20 * 1024 * 1024 * 1024 to keep ~20 GB free

######################################################################################

$config['cv']="ed2317c592f17c6dcb43dd56cd3e4c1c";

if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
{
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
	if (strpos($_SERVER['REMOTE_ADDR'], ',') !== false)
	{
		$_SERVER['REMOTE_ADDR'] = trim(substr($_SERVER['REMOTE_ADDR'], 0, strpos($_SERVER['REMOTE_ADDR'], ',')));
	}
} elseif (isset($_SERVER['HTTP_X_REAL_IP']))
{
	$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_REAL_IP'];
}

if ($_REQUEST['action'] == '' && $_REQUEST['file'] == '')
{
	echo "connected.";
	die;
} elseif ($_REQUEST['action'] == 'version')
{
	echo $api_version;
	die;
} elseif ($_REQUEST['action'] == 'ip')
{
	echo $_SERVER['REMOTE_ADDR'];
	die;
} elseif ($_REQUEST['action'] == 'path')
{
	if ($_REQUEST['cv'] != $config['cv'])
	{
		sleep(1);
		http_response_code(403);
		header("KVS-Errno: 2");
		echo "Access denied (errno 2)";
		die;
	}
	echo dirname($_SERVER['SCRIPT_FILENAME']);
} elseif ($_REQUEST['action'] == 'cdncheck')
{
	// Diagnostic: report (and actually attempt) whether this edge can write the
	// CDN cache log and the content/cache directory, returning a plain-text
	// report in the body so failures are directly visible. Auth'd with cv, like
	// the 'path' action. Hit: /remote_control.php?action=cdncheck&cv=<config cv>
	if ($_REQUEST['cv'] != $config['cv'])
	{
		sleep(1);
		http_response_code(403);
		header("KVS-Errno: 2");
		echo "Access denied (errno 2)";
		die;
	}
	header('Content-Type: text/plain; charset=utf-8');
	$script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
	$log = $script_dir . '/remote_control_cdn.log';
	$content_dir = rtrim(str_replace('\\', '/', $script_dir), '/') . '/' . trim(str_replace('\\', '/', $origin_content_prefix), '/');

	$user = '?';
	if (function_exists('posix_geteuid') && function_exists('posix_getpwuid'))
	{
		$pw = @posix_getpwuid(posix_geteuid());
		$user = $pw ? $pw['name'] : (string) @posix_geteuid();
	} elseif (getenv('USERNAME') || getenv('USER'))
	{
		$user = getenv('USERNAME') ?: getenv('USER');
	}

	$log_test = @file_put_contents($log, date('[Y-m-d H:i:s] ') . "cdncheck self-test write\n", FILE_APPEND | LOCK_EX);

	echo "php_process_user : $user\n";
	echo "cdn_debug        : " . ($cdn_debug ? 'on' : 'off') . "\n";
	echo "curl_available   : " . (function_exists('curl_init') ? 'yes' : 'no') . "\n";
	echo "allow_url_fopen  : " . (ini_get('allow_url_fopen') ? 'yes' : 'no') . "\n";
	echo "\n";
	echo "script_dir       : $script_dir\n";
	echo "script_dir_write : " . (is_writable($script_dir) ? 'yes' : 'NO') . "\n";
	echo "\n";
	echo "log_path         : $log\n";
	echo "log_exists       : " . (is_file($log) ? 'yes' : 'no') . "\n";
	echo "log_writable     : " . (is_file($log) ? (is_writable($log) ? 'yes' : 'NO') : 'n/a') . "\n";
	echo "log_write_test   : " . ($log_test === false ? 'FAILED (cannot write log)' : "ok ($log_test bytes appended)") . "\n";
	echo "\n";
	echo "content_dir      : $content_dir\n";
	echo "content_exists   : " . (is_dir($content_dir) ? 'yes' : 'no') . "\n";
	echo "content_writable : " . ((is_dir($content_dir) && is_writable($content_dir)) ? 'yes' : 'NO') . "\n";
	die;
} elseif ($_REQUEST['action'] == 'status')
{
	if (function_exists('sys_getloadavg'))
	{
		$load = sys_getloadavg();
	} else
	{
		$load = [0];
	}
	$load = floatval($load[0]);
	if ($_REQUEST['content_path'] != '' && (is_dir(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]") || is_link(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]")))
	{
		$total_space = @disk_total_space(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]");
		$free_space = @disk_free_space(dirname($_SERVER['SCRIPT_FILENAME']) . "/$_REQUEST[content_path]");
	} else
	{
		$total_space = @disk_total_space(dirname($_SERVER['SCRIPT_FILENAME']));
		$free_space = @disk_free_space(dirname($_SERVER['SCRIPT_FILENAME']));
	}
	echo "$load|$total_space|$free_space";
	die;
} elseif ($_REQUEST['action'] == 'load')
{
	// Lightweight load probe for the main server's load-based balancing in
	// get_file.php: returns "<1-min loadavg>|<cpu cores>" so the caller can
	// compare load per core. cores=0 when the count cannot be determined.
	$load = 0;
	if (function_exists('sys_getloadavg'))
	{
		$la = sys_getloadavg();
		$load = floatval($la[0]);
	}
	$cores = 0;
	if (@is_readable('/proc/cpuinfo'))
	{
		$cores = preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'));
	}
	echo "$load|" . intval($cores);
	die;
} elseif ($_REQUEST['action'] == 'monitor')
{
	// Aggregated JSON stats for the standalone monitoring dashboard
	// (monitor_server/server_monitor.php): CPU, disk, network and cache counters
	// in one authenticated call. CPU jiffies and network byte counters are
	// cumulative (straight from /proc), so the dashboard computes usage rates
	// from the delta between its own polls and this endpoint stays stateless.
	if ($_REQUEST['cv'] != $config['cv'])
	{
		sleep(1);
		http_response_code(403);
		header("KVS-Errno: 2");
		echo "Access denied (errno 2)";
		die;
	}
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');

	$loadavg = array(0.0, 0.0, 0.0);
	if (function_exists('sys_getloadavg'))
	{
		$la = sys_getloadavg();
		$loadavg = array(floatval($la[0]), floatval($la[1]), floatval($la[2]));
	}

	$cores = 0;
	if (@is_readable('/proc/cpuinfo'))
	{
		$cores = preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo'));
	}

	// cumulative CPU jiffies since boot: total across all states, and idle+iowait
	$cpu = null;
	if (@is_readable('/proc/stat') && preg_match('/^cpu\s+(.+)$/m', (string) @file_get_contents('/proc/stat'), $m))
	{
		$fields = preg_split('/\s+/', trim($m[1]));
		if (count($fields) >= 4 && is_numeric($fields[0]))
		{
			$total = 0;
			$n = min(8, count($fields));
			for ($i = 0; $i < $n; $i++)
			{
				$total += floatval($fields[$i]);
			}
			$cpu = array('total' => $total, 'idle' => floatval($fields[3]) + (isset($fields[4]) ? floatval($fields[4]) : 0));
		}
	}

	// cumulative network bytes since boot, summed over all non-loopback interfaces
	$net = null;
	if (@is_readable('/proc/net/dev'))
	{
		$rx = 0.0;
		$tx = 0.0;
		$found = false;
		foreach ((array) @file('/proc/net/dev') as $line)
		{
			if (strpos($line, ':') === false)
			{
				continue;
			}
			list($iface, $data) = explode(':', $line, 2);
			if (trim($iface) == 'lo')
			{
				continue;
			}
			$fields = preg_split('/\s+/', trim($data));
			if (count($fields) >= 9 && is_numeric($fields[0]))
			{
				$rx += floatval($fields[0]);
				$tx += floatval($fields[8]);
				$found = true;
			}
		}
		if ($found)
		{
			$net = array('rx_bytes' => $rx, 'tx_bytes' => $tx);
		}
	}

	$uptime = null;
	if (@is_readable('/proc/uptime'))
	{
		$u = explode(' ', trim((string) @file_get_contents('/proc/uptime')));
		if (isset($u[0]) && is_numeric($u[0]))
		{
			$uptime = intval(floatval($u[0]));
		}
	}

	// disk usage of the content/cache volume (same fallback logic as action=status)
	$script_dir = dirname($_SERVER['SCRIPT_FILENAME']);
	$prefix = trim(str_replace('\\', '/', $origin_content_prefix), '/');
	$disk_dir = ($prefix != '' && (is_dir("$script_dir/$prefix") || is_link("$script_dir/$prefix"))) ? "$script_dir/$prefix" : $script_dir;

	echo json_encode(array(
		'ok'          => true,
		'edge'        => (trim($origin_url) != ''),
		'api_version' => $api_version,
		'time'        => time(),
		'uptime'      => $uptime,
		'loadavg'     => $loadavg,
		'cores'       => intval($cores),
		'cpu'         => $cpu,
		'net'         => $net,
		'disk'        => array('total' => @disk_total_space($disk_dir), 'free' => @disk_free_space($disk_dir)),
		'cache'       => remote_monitor_cache_stats(),
		'views_15m'   => remote_monitor_recent_views(900),
		'views_24h'   => remote_monitor_recent_views(86400),
	));
	die;
} elseif ($_REQUEST['action'] == 'time')
{
	echo time();
	die;
} elseif ($_REQUEST['action'] == 'check')
{
	$content_path = $_REQUEST['content_path'];
	$paths = explode('||', $_REQUEST['files']);
	foreach ($paths as $path)
	{
		if ($path)
		{
			$path_rec = explode('|', $path);
			if ($content_path && $path_rec[0])
			{
				$path_rec[0] = "$content_path/$path_rec[0]";
			}
			if ($path_rec[1] > 0)
			{
				if (sprintf("%.0f", @filesize($path_rec[0])) != $path_rec[1])
				{
					echo "$path_rec[0] (expected size $path_rec[1])";
					die;
				}
			} else
			{
				if (sprintf("%.0f", @filesize($path_rec[0])) < 1)
				{
					echo $path_rec[0];
					die;
				}
			}
		}
	}
	echo '1';
	die;
} elseif ($_REQUEST['file'] <> '')
{
	$time = intval($_REQUEST['time']);
	$limit = intval($_REQUEST['lr']);
	$cv = trim($_REQUEST['cv2']);
	$target_file = rawurldecode($_REQUEST['file']);
	$is_download = trim($_GET['download']);

	if (strpos($target_file, 'B64') === 0)
	{
		$target_file_info = @unserialize(base64_decode(substr($target_file, 3)));

		if (!isset($target_file_info['time'], $target_file_info['cv'], $target_file_info['file']))
		{
			http_response_code(403);
			header("KVS-Errno: 2");
			echo "Access denied (errno 2)";
			die;
		}

		if ($target_file_info['time'] < time() - $ttl || $target_file_info['time'] > time() + $ttl)
		{
			http_response_code(403);
			header("KVS-Errno: 3");
			echo "Access denied (errno 3)";
			die;
		}

		$allowed_ips = explode(',', trim($_COOKIE["kt_remote_ips"]));
		if (md5($target_file_info['time'] . $target_file_info['limit'] . $target_file_info['file'] . $_SERVER['REMOTE_ADDR'] . $config['cv']) !== $target_file_info['cv'])
		{
			$ip_valid = false;
			foreach ($allowed_ips as $allowed_ip)
			{
				$allowed_ip = explode("||", $allowed_ip);
				if ($allowed_ip[1] === md5($allowed_ip[0] . $config['cv']))
				{
					if (md5($target_file_info['time'] . $target_file_info['limit'] . $target_file_info['file'] . $allowed_ip[0] . $config['cv']) === $target_file_info['cv'])
					{
						$ip_valid = true;
						break;
					}
				}
			}
			if (!$ip_valid && $whitelist_ips)
			{
				$whitelist_ips = array_map('trim', explode(',', trim($whitelist_ips)));
				foreach ($whitelist_ips as $whitelist_ip)
				{
					if ($whitelist_ip == $_SERVER['REMOTE_ADDR'] || md5($target_file_info['time'] . $target_file_info['limit'] . $target_file_info['file'] . $whitelist_ip . $config['cv']) === $target_file_info['cv'])
					{
						$ip_valid = true;
						break;
					}
				}
			}
			if (!$ip_valid)
			{
				http_response_code(403);
				header("KVS-Errno: 4");
				header("KVS-IP: $_SERVER[REMOTE_ADDR]");
				echo "Access denied (errno 4)";
				die;
			}
		} else
		{
			$has_ip_cookie = false;
			foreach ($allowed_ips as $allowed_ip)
			{
				$allowed_ip = explode("||", $allowed_ip);
				if ($allowed_ip[0] == $_SERVER['REMOTE_ADDR'])
				{
					$has_ip_cookie = true;
				}
			}
			if (!$has_ip_cookie)
			{
				$allowed_ips[] = $_SERVER['REMOTE_ADDR'] . '||' . md5($_SERVER['REMOTE_ADDR'] . $config['cv']);
				if (version_compare(PHP_VERSION, '7.3.0') >= 0)
				{
					setcookie("kt_remote_ips", implode(',', $allowed_ips), ['expires' => time() + $ttl, 'path' => '/', 'samesite' => 'Lax']);
				} else
				{
					setcookie("kt_remote_ips", implode(',', $allowed_ips), time() + $ttl, "/");
				}
			}
		}

		$target_file = $target_file_info['file'];
		$limit = $target_file_info['limit'];
	} else
	{
		if ($time < time() - $ttl || $time > time() + $ttl)
		{
			http_response_code(403);
			header("KVS-Errno: 3");
			echo "Access denied (errno 3)";
			die;
		}

		if (md5($time . $limit . $config['cv']) !== $cv)
		{
			http_response_code(403);
			header("KVS-Errno: 4");
			echo "Access denied (errno 4)";
			die;
		}

		if ($_SERVER['HTTP_REFERER'] != '' && $_REQUEST['cv3'] != '')
		{
			$ref_host = parse_url(str_replace('www.', '', $_SERVER['HTTP_REFERER']), PHP_URL_HOST);
			if ($ref_host != '' && $ref_host != $_SERVER['SERVER_NAME'] && md5($ref_host . $config['cv']) !== trim($_REQUEST['cv3']))
			{
				$referer_valid = false;
				$whitelist_referers = array_map('trim', explode(',', trim($whitelist_referers)));
				foreach ($whitelist_referers as $whitelist_referer)
				{
					if ($whitelist_referer == $ref_host)
					{
						$referer_valid = true;
						break;
					}
				}

				if (!$referer_valid)
				{
					http_response_code(403);
					header("KVS-Errno: 5");
					echo "Access denied (errno 5)";
					die;
				}
			}
		}

		if (md5($target_file . $config['cv']) !== trim($_REQUEST['cv4']))
		{
			http_response_code(403);
			header("KVS-Errno: 6");
			echo "Access denied (errno 6)";
			die;
		}
	}

	if (floatval($_REQUEST['start']) > 0)
	{
		$start_str = "?start=" . floatval($_REQUEST['start']);
	}

	if (strpos($target_file, ".flv") !== false)
	{
		header("Content-Type: video/x-flv");
	} elseif (strpos($target_file, ".mp4") !== false)
	{
		header("Content-Type: video/mp4");
	} elseif (strpos($target_file, ".webm") !== false)
	{
		header("Content-Type: video/webm");
	} elseif (strpos($target_file, ".jpg") !== false)
	{
		header("Content-Type: image/jpeg");
	} elseif (strpos($target_file, ".gif") !== false)
	{
		header("Content-Type: image/gif");
	} elseif (strpos($target_file, ".zip") !== false)
	{
		header("Content-Type: application/zip");
	} else
	{
		header("Content-Type: application/octet-stream");
	}

	$short_file_name = basename($target_file);
	if ($_REQUEST['download_filename'] <> '')
	{
		$short_file_name = $_REQUEST['download_filename'];
	}
	if ($is_download == 'true')
	{
		header("Content-Disposition: attachment; filename=\"$short_file_name\"");
	} else
	{
		header("Content-Disposition: inline; filename=\"$short_file_name\"");
	}

	// -------------------------------------------------------------------------
	// CDN pull-through cache.
	//
	// This server is a caching edge and does not necessarily hold every file.
	// Look for the file on local disk: if present, let the web server deliver it
	// (X-Accel-Redirect handles Range/pseudo-streaming/speed limit). If it is
	// missing and an origin is configured, pull it from the main server through
	// its get_file.php, stream it to the visitor and cache it to disk at the same
	// time, so the next request for it is served locally.
	// -------------------------------------------------------------------------
	if (trim($origin_url) != '')
	{
		$content_relative = remote_content_relative_path($target_file);
		$local_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'])), '/') . $content_relative;
		$accel_uri = "$target_file$start_str";

		// Logical, un-prefixed origin path (e.g. "29000/29350/29350_720p.mp4"): the
		// origin signs its get_file.php hash over this, so strip this edge's content
		// prefix (e.g. "videos/") from the local relative path. Used both to pull the
		// file from the origin and as the key under which its view time is recorded.
		$origin_video_file = ltrim($content_relative, '/');
		$content_prefix = trim(str_replace('\\', '/', $origin_content_prefix), '/');
		if ($content_prefix != '' && strpos($origin_video_file, $content_prefix . '/') === 0)
		{
			$origin_video_file = substr($origin_video_file, strlen($content_prefix) + 1);
		}

		$client_range = trim($_SERVER['HTTP_RANGE']);
		$is_partial = ($client_range != '' && !preg_match('#^bytes=0-$#', $client_range));

		// Record that this file was watched, on playback-start requests only (not on
		// every mid-file seek), so the cleanup cron can age out files nobody views.
		// Applies to both cache hits and misses; never fatal to serving the file.
		if (!$is_partial)
		{
			remote_record_view($origin_video_file);
		}

		if (is_file($local_path))
		{
			// CACHE HIT
			if (intval($limit) > 0)
			{
				header("X-Accel-Limit-Rate: $limit");
			}
			header("X-Accel-Redirect: $accel_uri");
			die;
		}

		// CACHE MISS: build a signed get_file.php link back to the origin.
		// admin_rq_server_id + dsc make the origin deliver the master copy with its
		// own hot-link/access checks disabled.
		$origin_file_url = remote_origin_pull_url($origin_url, $origin_sg_id, $origin_server_id, $origin_video_file);

		remote_cdn_log(($is_partial ? 'PROXY' : 'STREAM') . " miss local=$local_path range=" . ($client_range != '' ? $client_range : '-') . " origin=$origin_file_url");

		// In debug mode, verify up-front that this edge can actually persist what
		// it is about to fetch — the diagnostic log (next to this script) and, for
		// a full request, the cache file (under the content tree). If not, show the
		// reason(s) in the response body instead of silently streaming the video
		// uncached, so the operator can see and fix the permissions.
		if (!empty($cdn_debug))
		{
			$problems = array();

			$log_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'])), '/');
			if (!is_writable($log_dir))
			{
				$problems[] = "log NOT writable: cannot create/append $log_dir/remote_control_cdn.log";
			}

			$cache_dir = dirname($local_path);
			if (!$is_partial)
			{
				if (!is_dir($cache_dir))
				{
					@mkdir($cache_dir, 0777, true);
				}
				if (!is_dir($cache_dir))
				{
					$problems[] = "cache dir MISSING and could not be created: $cache_dir";
				} elseif (!is_writable($cache_dir))
				{
					$problems[] = "cache dir NOT writable: $cache_dir";
				}
			}

			if ($problems)
			{
				remote_cdn_debug_fail(implode(' | ', $problems), array(
					'request_type' => $is_partial ? 'partial/seek (range=' . $client_range . ')' : 'full',
					'script_dir'   => $log_dir,
					'cache_dir'    => $cache_dir,
					'local_path'   => $local_path,
					'origin_pull'  => $origin_file_url,
				));
			}
		}

		if ($is_partial)
		{
			// A seek / partial request on a cold file: proxy just this byte range
			// from the origin without caching (a partial body must never be stored
			// as if it were the whole file). The cache fills on a full request.
			remote_cdn_proxy($origin_file_url, $client_range, intval($limit));
		} else
		{
			// A full request: stream the file from the origin to the visitor while
			// writing it to the local cache.
			remote_cdn_stream_and_cache($origin_file_url, $local_path, intval($limit), $accel_uri);
		}
		die;
	}

	// Pull-through caching disabled (no origin configured): original behaviour.
	if (intval($limit) > 0)
	{
		header("X-Accel-Limit-Rate: $limit");
	}
	header("X-Accel-Redirect: $target_file{$start_str}");
}

// #############################################################################
// CDN pull-through cache helpers
// #############################################################################

// Best-effort name of the OS user this PHP process runs as (the php-fpm/nginx
// user), for permission diagnostics. Returns '?' when it cannot be determined.
function remote_cdn_process_user()
{
	if (function_exists('posix_geteuid') && function_exists('posix_getpwuid'))
	{
		$pw = @posix_getpwuid(posix_geteuid());
		return $pw ? $pw['name'] : (string) @posix_geteuid();
	}
	$u = getenv('USERNAME');
	if ($u === false || $u === '')
	{
		$u = getenv('USER');
	}
	return ($u === false || $u === '') ? '?' : $u;
}

// When $cdn_debug is on, abort the request with a visible plain-text error that
// explains why the edge could not cache or log, instead of silently streaming the
// video uncached and hiding the problem. Returns false when debug is off, so the
// caller runs its normal (resilient) uncached fallback. $context is an assoc array
// of extra "label => value" lines to include in the report.
function remote_cdn_debug_fail($reason, $context = array())
{
	global $cdn_debug;
	remote_cdn_log("FAIL $reason");
	if (empty($cdn_debug))
	{
		return false;
	}
	if (!headers_sent())
	{
		http_response_code(503);
		header('Content-Type: text/plain; charset=utf-8');
		header('KVS-CDN-Error: ' . $reason);
	}
	$context['php_process_user'] = remote_cdn_process_user();
	echo "CDN edge cache/log error (\$cdn_debug on):\n\n";
	echo " - $reason\n\n";
	foreach ($context as $label => $value)
	{
		echo str_pad($label, 18) . ": $value\n";
	}
	echo "\nFix the ownership/permissions above, then retry. Set \$cdn_debug = false\n";
	echo "to serve the video uncached instead of showing this message.\n";
	die;
}

// Append a diagnostic line to remote_control_cdn.log next to this script when
// $cdn_debug is on. Used to trace why a cache miss did or did not persist.
function remote_cdn_log($msg)
{
	global $cdn_debug;
	if (empty($cdn_debug))
	{
		return;
	}
	// $_SERVER['REMOTE_ADDR'] was already resolved to the end-user's IP at the top
	// of this script; also record the raw forwarding chain when present.
	$ip = 'ip=' . $_SERVER['REMOTE_ADDR'];
	if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && trim($_SERVER['HTTP_X_FORWARDED_FOR']) != '')
	{
		$ip .= ' xff=' . trim($_SERVER['HTTP_X_FORWARDED_FOR']);
	} elseif (isset($_SERVER['HTTP_X_REAL_IP']) && trim($_SERVER['HTTP_X_REAL_IP']) != '')
	{
		$ip .= ' xrip=' . trim($_SERVER['HTTP_X_REAL_IP']);
	}
	$log = dirname($_SERVER['SCRIPT_FILENAME']) . '/remote_control_cdn.log';
	$written = @file_put_contents($log, date('[Y-m-d H:i:s] ') . $ip . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
	if ($written === false)
	{
		// The log write itself failed — make that visible instead of silent.
		// A response header only works while the body has not started streaming;
		// the web-server error_log is the fallback channel once it has.
		$dir = dirname($log);
		if (!is_writable($dir) && !is_file($log))
		{
			$reason = "log-dir-not-writable dir=$dir";
		} elseif (is_file($log) && !is_writable($log))
		{
			$reason = "log-file-not-writable file=$log";
		} else
		{
			$reason = "log-write-failed file=$log";
		}
		if (!headers_sent())
		{
			header('KVS-CDN-Log: ' . $reason);
		}
		error_log("remote_control.php: $reason");
	}
}

// Map the requested URI ($target_file, e.g. "/contents/videos/29000/29227/f.mp4")
// to its content-relative path (e.g. "/29000/29227/f.mp4") by stripping the URL
// prefix under which this script is installed. In a standard KVS remote install
// that prefix (the storage server's "urls" path) maps onto the directory that
// holds this script, so the same relative path is used both for the local cache
// file (under dirname(SCRIPT_FILENAME)) and for the origin get_file.php "file".
function remote_content_relative_path($target_file)
{
	$uri_prefix = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
	$path = $target_file;
	if (($qpos = strpos($path, '?')) !== false)
	{
		$path = substr($path, 0, $qpos);
	}
	if ($uri_prefix != '' && $uri_prefix != '/' && strpos($path, $uri_prefix . '/') === 0)
	{
		$path = substr($path, strlen($uri_prefix));
	}
	$path = '/' . ltrim(str_replace('\\', '/', $path), '/');
	// collapse duplicate slashes and defuse any directory traversal
	$path = preg_replace('#/+#', '/', $path);
	$path = preg_replace('#/\.\.(?=/|$)#', '', $path);
	return $path;
}

// Build a signed get_file.php URL on the origin server that returns the raw file.
// hash and dsc are signed with the shared secret $config['cv'], and matched by
// get_file.php exactly (hash = md5(cv.file), dsc = md5(cv/hash/file/ttl)).
function remote_origin_pull_url($origin_url, $origin_sg_id, $origin_server_id, $file)
{
	global $config;

	$hash = md5($config['cv'] . $file);
	$ttl_ts = time();
	$dsc = md5($config['cv'] . '/' . $hash . '/' . $file . '/' . $ttl_ts);

	return rtrim($origin_url, '/') . '/get_file.php'
		. '?sg_id=' . rawurlencode($origin_sg_id)
		. '&hash=' . $hash
		. '&file=' . rawurlencode($file)
		. '&admin_rq_server_id=' . rawurlencode($origin_server_id)
		. '&ttl=' . $ttl_ts
		. '&dsc=' . $dsc;
}

// Stream $url to the given callbacks, following redirects. Prefers cURL and falls
// back to PHP stream wrappers (allow_url_fopen), so the edge does not require the
// cURL extension. $range is '' or a Range header value. $header_cb($header_line)
// is invoked per response header line (cURL style, trailing CRLF); $body_cb($chunk)
// per body chunk. Returns true if a transfer ran, false if no transport is
// available or the connection could not be made (peer TLS verification is skipped,
// as this is an internal server-to-server pull).
function remote_http_pull($url, $range, $header_cb, $body_cb)
{
	if (function_exists('curl_init'))
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 0);
		curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		if ($range != '')
		{
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Range: ' . $range));
		}
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use ($header_cb)
		{
			call_user_func($header_cb, $header);
			return strlen($header);
		});
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($body_cb)
		{
			call_user_func($body_cb, $data);
			return strlen($data);
		});
		$ok = curl_exec($ch);
		curl_close($ch);
		return ($ok !== false);
	}

	if (ini_get('allow_url_fopen'))
	{
		$req_header = "Connection: close\r\n";
		if ($range != '')
		{
			$req_header .= "Range: $range\r\n";
		}
		$context = stream_context_create(array(
			'http' => array(
				'method' => 'GET',
				'follow_location' => 1,
				'max_redirects' => 5,
				'timeout' => 15,
				'ignore_errors' => true,
				'header' => $req_header,
			),
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false,
			),
		));
		$fp = @fopen($url, 'rb', false, $context);
		if (!$fp)
		{
			return false;
		}
		if (isset($http_response_header) && is_array($http_response_header))
		{
			foreach ($http_response_header as $line)
			{
				call_user_func($header_cb, $line . "\r\n");
			}
		}
		while (!feof($fp))
		{
			$chunk = fread($fp, 65536);
			if ($chunk === false)
			{
				break;
			}
			if ($chunk !== '')
			{
				call_user_func($body_cb, $chunk);
			}
		}
		fclose($fp);
		return true;
	}

	return false;
}

// Full request on a cache miss: stream the file from the origin to the visitor
// and simultaneously write it to a temp file that is atomically renamed into the
// cache on success. Only one worker fills a given file at a time; concurrent
// requests proxy the file uncached to avoid corrupt/partial cache entries.
function remote_cdn_stream_and_cache($origin_url, $local_path, $limit, $accel_uri)
{
	$tmp_path = $local_path . '.part';
	$lock_path = $local_path . '.lock';

	$dir = dirname($local_path);
	if (!is_dir($dir))
	{
		@mkdir($dir, 0777, true);
	}
	if (!is_dir($dir) || !is_writable($dir))
	{
		// Cannot write to the content directory: stream to the visitor uncached
		// and record why, so the operator can fix permissions.
		remote_cdn_debug_fail("cache dir NOT writable dir=$dir (check ownership/permissions of content tree)", array('local_path' => $local_path));
		// Debug off: stream to the visitor uncached rather than failing the request.
		header('KVS-CDN: uncached-dir-not-writable');
		remote_cdn_proxy($origin_url, '', $limit);
		return;
	}

	$lock = @fopen($lock_path, 'c');
	$is_filler = ($lock && flock($lock, LOCK_EX | LOCK_NB));

	if (is_file($local_path))
	{
		// Filled by another worker in the meantime: let the web server serve it.
		if ($lock)
		{
			if ($is_filler)
			{
				@flock($lock, LOCK_UN);
			}
			@fclose($lock);
		}
		remote_cdn_log("appeared during race, serving from cache: $local_path");
		if (intval($limit) > 0)
		{
			header("X-Accel-Limit-Rate: $limit");
		}
		header("X-Accel-Redirect: $accel_uri");
		return;
	}

	if (!$is_filler)
	{
		if ($lock)
		{
			@fclose($lock);
		}
		// Another worker is already filling the cache: proxy without caching.
		remote_cdn_log("another worker is filling; serving uncached: $local_path");
		header('KVS-CDN: uncached-concurrent-fill');
		remote_cdn_proxy($origin_url, '', $limit);
		return;
	}

	// About to write a new file into the cache (a first-time view): if the volume is
	// low on free space, evict least-recently-viewed cached files first to make room.
	remote_cdn_enforce_free_space($local_path);

	$out = @fopen($tmp_path, 'wb');
	if (!$out)
	{
		@flock($lock, LOCK_UN);
		@fclose($lock);
		remote_cdn_debug_fail("could not open temp file for writing: $tmp_path", array('local_path' => $local_path));
		// Debug off: stream to the visitor uncached rather than failing the request.
		header('KVS-CDN: uncached-tmp-open-failed');
		remote_cdn_proxy($origin_url, '', $limit);
		return;
	}

	@set_time_limit(0);
	ignore_user_abort(true);
	while (ob_get_level() > 0)
	{
		@ob_end_flush();
	}
	header('Accept-Ranges: bytes');
	header('X-Accel-Buffering: no');
	header('KVS-CDN: stream-and-cache');

	$state = array('out' => $out, 'limit' => $limit, 'start' => microtime(true), 'sent' => 0, 'status' => 0);

	$header_cb = function ($header) use (&$state)
	{
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m))
		{
			$state['status'] = intval($m[1]);
			http_response_code($state['status']);
		} elseif (stripos($header, 'Content-Length:') === 0 && $state['status'] >= 200 && $state['status'] < 300)
		{
			@header('Content-Length: ' . trim(substr($header, 15)));
		}
	};
	$body_cb = function ($data) use (&$state)
	{
		$len = strlen($data);
		if ($state['status'] >= 200 && $state['status'] < 300)
		{
			@fwrite($state['out'], $data);
		}
		if (!connection_aborted())
		{
			echo $data;
			flush();
		}
		remote_cdn_throttle($state, $len);
	};

	$ok = remote_http_pull($origin_url, '', $header_cb, $body_cb);
	fclose($out);

	$tmp_size = @filesize($tmp_path);
	if ($ok && $state['status'] >= 200 && $state['status'] < 300 && $tmp_size > 0)
	{
		if (@rename($tmp_path, $local_path))
		{
			remote_cdn_log("CACHED ok status=$state[status] bytes=$tmp_size -> $local_path");
		} else
		{
			@unlink($tmp_path);
			remote_cdn_log("rename FAILED (perms?) $tmp_path -> $local_path");
		}
	} else
	{
		@unlink($tmp_path);
		remote_cdn_log("NOT cached ok=" . ($ok ? '1' : '0') . " status=$state[status] bytes=" . intval($tmp_size) . " (origin non-2xx, transport failure, or client+origin aborted)");
		if (!$ok && $state['status'] == 0)
		{
			// no usable HTTP transport, or the origin could not be reached
			http_response_code(502);
			header('KVS-CDN-Errno: origin-unreachable');
		}
	}

	@flock($lock, LOCK_UN);
	@fclose($lock);
}

// Proxy bytes straight from the origin to the visitor without caching. Used for
// range/seek requests on a cold file and when another worker owns the cache fill.
function remote_cdn_proxy($origin_url, $client_range, $limit)
{
	@set_time_limit(0);
	ignore_user_abort(true);
	while (ob_get_level() > 0)
	{
		@ob_end_flush();
	}
	header('Accept-Ranges: bytes');
	header('X-Accel-Buffering: no');

	$state = array('limit' => $limit, 'start' => microtime(true), 'sent' => 0, 'status' => 0);

	$header_cb = function ($header) use (&$state)
	{
		$low = strtolower($header);
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m))
		{
			$state['status'] = intval($m[1]);
			http_response_code($state['status']);
		} elseif (strpos($low, 'content-length:') === 0 || strpos($low, 'content-range:') === 0 || strpos($low, 'accept-ranges:') === 0)
		{
			@header(trim($header));
		}
	};
	$body_cb = function ($data) use (&$state)
	{
		if (!connection_aborted())
		{
			echo $data;
			flush();
		}
		remote_cdn_throttle($state, strlen($data));
	};

	$ok = remote_http_pull($origin_url, $client_range, $header_cb, $body_cb);
	if (!$ok && $state['status'] == 0)
	{
		http_response_code(502);
		header('KVS-CDN-Errno: origin-unreachable');
	}
}

// Enforce the per-format speed limit (bytes/sec) while streaming from PHP, since
// nginx's X-Accel-Limit-Rate only applies to locally-served (cached) files.
function remote_cdn_throttle(&$state, $bytes)
{
	$state['sent'] += $bytes;
	// Once the viewer has disconnected, stop throttling: the transfer is now just
	// a background cache-fill, so let it finish as fast as possible (and before any
	// php-fpm request_terminate_timeout kills the worker).
	if (connection_aborted())
	{
		return;
	}
	if (isset($state['limit']) && $state['limit'] > 0)
	{
		$elapsed = microtime(true) - $state['start'];
		$allowed = $elapsed * $state['limit'];
		if ($state['sent'] > $allowed)
		{
			$sleep = ($state['sent'] - $allowed) / $state['limit'];
			if ($sleep > 0)
			{
				usleep((int) ($sleep * 1000000));
			}
		}
	}
}

// #############################################################################
// View tracking (SQLite)
// #############################################################################

// Open (once per request) the SQLite view database as a PDO handle and ensure the
// schema exists. Returns the handle, or null if tracking is disabled, the
// pdo_sqlite extension is missing, or the database cannot be opened. Failures are
// logged (when $cdn_debug is on) and never interrupt serving the file.
function remote_view_db()
{
	global $view_db_path;
	static $db = false; // false = not tried yet, null = unavailable

	if ($db !== false)
	{
		return $db;
	}
	$db = null;

	if (empty($view_db_path) || !extension_loaded('pdo_sqlite'))
	{
		return $db;
	}
	try
	{
		$pdo = new PDO('sqlite:' . $view_db_path);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// WAL + a busy timeout let many concurrent viewers record without blocking.
		$pdo->exec('PRAGMA journal_mode=WAL');
		$pdo->exec('PRAGMA busy_timeout=3000');
		$pdo->exec('CREATE TABLE IF NOT EXISTS file_views (path TEXT PRIMARY KEY, last_viewed INTEGER NOT NULL)');
		// Index the LRU order so the real-time low-disk evictor picks victims cheaply.
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_file_views_last_viewed ON file_views(last_viewed)');
		// Top-list paths that edge_prewarm.php publishes here so the evictor spares them.
		$pdo->exec('CREATE TABLE IF NOT EXISTS protected_paths (path TEXT PRIMARY KEY)');
		$db = $pdo;
	} catch (Exception $e)
	{
		remote_cdn_log('view-db open failed: ' . $e->getMessage());
		$db = null;
	}
	return $db;
}

// Upsert the current time as the last-viewed time for a logical file path. Best
// effort: any failure is logged and swallowed so it can never break the response.
function remote_record_view($path)
{
	if ($path === '' || strpos($path, '..') !== false)
	{
		return;
	}
	$db = remote_view_db();
	if (!$db)
	{
		return;
	}
	try
	{
		$stmt = $db->prepare('INSERT OR REPLACE INTO file_views (path, last_viewed) VALUES (?, ?)');
		$stmt->execute(array($path, time()));
	} catch (Exception $e)
	{
		remote_cdn_log('view-db write failed: ' . $e->getMessage());
	}
}

// Real-time disk guard, called just before a newly-watched file is written to the
// cache. If the content volume has dropped below $cache_min_free_bytes free, evict
// the least-recently-viewed cached files (per the view db) until free space
// recovers. Deliberately cheap and bounded so it can run inside a request:
//   - triggered by an O(1) disk_free_space() check (no directory walk);
//   - victims are read straight from the LRU index, capped per call;
//   - a non-blocking lock means only one worker evicts at a time (others skip);
//   - top-list paths published by edge_prewarm.php are excluded, and the file being
//     written is never a candidate (it is not on disk yet).
// $local_path is the full cache path about to be written.
function remote_cdn_enforce_free_space($local_path)
{
	global $cache_min_free_bytes, $origin_content_prefix;

	if (empty($cache_min_free_bytes) || $cache_min_free_bytes <= 0)
	{
		return;
	}

	$volume_dir = dirname($local_path);
	$free = @disk_free_space($volume_dir);
	if ($free === false || $free >= $cache_min_free_bytes)
	{
		return; // plenty free (or cannot tell) - nothing to do
	}

	$db = remote_view_db();
	if (!$db)
	{
		return; // no view db - cannot choose LRU victims
	}

	$root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'])), '/');
	$prefix = trim(str_replace('\\', '/', $origin_content_prefix), '/');
	$base = ($prefix !== '') ? "$root/$prefix" : $root;

	// Only one worker evicts at a time; the rest carry on (a later request retries).
	$lock = @fopen("$root/.evict.lock", 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
	{
		if ($lock)
		{
			@fclose($lock);
		}
		return;
	}

	$local_norm = str_replace('\\', '/', $local_path);
	$evicted = array();
	try
	{
		// Oldest-viewed first, excluding protected (top-list) paths. Bounded.
		$rows = $db->query('SELECT path FROM file_views WHERE path NOT IN (SELECT path FROM protected_paths) ORDER BY last_viewed ASC LIMIT 500');
		foreach ($rows as $row)
		{
			$full = "$base/" . ltrim($row['path'], '/');
			if ($full === $local_norm || !is_file($full))
			{
				continue; // never the file we are about to write; skip already-gone rows
			}
			if (@unlink($full))
			{
				$evicted[] = $row['path'];
				remote_cdn_log("EVICT low-disk $row[path]");
				if (@disk_free_space($volume_dir) >= $cache_min_free_bytes)
				{
					break; // recovered enough headroom
				}
			}
		}

		if (count($evicted) > 0)
		{
			$stmt = $db->prepare('DELETE FROM file_views WHERE path = ?');
			foreach ($evicted as $p)
			{
				$stmt->execute(array($p));
			}
		}
	} catch (Exception $e)
	{
		remote_cdn_log('low-disk eviction failed: ' . $e->getMessage());
	}

	@flock($lock, LOCK_UN);
	@fclose($lock);
}

// #############################################################################
// Monitoring endpoint helpers (action=monitor)
// #############################################################################

// Walk the content/cache tree and count cached videos (distinct per-video
// directories holding at least one video-format file), video files, total files
// and total bytes. The walk can take seconds on a full cache, so the result is
// cached in edge_monitor_stats.dat next to this script for 5 minutes, and a
// non-blocking lock ensures only one worker recounts at a time (the rest serve
// the stale value). Returns the stats array, or null before the first count.
function remote_monitor_cache_stats()
{
	global $origin_content_prefix;

	$root = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'])), '/');
	$prefix = trim(str_replace('\\', '/', $origin_content_prefix), '/');
	$base = ($prefix !== '') ? "$root/$prefix" : $root;

	$cache_file = "$root/edge_monitor_stats.dat";
	$ttl = 300;

	$cached = @json_decode((string) @file_get_contents($cache_file), true);
	if (is_array($cached) && isset($cached['counted_at']) && time() - $cached['counted_at'] < $ttl)
	{
		return $cached;
	}

	$lock = @fopen("$cache_file.lock", 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
	{
		if ($lock)
		{
			@fclose($lock);
		}
		return is_array($cached) ? $cached : null;
	}

	@set_time_limit(0);
	$video_ext = array('mp4' => 1, 'm4v' => 1, 'webm' => 1, 'flv' => 1, 'mov' => 1, 'avi' => 1, 'wmv' => 1, 'mkv' => 1, 'mpg' => 1, 'mpeg' => 1, 'ts' => 1);
	$stats = array('videos' => 0, 'video_files' => 0, 'files' => 0, 'bytes' => 0, 'counted_at' => time());
	if (is_dir($base))
	{
		$video_dirs = array();
		try
		{
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ($it as $file)
			{
				if (!$file->isFile())
				{
					continue;
				}
				$ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
				if ($ext == 'part' || $ext == 'lock')
				{
					continue; // in-progress cache fills and lock files
				}
				$stats['files']++;
				$size = @filesize($file->getPathname());
				if ($size !== false)
				{
					$stats['bytes'] += $size;
				}
				if (isset($video_ext[$ext]))
				{
					$stats['video_files']++;
					$video_dirs[dirname($file->getPathname())] = 1;
				}
			}
			$stats['videos'] = count($video_dirs);
		} catch (Exception $e)
		{
			remote_cdn_log('monitor cache count failed: ' . $e->getMessage());
		}
	}

	@file_put_contents($cache_file, json_encode($stats), LOCK_EX);
	@flock($lock, LOCK_UN);
	@fclose($lock);
	return $stats;
}

// Number of distinct cached files viewed within the last $seconds, per the
// SQLite view db. Returns null when view tracking is unavailable.
function remote_monitor_recent_views($seconds)
{
	$db = remote_view_db();
	if (!$db)
	{
		return null;
	}
	try
	{
		$stmt = $db->prepare('SELECT COUNT(*) FROM file_views WHERE last_viewed >= ?');
		$stmt->execute(array(time() - $seconds));
		return intval($stmt->fetchColumn());
	} catch (Exception $e)
	{
		remote_cdn_log('view-db count failed: ' . $e->getMessage());
		return null;
	}
}