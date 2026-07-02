<?php
/* Developed by Kernel Team.
   http://kernel-team.com
*/
require_once 'admin/include/setup.php';
require_once 'admin/include/functions_base.php';

if ($_GET['action']=='check_ip')
{
	header("KVS-IP: $_SERVER[REMOTE_ADDR]");
	echo $_SERVER['REMOTE_ADDR'];
	die;
}

$sg_id=intval($_GET['sg_id']);
$hash=$_GET['hash'];
$hash2='';
if (strlen($hash)>32)
{
	$hash2=substr($hash,32);
	$hash=substr($hash,0,32);
}
$file=trim(rtrim($_GET['file'],"/"));
$admin_rq_server_id=intval($_GET['admin_rq_server_id']);
$is_download=trim($_GET['download']);
$download_filename=trim($_GET['download_filename']);

$stats_settings = @unserialize(file_get_contents("$config[project_path]/admin/data/system/stats_params.dat"));

// check link hash validity
$hash_check = md5($config['cv'] . $file);
if ($hash_check != $hash)
{
	if (substr(md5(strrev($config['cv']) . $file), 0, 31) == substr($hash, 0, 31))
	{
		if (intval($stats_settings['collect_player_stats']) == 1)
		{
			if (intval($stats_settings['player_stats_reporting']) == 0 || intval($stats_settings['player_stats_reporting']) == 2)
			{
				$is_embed = intval(substr($hash, 31));
				$device_type = 0;
				if (intval($stats_settings['collect_player_stats_devices']) == 1)
				{
					$device_type = get_device_type();
				}
				file_put_contents("$config[project_path]/admin/data/stats/player.dat", date('Y-m-d') . "|$is_embed|Total||$_SERVER[GEOIP_COUNTRY_CODE]|$_COOKIE[kt_referer]|$_COOKIE[kt_qparams]|$device_type|\n", FILE_APPEND | LOCK_EX);
			}
		}
		header("Content-type: image/gif");
		die(base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw=='));
	}
	debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI] Invalid link hash $hash for file $file");
	http_response_code(404);
	die;
}

if ($sg_id==0)
{
	// request for source file on main server
	$target_file="$config[content_url_videos_sources]/$file";
	if ($config['server_type']=='nginx')
	{
		$target_file=substr($target_file,strpos($target_file,'/',8));
		$short_file_name=end(explode("/",$file));
		if ($download_filename<>'')
		{
			$short_file_name=$download_filename;
		}
		$file_ext=strtolower(end(explode(".",$file)));
		if ($file_ext=='jpg')
		{
			header("Content-type: image/jpeg");
			header("Content-Disposition: inline; filename=\"$short_file_name\"");
		} else {
			header("Content-type: application/octet-stream");
			header("Content-Disposition: attachment; filename=\"$short_file_name\"");
		}
		header("X-Accel-Redirect: $target_file");
	} else {
		header("Location: $target_file");
	}
	die;
}

// check format validity
$formats_videos=@unserialize(@file_get_contents("admin/data/system/formats_videos.dat"));
preg_match("|\d+/(\d+)/\d+(.+)|is",$file,$temp);
$video_id=$temp[1];
$postfix=$temp[2];

foreach($formats_videos as $format)
{
	if ($postfix==$format['postfix'])
	{
		$current_format=$format;
		break;
	}
}
if (!isset($current_format))
{
	debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  No video format is available for file $file");
	http_response_code(404);
	echo "File format is not found";
	die;
}

$hotlink_info=@unserialize(@file_get_contents("admin/data/system/hotlink_info.dat"));

$disable_security_check=false;
if (isset($_REQUEST['dsc']))
{
	$disable_security_check_ttl=intval($_REQUEST['ttl']);
	if (md5("$config[cv]/$hash/$file/$disable_security_check_ttl")==$_REQUEST['dsc'])
	{
		if (abs($disable_security_check_ttl-time())<86400)
		{
			$disable_security_check=true;
		}
	}
}

