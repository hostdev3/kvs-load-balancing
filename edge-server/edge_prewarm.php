<?php
/* Developed by Kernel Team.
   http://kernel-team.com

   Edge pre-warmer for the top most-viewed videos.

   Fetches the top-N most-viewed video file list from the origin (main) server
   via get_top_videos.php, then pulls any file that is not already cached
   locally through the origin's get_file.php - the exact same signed pull the
   edge's remote_control.php uses for on-demand caching - so those files are
   already warm on local disk before visitors request them.

   Run it from system cron on the edge (NOT through the web server, so it is not
   bound by php-fpm/HTTP timeouts), e.g. every few hours:

     0 *\/4 * * *  php /path/to/edge_storage_server/edge_prewarm.php >/dev/null 2>&1

   It can also be triggered over HTTP for a quick manual run:
     /edge_storage_server/edge_prewarm.php?cv=<config cv>
*/

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);

// ---------------------------- configuration -------------------------------
// These MUST match the values in this edge's remote_control.php / get_file.php.

// Shared secret (same as the main server and this edge's remote_control.php).
$config['cv'] = "ed2317c592f17c6dcb43dd56cd3e4c1c";

// Origin (main) server - where the list and the files are pulled from.
$origin_url        = "https://baddies.xxx";  // base URL of the main server
$origin_server_id  = "8";                     // admin_rq_server_id holding the master copy
$origin_sg_id      = 7;                        // any non-zero storage-group id
$origin_content_prefix = "videos";            // this edge's content path prefix

// Top-videos list endpoint on the origin (defaults to origin_url/get_top_videos.php).
$top_videos_url = rtrim($origin_url, '/') . "/get_top_videos.php";

// How many videos to keep warm, and the storage-group filter. $edge_group_id MUST
// be this edge's server group in KVS (Servers admin -> this server's "group"), so
// it only warms and keeps videos whose server_group_id matches. Set 0 only if this
// edge is meant to hold videos from every group.
$top_limit     = 200;
$edge_group_id = 7;

// Where this edge stores content. Leave empty to use this script's own directory
// (the standard layout where the control script lives in the content root).
$content_root = "";

// Safety cap on how many bytes a single run may download (0 = unlimited).
$max_run_bytes = 0;

// --- stale-file cleanup ---------------------------------------------------
// After warming, delete cached files not viewed for $stale_days days UNLESS they
// are in the current top list. View timestamps are recorded by remote_control.php
// into the shared SQLite db below, whose path MUST match remote_control.php's
// $view_db_path. If that db is unavailable, cleanup is skipped rather than deleting
// blindly by file age (which would drop still-watched files). Set false to only
// warm and never delete.
$enable_cleanup = true;
$stale_days     = 14;
$view_db_path   = __DIR__ . '/edge_cache.sqlite';

// --- cache size cap (LRU eviction) ----------------------------------------
// Independently of $stale_days, keep the cache from filling the disk. When the
// total cached size exceeds $max_cache_bytes, and/or free disk space drops below
// $min_free_bytes, the least-recently-viewed files are evicted (top-list files
// stay protected) until back under the limit - even if they are only hours old.
// This catches bursts where a single day of fresh caches would overflow storage.
// Set a knob to 0 to disable it. NOTE: use 64-bit PHP for caches above ~2 GB.
$max_cache_bytes = 0;                  // e.g. 500 * 1024 * 1024 * 1024 for 500 GB (0 = off)
$min_free_bytes  = 0;                  // e.g.  50 * 1024 * 1024 * 1024 to keep 50 GB free (0 = off)
$cache_low_watermark = 0.90;           // when the cap trips, evict down to this fraction of it
                                       // (hysteresis, so it does not re-trigger every run)

// Log file (next to this script). Set empty to disable logging.
$log_file = __DIR__ . '/edge_prewarm.log';

######################################################################################

$is_cli = (php_sapi_name() === 'cli');

