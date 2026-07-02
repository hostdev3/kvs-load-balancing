<?php
/* Developed by Kernel Team.
   http://kernel-team.com
*/

/*
   Proximity-based server selection.

   GeoIP in this product is country-level only ($_SERVER['GEOIP_COUNTRY_CODE']),
   and a storage server's only geographic attribute is its lb_countries list.
   This module interprets each server's lb_countries as the countries where that
   server is physically located, and routes a visitor to the geographically
   nearest server regardless of explicit country assignment.

   A server with an empty lb_countries list (the group's default/catch-all server)
   has no explicit location, so it is treated as located in PROXIMITY_DEFAULT_COUNTRY
   (Germany by default) and takes part in proximity routing from there. Set that
   constant to '' to instead keep untagged servers as pure fallback.

   To get correct proximity routing, tag every storage server with the country
   it physically sits in via its "load balancing countries" field.
*/

if (!defined('PROXIMITY_TIER_KM'))
{
	// Servers whose distance to the visitor is within this many kilometers of the
	// nearest located server are treated as equally near, and load is split among
	// them by lb_weight (preserving weighted load balancing within a region).
	// Increase to spread load across a wider regional cluster; set to 0 to always
	// pick the single closest server.
	define('PROXIMITY_TIER_KM', 500);
}

if (!defined('PROXIMITY_DEFAULT_COUNTRY'))
{
	// ISO country code used as the physical location of servers that have no
	// lb_countries set (the group's default/catch-all server). With this set, such
	// servers take part in proximity routing as if located in this country instead
	// of being a pure fallback. Set to '' to keep untagged servers as fallback-only.
	define('PROXIMITY_DEFAULT_COUNTRY', 'DE');
}