if (stripos($_SERVER['HTTP_USER_AGENT'], 'google') !== false)
{
	$domain = gethostbyaddr($_SERVER['REMOTE_ADDR']);
	if (substr($domain, -strlen('googlebot.com')) == 'googlebot.com' || substr($domain, -strlen('google.com')) == 'google.com')
	{
		debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Disabled protection for google bot: $_SERVER[HTTP_USER_AGENT], $domain");
		$disable_security_check = true;
	} else
	{
		if (is_file("$config[project_path]/admin/data/system/googlebot.json"))
		{
			$json_data_decoded = @json_decode(file_get_contents("$config[project_path]/admin/data/system/googlebot.json"), true);
			if (is_array($json_data_decoded['prefixes']))
			{
				foreach ($json_data_decoded['prefixes'] as $network_prefix)
				{
					$network_prefix = trim($network_prefix['ipv4Prefix'] ?? $network_prefix['ipv6Prefix']);
					if (KvsUtilities::is_ip_in_network(trim($_SERVER['REMOTE_ADDR']), $network_prefix))
					{
						debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Disabled protection for google bot: $_SERVER[HTTP_USER_AGENT], $network_prefix");
						$disable_security_check = true;
						break;
					}
				}
			}
		}
		if (!$disable_security_check)
		{
			debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Failed to disable protection for google bot: $_SERVER[HTTP_USER_AGENT], $domain");
		}
	}
} elseif (stripos($_SERVER['HTTP_USER_AGENT'], 'bingbot') !== false)
{
	if (is_file("$config[project_path]/admin/data/system/bingbot.json"))
	{
		$json_data_decoded = @json_decode(file_get_contents("$config[project_path]/admin/data/system/bingbot.json"), true);
		if (is_array($json_data_decoded['prefixes']))
		{
			foreach ($json_data_decoded['prefixes'] as $network_prefix)
			{
				$network_prefix = trim($network_prefix['ipv4Prefix'] ?? $network_prefix['ipv6Prefix']);
				if (KvsUtilities::is_ip_in_network(trim($_SERVER['REMOTE_ADDR']), $network_prefix))
				{
					debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Disabled protection for bing bot: $_SERVER[HTTP_USER_AGENT], $network_prefix");
					$disable_security_check = true;
					break;
				}
			}
		}
	}
	if (!$disable_security_check)
	{
		debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Failed to disable protection for bing bot: $_SERVER[HTTP_USER_AGENT], $domain");
	}
}

$white_ips = $hotlink_info['ANTI_HOTLINK_WHITE_IPS'];
if ($white_ips != '')
{
	$white_ips = array_map('trim', explode(',', $white_ips));
	foreach ($white_ips as $white_ip)
	{
		if (KvsUtilities::is_ip_in_network(trim($_SERVER['REMOTE_ADDR']), $white_ip))
		{
			debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Disabled protection for whitelisted IP based on \"$white_ip\" rule");
			$disable_security_check = true;
			break;
		}
	}
}

$range_start = 0;
if ($_SERVER['HTTP_RANGE'] != '')
{
	unset($ranges);
	if (preg_match('/^bytes=((\d*-\d*,? ?)+)$/', $_SERVER['HTTP_RANGE'], $ranges))
	{
		$ranges = explode('-', $ranges[1]);
		$range_start = intval($ranges[0]);
	}
}