// Over HTTP this must be authenticated; on CLI it is implicitly trusted (shell access).
if (!$is_cli)
{
	if (trim($_REQUEST['cv']) !== $config['cv'])
	{
		sleep(1);
		http_response_code(403);
		echo "Access denied";
		die;
	}
	header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(0);
ignore_user_abort(true);

if ($content_root === '')
{
	$content_root = __DIR__;
}
$content_root = rtrim(str_replace('\\', '/', $content_root), '/');
$content_prefix = trim(str_replace('\\', '/', $origin_content_prefix), '/');

// Only one pre-warm run at a time.
$lock_handle = @fopen(__DIR__ . '/edge_prewarm.lock', 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB))
{
	prewarm_log("another pre-warm run is already in progress; exiting");
	prewarm_out("another pre-warm run is already in progress; exiting");
	die;
}

$run_start = time();
prewarm_log("=== pre-warm run start (limit=$top_limit group=$edge_group_id) ===");

// -------------------------- fetch the top list ----------------------------
$ttl = time();
$list_url = $top_videos_url
	. '?limit=' . rawurlencode($top_limit)
	. '&sg_id=' . rawurlencode($edge_group_id)
	. '&ttl=' . $ttl
	. '&sign=' . md5($config['cv'] . '/top_videos/' . $ttl);

$list_body = '';
$list_status = 0;
prewarm_http_pull($list_url, '',
	function ($header) use (&$list_status)
	{
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m))
		{
			$list_status = intval($m[1]);
		}
	},
	function ($chunk) use (&$list_body)
	{
		$list_body .= $chunk;
	}
);

if ($list_status < 200 || $list_status >= 300 || trim($list_body) === '')
{
	prewarm_log("FAILED to fetch top list: http=$list_status url=$list_url");
	prewarm_out("failed to fetch top list (http $list_status)");
	prewarm_finish($lock_handle);
	die;
}

// Parse "<path>|<size>" lines into a de-duplicated wanted-set.
$wanted = array();       // logical_path => size
foreach (explode("\n", $list_body) as $line)
{
	$line = trim($line);
	if ($line === '')
	{
		continue;
	}
	$rec = explode('|', $line, 2);
	$path = ltrim(trim($rec[0]), '/');
	if ($path === '' || strpos($path, '..') !== false)
	{
		continue;
	}
	$wanted[$path] = isset($rec[1]) ? sprintf('%.0f', floatval($rec[1])) : '0';
}

prewarm_log("received " . count($wanted) . " file(s) in top list");

// Publish the protected (top-list) set to the view db so remote_control.php's
// real-time low-disk evictor never drops a top-ranked video between cron runs.
if (count($wanted) > 0)
{
	$pdb = prewarm_view_db($view_db_path);
	if ($pdb)
	{
		prewarm_store_protected($pdb, array_keys($wanted));
	}
}

// ------------------------------ pull loop ---------------------------------
$downloaded_bytes = 0;
$stats = array('cached' => 0, 'skipped' => 0, 'failed' => 0);

foreach ($wanted as $path => $expected_size)
{
	$local_path = "$content_root/$content_prefix/$path";

	// Already present with the expected size (or size unknown but non-empty)?
	if (is_file($local_path))
	{
		$have = sprintf('%.0f', floatval(@filesize($local_path)));
		if (($expected_size > 0 && $have === $expected_size) || ($expected_size == 0 && $have > 0))
		{
			$stats['skipped']++;
			continue;
		}
	}

	if ($max_run_bytes > 0 && $downloaded_bytes >= $max_run_bytes)
	{
		prewarm_log("run byte cap reached ($downloaded_bytes bytes); stopping early");
		break;
	}

	$origin_pull_url = prewarm_origin_pull_url($origin_url, $origin_sg_id, $origin_server_id, $path);
	$got = prewarm_fetch_to_cache($origin_pull_url, $local_path, $expected_size);

	if ($got === false)
	{
		$stats['failed']++;
		prewarm_log("FAIL   $path");
	} else
	{
		$stats['cached']++;
		$downloaded_bytes += $got;
		prewarm_log("CACHED $path ($got bytes)");
	}
}

// --------------------------- stale-file cleanup ---------------------------
// Evict cached files not viewed for $stale_days, always keeping the current top
// list. Files viewed recently (recorded by remote_control.php) or cached recently
// are kept; a file never viewed since it was cached ages from its own mtime.
$evicted = 0;
$evicted_bytes = 0;