// Approximate geographic centroid (latitude, longitude) per ISO 3166-1 alpha-2 code.
function proximity_country_coordinates($country_code)
{
	static $coords = array(
		'AD'=>array(42.5,1.6),    'AE'=>array(24.0,54.0),   'AF'=>array(33.0,65.0),
		'AG'=>array(17.05,-61.8), 'AI'=>array(18.25,-63.17),'AL'=>array(41.0,20.0),
		'AM'=>array(40.0,45.0),   'AO'=>array(-12.5,18.5),  'AR'=>array(-34.0,-64.0),
		'AT'=>array(47.33,13.33), 'AU'=>array(-27.0,133.0), 'AW'=>array(12.5,-69.97),
		'AZ'=>array(40.5,47.5),   'BA'=>array(44.0,18.0),   'BB'=>array(13.17,-59.53),
		'BD'=>array(24.0,90.0),   'BE'=>array(50.83,4.0),   'BF'=>array(13.0,-2.0),
		'BG'=>array(43.0,25.0),   'BH'=>array(26.0,50.55),  'BI'=>array(-3.5,30.0),
		'BJ'=>array(9.5,2.25),    'BM'=>array(32.33,-64.75),'BN'=>array(4.5,114.67),
		'BO'=>array(-17.0,-65.0), 'BR'=>array(-10.0,-55.0), 'BS'=>array(24.25,-76.0),
		'BT'=>array(27.5,90.5),   'BW'=>array(-22.0,24.0),  'BY'=>array(53.0,28.0),
		'BZ'=>array(17.25,-88.75),'CA'=>array(60.0,-96.0),  'CD'=>array(0.0,25.0),
		'CF'=>array(7.0,21.0),    'CG'=>array(-1.0,15.0),   'CH'=>array(47.0,8.0),
		'CI'=>array(8.0,-5.5),    'CL'=>array(-30.0,-71.0), 'CM'=>array(6.0,12.0),
		'CN'=>array(35.0,105.0),  'CO'=>array(4.0,-72.0),   'CR'=>array(10.0,-84.0),
		'CU'=>array(21.5,-80.0),  'CV'=>array(16.0,-24.0),  'CY'=>array(35.0,33.0),
		'CZ'=>array(49.75,15.5),  'DE'=>array(51.0,9.0),    'DJ'=>array(11.5,43.0),
		'DK'=>array(56.0,10.0),   'DM'=>array(15.42,-61.33),'DO'=>array(19.0,-70.67),
		'DZ'=>array(28.0,3.0),    'EC'=>array(-2.0,-77.5),  'EE'=>array(59.0,26.0),
		'EG'=>array(27.0,30.0),   'ER'=>array(15.0,39.0),   'ES'=>array(40.0,-4.0),
		'ET'=>array(8.0,38.0),    'FI'=>array(64.0,26.0),   'FJ'=>array(-18.0,178.0),
		'FK'=>array(-51.75,-59.0),'FM'=>array(6.92,158.25), 'FO'=>array(62.0,-7.0),
		'FR'=>array(46.0,2.0),    'GA'=>array(-1.0,11.75),  'GB'=>array(54.0,-2.0),
		'GD'=>array(12.12,-61.67),'GE'=>array(42.0,43.5),   'GF'=>array(4.0,-53.0),
		'GH'=>array(8.0,-2.0),    'GI'=>array(36.13,-5.35), 'GL'=>array(72.0,-40.0),
		'GM'=>array(13.47,-16.57),'GN'=>array(11.0,-10.0),  'GP'=>array(16.25,-61.58),
		'GQ'=>array(2.0,10.0),    'GR'=>array(39.0,22.0),   'GT'=>array(15.5,-90.25),
		'GU'=>array(13.47,144.78),'GW'=>array(12.0,-15.0),  'GY'=>array(5.0,-59.0),
		'HK'=>array(22.25,114.17),'HN'=>array(15.0,-86.5),  'HR'=>array(45.17,15.5),
		'HT'=>array(19.0,-72.42), 'HU'=>array(47.0,20.0),   'ID'=>array(-5.0,120.0),
		'IE'=>array(53.0,-8.0),   'IL'=>array(31.5,34.75),  'IN'=>array(20.0,77.0),
		'IQ'=>array(33.0,44.0),   'IR'=>array(32.0,53.0),   'IS'=>array(65.0,-18.0),
		'IT'=>array(42.83,12.83), 'JM'=>array(18.25,-77.5), 'JO'=>array(31.0,36.0),
		'JP'=>array(36.0,138.0),  'KE'=>array(1.0,38.0),    'KG'=>array(41.0,75.0),
		'KH'=>array(13.0,105.0),  'KI'=>array(1.42,173.0),  'KM'=>array(-12.17,44.25),
		'KN'=>array(17.33,-62.75),'KP'=>array(40.0,127.0),  'KR'=>array(37.0,127.5),
		'KW'=>array(29.34,47.66), 'KY'=>array(19.5,-80.5),  'KZ'=>array(48.0,68.0),
		'LA'=>array(18.0,105.0),  'LB'=>array(33.83,35.83), 'LC'=>array(13.88,-61.13),
		'LI'=>array(47.17,9.53),  'LK'=>array(7.0,81.0),    'LR'=>array(6.5,-9.5),
		'LS'=>array(-29.5,28.5),  'LT'=>array(56.0,24.0),   'LU'=>array(49.75,6.17),
		'LV'=>array(57.0,25.0),   'LY'=>array(25.0,17.0),   'MA'=>array(32.0,-5.0),
		'MC'=>array(43.73,7.4),   'MD'=>array(47.0,29.0),   'ME'=>array(42.5,19.3),
		'MG'=>array(-20.0,47.0),  'MK'=>array(41.83,22.0),  'ML'=>array(17.0,-4.0),
		'MM'=>array(22.0,98.0),   'MN'=>array(46.0,105.0),  'MO'=>array(22.17,113.55),
		'MQ'=>array(14.67,-61.0), 'MR'=>array(20.0,-12.0),  'MT'=>array(35.83,14.58),
		'MU'=>array(-20.28,57.55),'MV'=>array(3.25,73.0),   'MW'=>array(-13.5,34.0),
		'MX'=>array(23.0,-102.0), 'MY'=>array(2.5,112.5),   'MZ'=>array(-18.25,35.0),
		'NA'=>array(-22.0,17.0),  'NC'=>array(-21.5,165.5), 'NE'=>array(16.0,8.0),
		'NG'=>array(10.0,8.0),    'NI'=>array(13.0,-85.0),  'NL'=>array(52.5,5.75),
		'NO'=>array(62.0,10.0),   'NP'=>array(28.0,84.0),   'NZ'=>array(-41.0,174.0),
		'OM'=>array(21.0,57.0),   'PA'=>array(9.0,-80.0),   'PE'=>array(-10.0,-76.0),
		'PF'=>array(-15.0,-140.0),'PG'=>array(-6.0,147.0),  'PH'=>array(13.0,122.0),
		'PK'=>array(30.0,70.0),   'PL'=>array(52.0,20.0),   'PR'=>array(18.25,-66.5),
		'PS'=>array(32.0,35.25),  'PT'=>array(39.5,-8.0),   'PY'=>array(-23.0,-58.0),
		'QA'=>array(25.5,51.25),  'RE'=>array(-21.1,55.6),  'RO'=>array(46.0,25.0),
		'RS'=>array(44.0,21.0),   'RU'=>array(60.0,100.0),  'RW'=>array(-2.0,30.0),
		'SA'=>array(25.0,45.0),   'SB'=>array(-8.0,159.0),  'SC'=>array(-4.58,55.67),
		'SD'=>array(15.0,30.0),   'SE'=>array(62.0,15.0),   'SG'=>array(1.37,103.8),
		'SI'=>array(46.0,15.0),   'SK'=>array(48.67,19.5),  'SL'=>array(8.5,-11.5),
		'SM'=>array(43.93,12.42), 'SN'=>array(14.0,-14.0),  'SO'=>array(10.0,49.0),
		'SR'=>array(4.0,-56.0),   'SS'=>array(7.0,30.0),    'ST'=>array(1.0,7.0),
		'SV'=>array(13.83,-88.92),'SY'=>array(35.0,38.0),   'SZ'=>array(-26.5,31.5),
		'TC'=>array(21.75,-71.58),'TD'=>array(15.0,19.0),   'TG'=>array(8.0,1.17),
		'TH'=>array(15.0,100.0),  'TJ'=>array(39.0,71.0),   'TL'=>array(-8.83,125.92),
		'TM'=>array(40.0,60.0),   'TN'=>array(34.0,9.0),    'TO'=>array(-20.0,-175.0),
		'TR'=>array(39.0,35.0),   'TT'=>array(11.0,-61.0),  'TW'=>array(23.5,121.0),
		'TZ'=>array(-6.0,35.0),   'UA'=>array(49.0,32.0),   'UG'=>array(1.0,32.0),
		'US'=>array(38.0,-97.0),  'UY'=>array(-33.0,-56.0), 'UZ'=>array(41.0,64.0),
		'VC'=>array(13.25,-61.2), 'VE'=>array(8.0,-66.0),   'VG'=>array(18.5,-64.5),
		'VI'=>array(18.34,-64.93),'VN'=>array(16.17,107.83),'VU'=>array(-16.0,167.0),
		'WS'=>array(-13.58,-172.33),'XK'=>array(42.58,20.9),'YE'=>array(15.0,48.0),
		'YT'=>array(-12.83,45.17),'ZA'=>array(-29.0,24.0),  'ZM'=>array(-15.0,30.0),
		'ZW'=>array(-20.0,30.0),
	);

	$cc = strtoupper(trim($country_code));
	return isset($coords[$cc]) ? $coords[$cc] : null;
}