// if antihotlink is enabled, check protection data
if ($hotlink_info['ENABLE_ANTI_HOTLINK']==1 && !$disable_security_check)
{
	if ($current_format['is_hotlink_protection_disabled']==0)
	{
		if (intval($hotlink_info['ANTI_HOTLINK_TYPE'])==1)
		{
			$allowed=0;
			$hash_check2=substr(md5($hash.$config['cv'].$_SERVER['REMOTE_ADDR']),0,10);
			if ($hash_check2==$hash2)
			{
				$allowed=1;
			}

			if ($allowed==0)
			{
				$hash_encoded=$hash;
				if ($hotlink_info['ANTI_HOTLINK_ENCODE_LINKS']==1)
				{
					for ($i=0;$i<strlen($hash_encoded);$i++)
					{
						$new_pos=$i;
						for ($j=$i;$j<strlen($config['ahv']);$j++)
						{
							$val=intval($config['ahv'][$j]);
							$new_pos+=$val;
						}
						while ($new_pos>=strlen($hash_encoded))
						{
							$new_pos-=strlen($hash_encoded);
						}
						$t=$hash_encoded[$i];
						$hash_encoded[$i]=$hash_encoded[$new_pos];
						$hash_encoded[$new_pos]=$t;
					}
					$hash_check2=substr(md5($hash_encoded.$config['cv'].$_SERVER['REMOTE_ADDR']),0,10);
					if ($hash_check2==$hash2)
					{
						$allowed=1;
					}
				}
				if ($allowed==0)
				{
					$check_ips=array();
					if (trim($_COOKIE['kt_ips'])!='')
					{
						$check_ips=explode(',', trim($_COOKIE['kt_ips']));
					} elseif ($_SERVER['HTTP_COOKIES']!='')
					{
						$cookies=explode(';',$_SERVER['HTTP_COOKIES']);
						foreach ($cookies as $cookie)
						{
							if (strpos(trim($cookie),'kt_ips=')===0)
							{
								$cookie=explode('=',$cookie,2);
								$check_ips=explode(',',trim(urldecode($cookie[1])));
								break;
							}
						}
					}
					if (is_array($check_ips))
					{
						foreach ($check_ips as $check_ip)
						{
							$check_ip=trim($check_ip);
							$hash_check2=substr(md5($hash.$config['cv'].$check_ip),0,10);
							if ($hash_check2==$hash2)
							{
								$allowed=1;
								break;
							}
							$hash_check2=substr(md5($hash_encoded.$config['cv'].$check_ip),0,10);
							if ($hash_check2==$hash2)
							{
								$allowed=1;
							}
						}
					}
				}
				if ($allowed==0)
				{
					start_session();
					$check_ips=$_SESSION['lock_ips'];
					if (is_array($check_ips))
					{
						foreach ($check_ips as $check_ip=>$v)
						{
							$hash_check2=substr(md5($hash.$config['cv'].$check_ip),0,10);
							if ($hash_check2==$hash2)
							{
								$allowed=1;
								break;
							}
							$hash_check2=substr(md5($hash_encoded.$config['cv'].$check_ip),0,10);
							if ($hash_check2==$hash2)
							{
								$allowed=1;
							}
						}
					}
				}
			}

			if ($allowed==0)
			{
				debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Access denied due to invalid IP: $_SERVER[REMOTE_ADDR]");
				if (trim($hotlink_info['ANTI_HOTLINK_FILE'])<>'' && $admin_rq_server_id==0)
				{
					header("Location: ".trim($hotlink_info['ANTI_HOTLINK_FILE']));
				} else {
					http_response_code(410);
					header("KVS-Errno: 3");
					echo "Access denied (errno 3)";
				}
				die;
			}
		}

		if ($_SERVER['HTTP_REFERER'] != '')
		{
			$referer = str_replace('www.', '', $_SERVER['HTTP_REFERER']);
			if (strpos($referer, str_replace('www.', '', $config['project_url'])) !== 0)
			{
				$ref_host = parse_url($referer, PHP_URL_HOST);
				$host = parse_url(str_replace('www.', '', $config['project_url']), PHP_URL_HOST);
				if (strpos($ref_host, ".$host") === false)
				{
					$allowed = 0;
					if ($hotlink_info['ANTI_HOTLINK_WHITE_DOMAINS'] != '')
					{
						$white_domains = explode(',', $hotlink_info['ANTI_HOTLINK_WHITE_DOMAINS']);
						foreach ($white_domains as $white_domain)
						{
							$host = trim(str_replace('http://', '', str_replace('https://', '', str_replace('www.', '', $white_domain))));
							if (strpos($ref_host, $host) !== false)
							{
								$allowed = 1;
								break;
							}
						}
					}
					if ($ref_host === $host)
					{
						$allowed = 1;
					}
					if ($ref_host == 'mediaservices.cdn-apple.com')
					{
						$allowed = 1;
					}

					if ($allowed == 0)
					{
						debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Access denied due to invalid referer: $_SERVER[HTTP_REFERER]");
						if (trim($hotlink_info['ANTI_HOTLINK_FILE']) != '' && $admin_rq_server_id == 0)
						{
							header("Location: " . trim($hotlink_info['ANTI_HOTLINK_FILE']));
						} else
						{
							http_response_code(410);
							header('KVS-Errno: 1');
							echo 'Access denied (errno 1)';
						}
						die;
					}
				}
			}
		}
	}

	if ($range_start==0 && intval($hotlink_info['ANTI_HOTLINK_ENABLE_IP_LIMIT'])==1)
	{
		if ($postfix != '_preview.mp4' && intval($current_format['limit_number_parts']) <= 1 && (intval($current_format['limit_total_duration']) == 0 || intval($current_format['limit_total_duration']) >= 30))
		{
			// log ip data for ip protection
			// do not log video files of preview format and those that have multiple parts and duration limit below 30 seconds
			file_put_contents("$config[project_path]/admin/data/stats/ip_data.dat", "$_SERVER[REMOTE_ADDR]|" . time() . "\r\n", FILE_APPEND | LOCK_EX);
		}

		if (is_file("$config[project_path]/admin/data/stats/ip_blocked.dat"))
		{
			$bad_ips = file("$config[project_path]/admin/data/stats/ip_blocked.dat");
			if (is_array($bad_ips))
			{
				$bad_ips = array_map('trim', $bad_ips);

				if (in_array($_SERVER['REMOTE_ADDR'], $bad_ips))
				{
					debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Access denied due to blocked IP: $_SERVER[REMOTE_ADDR]");
					if (trim($hotlink_info['ANTI_HOTLINK_FILE']) <> '' && $admin_rq_server_id == 0)
					{
						header("Location: " . trim($hotlink_info['ANTI_HOTLINK_FILE']));
					} else
					{
						http_response_code(410);
						header("KVS-Errno: 4");
						echo "Access denied (errno 4)";
					}
					die;
				}
			}
		}
	}
}