if ($enable_cleanup && count($wanted) == 0)
{
	// A garbled/short list must never wipe the cache - there would be nothing to
	// protect. We already die earlier on an empty HTTP body; this is belt-and-braces.
	prewarm_log("cleanup SKIPPED: top list is empty (refusing to evict without a protect list)");
} elseif ($enable_cleanup)
{
	$db = prewarm_view_db($view_db_path);
	if (!$db)
	{
		prewarm_log("cleanup SKIPPED: view db unavailable (would otherwise delete by file age and drop still-watched files)");
	} else
	{
		$views = prewarm_load_views($db);           // logical_path => last_viewed
		$cutoff = time() - $stale_days * 86400;
		$base = "$content_root/$content_prefix";

		// One walk: collect every cached file with its size and last-activity time.
		// effective = most recent of (last view, cache write time); a file never
		// viewed since it was cached ages from its own mtime. top = in the top list.
		$files = array();
		$total_size = 0.0;
		$kept = array();                            // logical paths still on disk

		if (is_dir($base))
		{
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS)
			);
			foreach ($it as $fileinfo)
			{
				if (!$fileinfo->isFile())
				{
					continue;
				}
				$name = $fileinfo->getFilename();
				if (substr($name, -5) === '.part' || substr($name, -5) === '.lock')
				{
					continue; // in-progress fill / lock file - never touch
				}

				$full = str_replace('\\', '/', $fileinfo->getPathname());
				$logical = ltrim(substr($full, strlen($base)), '/');
				$size = (float) $fileinfo->getSize();
				$last_viewed = isset($views[$logical]) ? intval($views[$logical]) : 0;

				$files[] = array(
					'logical'   => $logical,
					'full'      => $full,
					'size'      => $size,
					'effective' => max($last_viewed, intval($fileinfo->getMTime())),
					'top'       => isset($wanted[$logical]),
				);
				$total_size += $size;
				$kept[$logical] = true;
			}
		}

		// --- policy 1: age - drop non-top files not viewed for $stale_days ---
		foreach ($files as $i => $f)
		{
			if ($f['top'] || $f['effective'] >= $cutoff)
			{
				continue;
			}
			if (@unlink($f['full']))
			{
				$files[$i]['removed'] = true;
				unset($kept[$f['logical']]);
				$total_size -= $f['size'];
				$evicted++;
				$evicted_bytes += $f['size'];
				prewarm_log("EVICT  $f[logical] (idle " . floor((time() - $f['effective']) / 86400) . "d)");
			}
		}

		// --- policy 2: size cap - evict least-recently-viewed non-top files ---
		// Work out how many bytes must go to get the cache under $max_cache_bytes
		// (down to the low-watermark) and to restore $min_free_bytes of free disk,
		// then drop the oldest-activity files first until that much is freed. Runs
		// regardless of age, so a burst of fresh caches cannot overflow the disk.
		$need_free = 0.0;
		if ($max_cache_bytes > 0 && $total_size > $max_cache_bytes)
		{
			$need_free = max($need_free, $total_size - $max_cache_bytes * $cache_low_watermark);
		}
		if ($min_free_bytes > 0)
		{
			$free_now = (float) @disk_free_space($base);
			if ($free_now > 0 && $free_now < $min_free_bytes)
			{
				$need_free = max($need_free, $min_free_bytes - $free_now);
			}
		}

		if ($need_free > 0)
		{
			$candidates = array();
			foreach ($files as $f)
			{
				if (empty($f['removed']) && !$f['top'])
				{
					$candidates[] = $f;
				}
			}
			// Least-recently-viewed first (oldest effective activity).
			usort($candidates, function ($a, $b)
			{
				if ($a['effective'] == $b['effective'])
				{
					return 0;
				}
				return ($a['effective'] < $b['effective']) ? -1 : 1;
			});

			$freed = 0.0;
			foreach ($candidates as $f)
			{
				if ($freed >= $need_free)
				{
					break;
				}
				if (@unlink($f['full']))
				{
					$freed += $f['size'];
					$total_size -= $f['size'];
					unset($kept[$f['logical']]);
					$evicted++;
					$evicted_bytes += $f['size'];
					prewarm_log("EVICT  $f[logical] (size cap, LRU, idle " . floor((time() - $f['effective']) / 86400) . "d)");
				}
			}

			if ($freed < $need_free)
			{
				prewarm_log("WARNING over storage limit after evicting all non-top files: still need "
					. round(($need_free - $freed) / 1048576) . " MB - the top list alone may exceed the cap; "
					. "raise disk, lower \$top_limit, or warm fewer formats");
			}
		}

		// Reconcile the db with disk: drop rows for files that no longer exist.
		prewarm_reconcile_views($db, $views, $kept);

		// Remove directories left empty by eviction.
		prewarm_prune_empty_dirs($base);

		prewarm_log("cleanup: evicted=$evicted bytes=$evicted_bytes kept=" . count($kept)
			. " cache_size=" . round($total_size / 1048576) . "MB stale_days=$stale_days");
	}
}