// Great-circle distance between two lat/lon points, in kilometers.
function proximity_haversine($lat1, $lon1, $lat2, $lon2)
{
	$earth_radius = 6371; // km
	$d_lat = deg2rad($lat2 - $lat1);
	$d_lon = deg2rad($lon2 - $lon1);
	$a = sin($d_lat / 2) * sin($d_lat / 2) +
		cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d_lon / 2) * sin($d_lon / 2);
	return $earth_radius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Distance in km between two ISO country codes, or null if either is unknown.
function proximity_country_distance($cc1, $cc2)
{
	if (strtoupper(trim($cc1)) === strtoupper(trim($cc2)))
	{
		return 0.0;
	}
	$a = proximity_country_coordinates($cc1);
	$b = proximity_country_coordinates($cc2);
	if ($a === null || $b === null)
	{
		return null;
	}
	return proximity_haversine($a[0], $a[1], $b[0], $b[1]);
}

// Distance of a server to the visitor = nearest of the server's location countries.
// A server with no lb_countries is treated as located in PROXIMITY_DEFAULT_COUNTRY.
// Returns null only when the server has no usable location at all.
function proximity_server_distance($server, $user_country)
{
	$countries = (isset($server['lb_countries']) && trim($server['lb_countries']) !== '')
		? $server['lb_countries']
		: PROXIMITY_DEFAULT_COUNTRY;
	if (trim($countries) === '')
	{
		return null;
	}
	$best = null;
	foreach (explode(',', $countries) as $cc)
	{
		$d = proximity_country_distance($cc, $user_country);
		if ($d === null)
		{
			continue;
		}
		if ($best === null || $d < $best)
		{
			$best = $d;
		}
	}
	return $best;
}

// Returns the subset of $servers nearest to the visitor: the closest located
// server plus any other located server within PROXIMITY_TIER_KM of it (so load
// is balanced across a regional cluster by lb_weight). Returns an empty array
// when proximity can't be determined (unknown visitor country, or no server has
// a usable location), in which case the caller should fall back to legacy routing.
function proximity_select_servers($servers, $user_country)
{
	$user_country = strtoupper(trim($user_country));
	if ($user_country === '' || proximity_country_coordinates($user_country) === null)
	{
		return array();
	}
	if (!is_array($servers))
	{
		return array();
	}

	$located = array();
	$min = null;
	foreach ($servers as $server)
	{
		$d = proximity_server_distance($server, $user_country);
		if ($d === null)
		{
			continue;
		}
		$located[] = array('server' => $server, 'distance' => $d);
		if ($min === null || $d < $min)
		{
			$min = $d;
		}
	}
	if ($min === null)
	{
		return array();
	}

	$result = array();
	foreach ($located as $entry)
	{
		if ($entry['distance'] <= $min + PROXIMITY_TIER_KM)
		{
			$result[] = $entry['server'];
		}
	}
	return $result;
}