if ($current_format['access_level_id']>0 && !$disable_security_check)
{
	start_session();
	if ($_SESSION['userdata']['user_id']<1)
	{
		// check if user has access level for watching this video format
		if ($current_format['access_level_id']==1 && $_SESSION['user_id']<1)
		{
			debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Access denied due to member-protected file access");
			http_response_code(403);
			header("KVS-Errno: 5");
			echo "Access denied / Only active members can access this video file (errno 5)";die;
		} elseif ($current_format['access_level_id']==2 && $_SESSION['status_id']!=3)
		{
			$has_premium_access_as_owner=false;
			if (isset($_REQUEST['ov']) && md5($config['cv'].$_SESSION['user_id'])==$_REQUEST['ov'])
			{
				$has_premium_access_as_owner=true;
			}

			$has_premium_access_by_tokens=false;
			if ($_SESSION['status_id']==2)
			{
				foreach ($_SESSION['content_purchased'] as $purchase)
				{
					if ($purchase['video_id']==$video_id)
					{
						$has_premium_access_by_tokens=true;
						break;
					}
				}
			}
			if (!$has_premium_access_by_tokens && !$has_premium_access_as_owner)
			{
				debug_get_file("$_SERVER[REMOTE_ADDR]  $_SERVER[REQUEST_URI]  Access denied due to premium-protected file access");
				http_response_code(403);
				header("KVS-Errno: 5");
				echo "Access denied / Only Premium members can access this video file (errno 5)";die;
			}
		}
	}
}

if ($_SERVER['REQUEST_METHOD'] != 'HEAD' && $range_start == 0)
{
	if (intval($stats_settings['collect_videos_stats_video_files']) == 1 || intval($stats_settings['collect_memberzone_stats_video_files']) == 1)
	{
		$user_id = 0;
		if (intval($stats_settings['collect_memberzone_stats_video_files']) == 1)
		{
			start_session();
			$user_id = intval($_SESSION['user_id']);
		}

		$fh = fopen("$config[project_path]/admin/data/stats/video_files.dat", "a+");
		flock($fh, LOCK_EX);
		fwrite($fh, "$video_id||" . date("Y-m-d H:i:s") . "||$postfix||$user_id||" . intval($_REQUEST['start_sec']) . "\r\n");
		fclose($fh);
	}
}

$data=unserialize(file_get_contents("admin/data/system/cluster.dat"));
$countries=array();

// filter out servers from other groups and countries
$data_user_country=array();
$sum_weight_country=0;
$data_other=array();
$sum_weight_other=0;
$data_default=array();