$summary = "done: cached=$stats[cached] skipped=$stats[skipped] failed=$stats[failed] "
	. "evicted=$evicted downloaded_bytes=$downloaded_bytes elapsed=" . (time() - $run_start) . "s";
prewarm_log($summary);
prewarm_log("=== pre-warm run end ===");
prewarm_out($summary);

prewarm_finish($lock_handle);
die;

// #############################################################################
// Helpers
// #############################################################################

// Build the signed get_file.php pull URL on the origin - identical scheme to
// remote_control.php's remote_origin_pull_url(): hash=md5(cv.file),
// dsc=md5(cv/hash/file/ttl), with admin_rq_server_id forcing the master copy.
function prewarm_origin_pull_url($origin_url, $origin_sg_id, $origin_server_id, $file)
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

// Download $url into $local_path atomically (via a .part temp file). Returns the
// number of bytes written on success, or false on any failure (the partial file
// is discarded). Verifies the expected size when one is known.
function prewarm_fetch_to_cache($url, $local_path, $expected_size)
{
	$dir = dirname($local_path);
	if (!is_dir($dir))
	{
		@mkdir($dir, 0777, true);
	}
	if (!is_dir($dir) || !is_writable($dir))
	{
		prewarm_log("cache dir not writable: $dir");
		return false;
	}

	$tmp_path = $local_path . '.part';
	$out = @fopen($tmp_path, 'wb');
	if (!$out)
	{
		prewarm_log("cannot open temp file: $tmp_path");
		return false;
	}

	$state = array('out' => $out, 'status' => 0, 'bytes' => 0);
	$header_cb = function ($header) use (&$state)
	{
		if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m))
		{
			$state['status'] = intval($m[1]);
		}
	};
	$body_cb = function ($data) use (&$state)
	{
		if ($state['status'] >= 200 && $state['status'] < 300)
		{
			$state['bytes'] += fwrite($state['out'], $data);
		}
	};

	$ok = prewarm_http_pull($url, '', $header_cb, $body_cb);
	fclose($out);

	$size = sprintf('%.0f', floatval(@filesize($tmp_path)));
	$size_ok = ($expected_size > 0) ? ($size === $expected_size) : ($size > 0);

	if ($ok && $state['status'] >= 200 && $state['status'] < 300 && $size_ok)
	{
		if (@rename($tmp_path, $local_path))
		{
			return intval($state['bytes']);
		}
		@unlink($tmp_path);
		prewarm_log("rename failed (perms?): $tmp_path -> $local_path");
		return false;
	}

	@unlink($tmp_path);
	if (!$size_ok && $ok && $state['status'] >= 200 && $state['status'] < 300)
	{
		prewarm_log("size mismatch: got $size expected $expected_size for $local_path");
	}
	return false;
}

// Stream $url to the given callbacks, following redirects. Prefers cURL, falls
// back to PHP stream wrappers (allow_url_fopen). Same approach as the on-demand
// puller in remote_control.php; TLS verification is skipped (internal pull).
function prewarm_http_pull($url, $range, $header_cb, $body_cb)
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

