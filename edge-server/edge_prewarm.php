<?php
/* Developed by Kernel Team.
   http://kernel-team.com

   Edge cache cleanup cron.

   The edge cache fills itself on demand: remote_control.php pulls and caches
   each file from the origin the first time a visitor requests it. This script
   is the janitor for that cache - it evicts files nobody watches anymore
   (stale-file cleanup) and keeps the total cache size / free disk space within
   configured bounds (LRU eviction), using the view timestamps that
   remote_control.php records into the shared SQLite db.

   Run it from system cron on the edge (NOT through the web server, so it is not
   bound by php-fpm/HTTP timeouts), e.g. every few hours:

     0 *\/4 * * *  php /path/to/edge_storage_server/edge_prewarm.php >/dev/null 2>&1

   CLI only - it refuses to run through the web server.
*/

error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR);

// ---------------------------- configuration -------------------------------
// These MUST match the values in this edge's remote_control.php.

// This edge's content path prefix (same as remote_control.php).
$origin_content_prefix = "videos";

// Where this edge stores content. Leave empty to use this script's own directory
// (the standard layout where the control script lives in the content root).
$content_root = "";

// --- stale-file cleanup ---------------------------------------------------
// Delete cached files not viewed for $stale_days days. View timestamps are
// recorded by remote_control.php into the shared SQLite db below, whose path
// MUST match remote_control.php's $view_db_path. If that db is unavailable,
// cleanup is skipped rather than deleting blindly by file age (which would drop
// still-watched files).
$stale_days     = 14;
$view_db_path   = __DIR__ . '/edge_cache.sqlite';

// --- cache size cap (LRU eviction) ----------------------------------------
// Independently of $stale_days, keep the cache from filling the disk. When the
// total cached size exceeds $max_cache_bytes, and/or free disk space drops below
// $min_free_bytes, the least-recently-viewed files are evicted until back under
// the limit - even if they are only hours old. This catches bursts where a
// single day of fresh caches would overflow storage.
// Set a knob to 0 to disable it. NOTE: use 64-bit PHP for caches above ~2 GB.
$max_cache_bytes = 0;                  // e.g. 500 * 1024 * 1024 * 1024 for 500 GB (0 = off)
$min_free_bytes  = 0;                  // e.g.  50 * 1024 * 1024 * 1024 to keep 50 GB free (0 = off)
$cache_low_watermark = 0.90;           // when the cap trips, evict down to this fraction of it
                                       // (hysteresis, so it does not re-trigger every run)

// Log file (next to this script). Set empty to disable logging.
$log_file = __DIR__ . '/edge_prewarm.log';

######################################################################################

// Cron/CLI only - never serve this script over the web.
if (php_sapi_name() !== 'cli')
{
	http_response_code(404);
	die;
}

@set_time_limit(0);
ignore_user_abort(true);

if ($content_root === '')
{
	$content_root = __DIR__;
}
$content_root = rtrim(str_replace('\\', '/', $content_root), '/');
$content_prefix = trim(str_replace('\\', '/', $origin_content_prefix), '/');

// Only one cleanup run at a time.
$lock_handle = @fopen(__DIR__ . '/edge_prewarm.lock', 'c');
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB))
{
	prewarm_log("another cleanup run is already in progress; exiting");
	die;
}

$run_start = time();
prewarm_log("=== cleanup run start (stale_days=$stale_days) ===");

// ------------------------------- cleanup ----------------------------------
// Evict cached files not viewed for $stale_days. Files viewed recently (recorded
// by remote_control.php) or cached recently are kept; a file never viewed since
// it was cached ages from its own mtime.
$evicted = 0;
$evicted_bytes = 0;
$kept = array();

$db = prewarm_view_db($view_db_path);
if (!$db)
{
	prewarm_log("cleanup SKIPPED: view db unavailable (would otherwise delete by file age and drop still-watched files)");
	prewarm_finish($lock_handle);
	die;
}

$views = prewarm_load_views($db);           // logical_path => last_viewed
$cutoff = time() - $stale_days * 86400;
$base = "$content_root/$content_prefix";

// One walk: collect every cached file with its size and last-activity time.
// effective = most recent of (last view, cache write time); a file never
// viewed since it was cached ages from its own mtime.
$files = array();
$total_size = 0.0;

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
		);
		$total_size += $size;
		$kept[$logical] = true;
	}
}

// --- policy 1: age - drop files not viewed for $stale_days ---
foreach ($files as $i => $f)
{
	if ($f['effective'] >= $cutoff)
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

// --- policy 2: size cap - evict least-recently-viewed files ---
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
		if (empty($f['removed']))
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
		prewarm_log("WARNING over storage limit after evicting all cached files: still need "
			. round(($need_free - $freed) / 1048576) . " MB - raise disk or lower the caps");
	}
}

// Reconcile the db with disk: drop rows for files that no longer exist.
prewarm_reconcile_views($db, $views, $kept);

// Remove directories left empty by eviction.
prewarm_prune_empty_dirs($base);

$summary = "done: evicted=$evicted evicted_bytes=$evicted_bytes kept=" . count($kept)
	. " cache_size=" . round($total_size / 1048576) . "MB stale_days=$stale_days"
	. " elapsed=" . (time() - $run_start) . "s";
prewarm_log($summary);
prewarm_log("=== cleanup run end ===");

prewarm_finish($lock_handle);
die;

// #############################################################################
// Helpers
// #############################################################################

function prewarm_log($msg)
{
	global $log_file;
	if ($log_file === '')
	{
		return;
	}
	@file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
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
		$db = $pdo;
	} catch (Exception $e)
	{
		prewarm_log('view db open failed: ' . $e->getMessage());
		$db = null;
	}
	return $db;
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