foreach ($data as $server)
{
	if ($admin_rq_server_id==$server['server_id'])
	{
		$server_admin_rq=$server;
	} elseif ($server['status_id']==1 && $server['streaming_type_id']!=5 && intval($server['group_id'])==$sg_id)
	{
		$countries=explode(',',$server['lb_countries']);
		if (strlen($server['lb_countries'])>0 && array_cnt($countries)>0)
		{
			foreach ($countries as $country)
			{
				if (strtolower(trim($country))==strtolower($_SERVER['GEOIP_COUNTRY_CODE']))
				{
					$data_user_country[]=$server;
					$sum_weight_country+=$server['lb_weight'];
				}
			}
		} else {
			$data_other[]=$server;
			$sum_weight_other+=$server['lb_weight'];
		}
		$data_default[]=$server;
	}
}

unset($target_file, $target_server, $is_remote, $control_script, $control_script_lock_ip);

// determine which server should be used for returning video
if (isset($server_admin_rq))
{
	$target_file="$server_admin_rq[urls]/$file";
	$target_server=$server_admin_rq['urls'];
	$is_remote=intval($server_admin_rq['is_remote']);
	$control_script=$server_admin_rq['control_script_url'];
	$control_script_lock_ip=intval($server_admin_rq['control_script_url_lock_ip']);
	$time_offset=$server_admin_rq['time_offset'];
	$streaming_type_id=$server_admin_rq['streaming_type_id'];
	$streaming_api_script=$server_admin_rq['streaming_script'];
	$streaming_api_name=str_replace(".php","",$streaming_api_script);
	$streaming_key=$server_admin_rq['streaming_key'];
	$is_replace_domain_on_satellite=intval($server_admin_rq['is_replace_domain_on_satellite']);
} else
{
	// prefer servers geographically nearest to the visitor (proximity-based routing)
	require_once 'admin/include/functions_proximity.php';
	$data_lb=proximity_select_servers($data_default,$_SERVER['GEOIP_COUNTRY_CODE']);
	$sum_weight=0;
	foreach ($data_lb as $server)
	{
		$sum_weight+=$server['lb_weight'];
	}

	// fall back to legacy country-assignment routing when proximity is undeterminable
	if (array_cnt($data_lb)==0)
	{
		if (array_cnt($data_user_country)>0)
		{
			$data_lb=$data_user_country;
			$sum_weight=$sum_weight_country;
		} else {
			$data_lb=$data_other;
			$sum_weight=$sum_weight_other;
		}
	}

	if (array_cnt($data_lb)==1)
	{
		$result_server=$data_lb[0];
	} else {
		$time=time();
		$time-=($time%300);
		srand($time+$video_id);
		$lb_value=rand(1,$sum_weight);
		$cur_value=0;
		foreach ($data_lb as $server)
		{
			if ($lb_value<=$cur_value+$server['lb_weight'])
			{
				$result_server=$server;
				break;
			}
			$cur_value+=$server['lb_weight'];
		}
		if (!isset($result_server))
		{
			$result_server=$data_default[0];
		}

		if (in_array($result_server['error_id'],[2,3,4,5,6]))
		{
			foreach ($data_lb as $server)
			{
				if (!in_array($server['error_id'],[2,3,4,5,6]))
				{
					$result_server=$server;
					break;
				}
			}
		}
	}

	$target_file="$result_server[urls]/$file";
	$target_server=$result_server['urls'];
	$is_remote=intval($result_server['is_remote']);
	$control_script=$result_server['control_script_url'];
	$control_script_lock_ip=intval($result_server['control_script_url_lock_ip']);
	$time_offset=$result_server['time_offset'];
	$streaming_type_id=$result_server['streaming_type_id'];
	$streaming_api_script=$result_server['streaming_script'];
	$streaming_api_name=str_replace(".php","",$streaming_api_script);
	$streaming_key=$result_server['streaming_key'];
	$is_replace_domain_on_satellite=intval($result_server['is_replace_domain_on_satellite']);
}

if ($is_replace_domain_on_satellite==1)
{
	if ($config['is_clone_db']=="true" && $config['satellite_for']!='')
	{
		$target_file=str_replace($config['satellite_for'],$config['project_licence_domain'],$target_file);
		$control_script=str_replace($config['satellite_for'],$config['project_licence_domain'],$control_script);
		if (strpos($target_file,'https://')!==false && strpos($config['project_url'],'https://')===false)
		{
			$target_file=str_replace('https://','http://',$target_file);
		}
		if (strpos($control_script,'https://')!==false && strpos($config['project_url'],'https://')===false)
		{
			$control_script=str_replace('https://','http://',$control_script);
		}
	}
	if ($config['mirror_for']!='')
	{
		$target_file=str_replace($config['mirror_for'],$config['project_licence_domain'],$target_file);
		$control_script=str_replace($config['mirror_for'],$config['project_licence_domain'],$control_script);
		if (strpos($target_file,'https://')!==false && strpos($config['project_url'],'https://')===false)
		{
			$target_file=str_replace('https://','http://',$target_file);
		}
		if (strpos($control_script,'https://')!==false && strpos($config['project_url'],'https://')===false)
		{
			$control_script=str_replace('https://','http://',$control_script);
		}
	}
}