function prewarm_log($msg)
{
	global $log_file;
	if ($log_file === '')
	{
		return;
	}
	@file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// Echo progress only when triggered over HTTP (CLI stays quiet for cron).
function prewarm_out($msg)
{
	if (php_sapi_name() !== 'cli')
	{
		echo "$msg\n";
	}
}

function prewarm_finish($lock_handle)
{
	if ($lock_handle)
	{
		@flock($lock_handle, LOCK_UN);
		@fclose($lock_handle);
	}
}

// Open (once) the shared SQLite view db as a PDO handle, ensuring the schema.
// Returns null if pdo_sqlite is unavailable or the db cannot be opened - the
// caller then skips cleanup rather than deleting blindly.
function prewarm_view_db($path)
{
	static $db = false; // false = not tried yet, null = unavailable

	if ($db !== false)
	{
		return $db;
	}
	$db = null;

	if ($path === '' || !extension_loaded('pdo_sqlite'))
	{
		return $db;
	}
	try
	{
		$pdo = new PDO('sqlite:' . $path);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('PRAGMA busy_timeout=5000');
		$pdo->exec('CREATE TABLE IF NOT EXISTS file_views (path TEXT PRIMARY KEY, last_viewed INTEGER NOT NULL)');
		$pdo->exec('CREATE INDEX IF NOT EXISTS idx_file_views_last_viewed ON file_views(last_viewed)');
		// Protected (top-list) paths, read by remote_control.php's real-time evictor.
		$pdo->exec('CREATE TABLE IF NOT EXISTS protected_paths (path TEXT PRIMARY KEY)');
		$db = $pdo;
	} catch (Exception $e)
	{
		prewarm_log('view db open failed: ' . $e->getMessage());
		$db = null;
	}
	return $db;
}

// Replace the protected-paths set with the current top list. remote_control.php's
// real-time evictor reads this table to spare top-ranked videos.
function prewarm_store_protected($db, $paths)
{
	try
	{
		$db->beginTransaction();
		$db->exec('DELETE FROM protected_paths');
		$stmt = $db->prepare('INSERT OR IGNORE INTO protected_paths (path) VALUES (?)');
		foreach ($paths as $p)
		{
			$stmt->execute(array($p));
		}
		$db->commit();
	} catch (Exception $e)
	{
		if ($db->inTransaction())
		{
			$db->rollBack();
		}
		prewarm_log('protected set store failed: ' . $e->getMessage());
	}
}

// Load all recorded view timestamps as an assoc map logical_path => last_viewed.
function prewarm_load_views($db)
{
	$views = array();
	try
	{
		$rows = $db->query('SELECT path, last_viewed FROM file_views');
		foreach ($rows as $row)
		{
			$views[$row['path']] = intval($row['last_viewed']);
		}
	} catch (Exception $e)
	{
		prewarm_log('view db read failed: ' . $e->getMessage());
	}
	return $views;
}

// Drop db rows whose file is no longer on disk: everything present in $views but
// not in the $kept set (kept = files still on disk after this cleanup pass).
function prewarm_reconcile_views($db, $views, $kept)
{
	$orphans = array_diff_key($views, $kept);
	if (count($orphans) == 0)
	{
		return;
	}
	try
	{
		$db->beginTransaction();
		$stmt = $db->prepare('DELETE FROM file_views WHERE path = ?');
		foreach ($orphans as $path => $unused)
		{
			$stmt->execute(array($path));
		}
		$db->commit();
	} catch (Exception $e)
	{
		if ($db->inTransaction())
		{
			$db->rollBack();
		}
		prewarm_log('view db reconcile failed: ' . $e->getMessage());
	}
}

// Recursively remove empty directories under $base (keeping $base itself), so the
// cache tree does not accumulate empty <dir>/<id> folders after eviction.
function prewarm_prune_empty_dirs($base)
{
	if (!is_dir($base))
	{
		return;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($it as $fileinfo)
	{
		if (!$fileinfo->isDir())
		{
			continue;
		}
		$dir = $fileinfo->getPathname();
		$entries = @scandir($dir);
		if ($entries !== false && count($entries) == 2) // only "." and ".."
		{
			@rmdir($dir);
		}
	}
}