$limit=0;
if (intval($current_format['limit_speed_option'])+intval($current_format['limit_speed_guests_option'])+intval($current_format['limit_speed_standard_option'])+intval($current_format['limit_speed_premium_option'])+intval($current_format['limit_speed_embed_option'])>0)
{
	$limit_option=intval($current_format['limit_speed_option']);
	$limit_value=floatval($current_format['limit_speed_value']);
	if (intval($current_format['limit_speed_option'])!=intval($current_format['limit_speed_guests_option']) || number_format($current_format['limit_speed_value'],1)!=number_format($current_format['limit_speed_guests_value'],1))
	{
		start_session();
		if (intval($_SESSION['user_id'])==0)
		{
			$limit_option=intval($current_format['limit_speed_guests_option']);
			$limit_value=floatval($current_format['limit_speed_guests_value']);
		}
	}
	if (intval($current_format['limit_speed_option'])!=intval($current_format['limit_speed_standard_option']) || number_format($current_format['limit_speed_value'],1)!=number_format($current_format['limit_speed_standard_value'],1))
	{
		start_session();
		if (intval($_SESSION['user_id'])>0 && intval($_SESSION['status_id'])!=3)
		{
			$limit_option=intval($current_format['limit_speed_standard_option']);
			$limit_value=floatval($current_format['limit_speed_standard_value']);
		}
	}
	if (intval($current_format['limit_speed_option'])!=intval($current_format['limit_speed_premium_option']) || number_format($current_format['limit_speed_value'],1)!=number_format($current_format['limit_speed_premium_value'],1))
	{
		start_session();
		if (intval($_SESSION['user_id'])>0 && intval($_SESSION['status_id'])==3)
		{
			$limit_option=intval($current_format['limit_speed_premium_option']);
			$limit_value=floatval($current_format['limit_speed_premium_value']);
		}
	}
	if (intval($current_format['limit_speed_option'])!=intval($current_format['limit_speed_embed_option']) || number_format($current_format['limit_speed_value'],1)!=number_format($current_format['limit_speed_embed_value'],1))
	{
		if ($_REQUEST['embed']=='true')
		{
			$limit_option=intval($current_format['limit_speed_embed_option']);
			$limit_value=floatval($current_format['limit_speed_embed_value']);
		}
	}
	if ($limit_option==1)
	{
		$limit=intval($limit_value);
	} elseif ($limit_option==2 && intval($_REQUEST['br'])>0)
	{
		$limit=intval($limit_value*intval($_REQUEST['br']));
	}
}

if ($current_format['limit_speed_countries']!='')
{
	$countries=explode(',',$current_format['limit_speed_countries']);
	if (array_cnt($countries)>0)
	{
		$is_country_in_limit=false;
		foreach ($countries as $country)
		{
			if (strtolower(trim($country))==strtolower($_SERVER['GEOIP_COUNTRY_CODE']))
			{
				$is_country_in_limit=true;
				break;
			}
		}
		if (!$is_country_in_limit)
		{
			$limit=0;
		}
	}
}

if ($disable_security_check)
{
	$limit=0;
}

if ($streaming_type_id==0)
{
	$limit=intval($limit/8*1000);
	if ($is_remote==1)
	{
		$temp=explode("/",substr($target_server,8),2);
		$target_server=$temp[1];
		$target_server=trim($target_server,"/");
		if ($target_server<>'')
		{
			$file="/$target_server/$file";
		} else {
			$file="/$file";
		}
		$time=time();
		if (floatval($time_offset)<>0)
		{
			$time+=floatval($time_offset)*3600;
		}
		$download_str_append='';
		if ($is_download=='true')
		{
			$download_str_append.='&download=true';
		}
		if ($download_filename<>'')
		{
			$download_str_append.="&download_filename=$download_filename";
		}
		$ref_host=str_replace("www.","",$_SERVER['HTTP_HOST']);

		$secret_remote_key = $config['cv'];
		if ($config['cvr'])
		{
			$secret_remote_key = $config['cvr'];
		}
		if ($control_script_lock_ip==1)
		{
			$has_ip_cookie = false;
			$allowed_ips = explode(',', trim($_COOKIE['kt_remote_ips']));
			foreach ($allowed_ips as $allowed_ip)
			{
				$allowed_ip = explode('||', $allowed_ip);
				if ($allowed_ip[0] == $_SERVER['REMOTE_ADDR'])
				{
					$has_ip_cookie = true;
					break;
				}
			}
			if (!$has_ip_cookie)
			{
				$allowed_ips[] = $_SERVER['REMOTE_ADDR'] . '||' . md5($_SERVER['REMOTE_ADDR'] . $secret_remote_key);
				set_cookie('kt_remote_ips', implode(',', $allowed_ips), time() + 3600);
			}

			$file_info=array(
				'time'=>$time,
				'limit'=>$limit,
				'file'=>$file,
				'cv'=>md5($time.$limit.$file.$_SERVER['REMOTE_ADDR'].$secret_remote_key),
			);
			header("Location: $control_script?file=B64".rawurlencode(base64_encode(serialize($file_info))).$download_str_append);
		} else
		{
			header("Location: $control_script?time=".$time."&cv=".md5($time.$secret_remote_key)."&lr=".$limit."&cv2=".md5($time.$limit.$secret_remote_key)."&file=".rawurlencode($file).$download_str_append."&cv3=".md5($ref_host.$secret_remote_key)."&cv4=".md5($file.$secret_remote_key));
		}
	} else {
		$target_file=substr($target_file,strpos($target_file,'/',8));
		$short_file_name=basename($target_file);
		if ($download_filename<>'')
		{
			$short_file_name=$download_filename;
		}
		if (strpos($postfix,".flv")!==false)
		{
			header("Content-Type: video/x-flv");
		} elseif (strpos($postfix,".mp4")!==false)
		{
			header("Content-Type: video/mp4");
		} elseif (strpos($postfix,".webm")!==false)
		{
			header("Content-Type: video/webm");
		} elseif (strpos($postfix,".gif")!==false)
		{
			header("Content-Type: image/gif");
		} else {
			header("Content-Type: application/octet-stream");
		}
		if (intval($limit)>0)
		{
			header("X-Accel-Limit-Rate: $limit");
		}
		if ($is_download=='true')
		{
			header("Content-Disposition: attachment; filename=\"$short_file_name\"");
		} else {
			header("Content-Disposition: inline; filename=\"$short_file_name\"");
		}
		header("X-Accel-Redirect: $target_file");
	}
} elseif ($streaming_type_id==4)
{
	if (is_file("$config[project_path]/admin/cdn/$streaming_api_script"))
	{
		require_once "$config[project_path]/admin/cdn/$streaming_api_script";
		$get_video_function = "{$streaming_api_name}_get_video";
		if (strtolower($_SERVER['REQUEST_METHOD']) == 'head' && function_exists("{$streaming_api_name}_head_video"))
		{
			$get_video_function = "{$streaming_api_name}_head_video";
		}
		if (function_exists($get_video_function))
		{
			$target_url = $target_file;
			$target_file = substr($target_file, strpos($target_file, '/', 8));
			$video_url = $get_video_function($target_file, $target_url, '', $limit, $streaming_key);
			if ($video_url)
			{
				header("Location: $video_url");
				die;
			} else
			{
				header("Location: $target_url");
				die;
			}
		}
	}
	header("Location: $target_file");
} elseif ($streaming_type_id == 5)
{
	http_response_code(404);
	die;
} else
{
	header("Location: $target_file");
}

function debug_get_file($message)
{
	global $config;

	if ($config['enable_debug_get_file']=='true')
	{
		$fp=fopen("$config[project_path]/admin/logs/get_file.txt","a+");
		flock($fp,LOCK_EX);
		if ($message=='')
		{
			fwrite($fp,"\n");
		} else {
			fwrite($fp,date("[Y-m-d H:i:s] ").$message."\n");
		}
		fclose($fp);
	}
}
