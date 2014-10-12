<?php

	date_default_timezone_set('UTC');

	ini_set('display_errors', 0);
	error_reporting(0);

	set_time_limit(30);

	class KokenAPI {
		private $curl;
		private $token;
		private $protocol = 'http';
		private $cache_dir;

		function __construct()
		{
			// BASEPATH required for key.php include
			define('BASEPATH', true);
			include 'storage/configuration/key.php';
			$this->token = $KOKEN_ENCRYPTION_KEY;

			$this->curl = curl_init();

			$is_ssl = isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1 : $_SERVER['SERVER_PORT'] == 443;
			$this->protocol = $is_ssl ? 'https' : 'http';

			$this->cache_dir = dirname(__FILE__) .
								DIRECTORY_SEPARATOR . 'storage' .
								DIRECTORY_SEPARATOR . 'cache' .
								DIRECTORY_SEPARATOR . 'api';
		}

		public function get($url)
		{
			$url .= '/token:' . $this->token;

			$stamp = $this->cache_dir . DIRECTORY_SEPARATOR . 'stamp';
			$cache_file = $this->cache_dir . DIRECTORY_SEPARATOR . md5($url) . '.auth.cache';

			if (file_exists($cache_file) && filemtime($cache_file) >= filemtime($stamp))
			{
				$data = json_decode( file_get_contents($cache_file), true );
			}
			else
			{
				$url = $this->protocol . '://' . $_SERVER['HTTP_HOST'] . preg_replace('~/i\.php.*~', "/api.php?$url", $_SERVER['SCRIPT_NAME']);

				curl_setopt($this->curl, CURLOPT_URL, $url);
				curl_setopt($this->curl, CURLOPT_HEADER, 0);
				curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($this->curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1944.0 Safari/537.36');
				curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($this->curl, CURLOPT_TIMEOUT, 10);

				curl_setopt($this->curl, CURLOPT_HTTPHEADER, array(
					'Connection: Keep-Alive',
					'Keep-Alive: 2',
					'Cache-Control: must-revalidate'
				));

				if ($this->protocol === 'https')
				{
					curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 0);
					curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
				}

				$data = json_decode( curl_exec($this->curl), true );
			}

			return $data;
		}

		public function clear()
		{
			curl_close($this->curl);
		}
	}

	function dd($file)
	{
		$disabled_functions = explode(',', str_replace(' ', '', ini_get('disable_functions')));

		if (is_callable('readfile') && !in_array('readfile', $disabled_functions)) {
			readfile($file);
			exit;
		} else {
			die(file_get_contents($file));
		}
	}

	if (isset($_GET['path']))
	{
		$path = $_GET['path'];
	}
	else if (isset($_SERVER['QUERY_STRING']))
	{
		$path = urldecode($_SERVER['QUERY_STRING']);
	}
	else if (isset($_SERVER['PATH_INFO']))
	{
		$path = $_SERVER['PATH_INFO'];
	}
	else if (isset($_SERVER['REQUEST_URI']))
	{
		$path = preg_replace('/.*\/i.php/', '', $_SERVER['REQUEST_URI']);
	}

	$ds = DIRECTORY_SEPARATOR;
	$root = dirname(__FILE__);
	$storage = $root . $ds . 'storage';
	$base = $storage . $ds . 'cache' . $ds . 'images';
	$file = $base . str_replace('/', $ds, $path);

	$dl = $base64 = false;
	if (preg_match('/\.dl$/', $file))
	{
		$file = preg_replace('/\.dl$/', '', $file);
		$dl = true;
	}
	else if (preg_match('/\.64$/', $file))
	{
		$file = preg_replace('/\.64$/', '', $file);
		$base64 = true;
	}

	$lock = preg_replace('/\.(jpe?g|gif|png|svg)$/i', '.lock', $file);

	if (is_callable('register_shutdown_function'))
	{
		function shutdown()
		{
			global $lock;
			unlink($lock);
		}

		register_shutdown_function('shutdown');
	}

	$new = false;

	$waited = 0;
	while (!file_exists($file) && file_exists($lock) && $waited < 5) {
		sleep(1);
		$waited++;
	}

	$exists = file_exists($file);

	$info = pathinfo($file);
	$ext = $info['extension'];

	if ($exists)
	{
		$realpath = realpath($base);
		$realpathfile = realpath($file);

		if ($exists && (!$realpathfile || strpos($realpathfile, $realpath) !== 0))
		{
			// Bad request
			header('HTTP/1.1 403 Forbidden');
			exit;
		}
	}
	else
	{
		if (!is_dir(dirname($file)))
		{
			$parent_perms = substr(sprintf('%o', fileperms(dirname(dirname(dirname(dirname($file)))))), -4);
			$old = umask(0);
			mkdir(dirname($file), octdec($parent_perms), true);
			umask($old);
		}

		touch($lock);

		$new = $preset = true;

		preg_match('/^\/((?:[0-9]{3}\/[0-9]{3})|custom)\/(.*)[,\/](tiny|small|medium|medium_large|large|xlarge|huge)\.(crop\.)?(2x\.)?(?:\d{9,10}\.)?(?P<ext>jpe?g|gif|png|svg)(\.dl|.64)?$/i', $path, $matches);

		// If $matches is empty, they are requesting a custom size
		if (empty($matches))
		{
			preg_match('/^\/((?:[0-9]{3}\/[0-9]{3})|custom)\/(.*)[,\/]([0-9]+)\.([0-9]+)\.([0-9]{1,3})\.([0-9]{1,3})\.(crop\.)?(2x\.)?(?:\d{9,10}\.)?(?P<ext>jpe?g|gif|png|svg)(\.dl|.64)?$/i', $path, $matches);
			$preset = false;
		}

		if (empty($matches))
		{
			// Bad request
			header('HTTP/1.1 403 Forbidden');
			exit;
		}

		$custom = $matches[1] === 'custom';

 		// No path traversing in file name
 		if (preg_match("/[^a-zA-Z0-9._-]/", $matches[2])) {
			header('HTTP/1.1 403 Forbidden');
			exit;
		}

		$KokenAPI = new KokenAPI;
		$settings = $KokenAPI->get('/settings');

		require $root . '/app/application/libraries/shutter.php';
		$plugins = $KokenAPI->get('/plugins');
		Shutter::finalize_plugins($plugins['plugins']);

		if ($custom)
		{
			$original = $storage . $ds . 'custom' . $ds . preg_replace('/\-(jpe?g|gif|png)$/i', '.$1', $matches[2]);
			list($source_width, $source_height) = getimagesize($original);
		}
		else
		{
			$id = (int) str_replace('/', '', $matches[1]);
			$content = $KokenAPI->get('/content/' . $id);

			$original_info = pathinfo($content['filename']);

			if (!isset($content['html']) && strtolower($original_info['filename']) !== strtolower($matches[2]))
			{
				$KokenAPI->clear();
				header('HTTP/1.1 404 Not Found');
				exit;
			}

			if (isset($content['original']['preview']))
			{
				$original = $root . $content['original']['preview']['relative_url'];
				$source_width = $content['original']['preview']['width'];
				$source_height = $content['original']['preview']['height'];
			}
			else
			{
				$original = $root . $content['original']['relative_url'];
				$source_width = $content['width'];
				$source_height = $content['height'];
			}
		}

		$KokenAPI->clear();

		if (file_exists($original))
		{
			@include($storage . $ds . 'configuration' . $ds . 'user_setup.php');

			if (!defined('MAGICK_PATH'))
			{
				define('MAGICK_PATH_FINAL', 'convert');
			}
			else if (strpos(strtolower(MAGICK_PATH), 'c:\\') !== false)
			{
				define('MAGICK_PATH_FINAL', '"' . MAGICK_PATH . '"');
			}
			else
			{
				define('MAGICK_PATH_FINAL', MAGICK_PATH);
			}

			require($root . $ds . 'app' . $ds . 'koken' . $ds . 'DarkroomUtils.php');

			if ($preset)
			{
				$preset_array = DarkroomUtils::$presets[$matches[3]];
				$w = $preset_array['width'];
				$h = $preset_array['height'];
				$q = $settings['image_' . $matches[3] . '_quality'];
				$sh = $settings['image_' . $matches[3] . '_sharpening'];
				$crop = !empty($matches[4]);
				$hires = !empty($matches[5]);
			}
			else
			{
				list(,,,$w,$h,$q,$sh,$crop) = $matches;
				$crop = (bool) $crop;
				$hires = !empty($matches[8]);
				$sh /= 100;
			}

			$d = DarkroomUtils::init($settings['image_processing_library']);

			// TODO: Fix these create_function calls once we go 5.3
			if ($settings['image_processing_library'] === 'imagick')
			{
				$d->beforeRender(create_function('$imObject, $options, $content', 'return Shutter::filter(\'darkroom.render.imagick\', array($imObject, $options, $content));'), $content);
			}
			else if (strpos($settings['image_processing_library'], 'convert') !== false)
			{
				$d->beforeRender(create_function('$cmd, $options, $content', 'return Shutter::filter(\'darkroom.render.imagemagick\', array($cmd, $options, $content));'), $content);
			}
			else
			{
				$d->beforeRender(create_function('$gdObject, $options, $content', 'return Shutter::filter(\'darkroom.render.gd\', array($gdObject, $options, $content));'), $content);
			}

			$midsize = preg_replace('/\.' . $info['extension'] . '$/', '.1600.' . $info['extension'], $original);

			$d->read($original, $source_width, $source_height)
			  ->resize($w, $h, $crop)
			  ->quality($q)
			  ->sharpen($sh)
			  ->focus($content['focal_point']['x'], $content['focal_point']['y']);

			if (file_exists($midsize))
			{
				$d->alternate($midsize);
			}

			if ($hires)
			{
				$d->retina();
			}

			if (!$settings['retain_image_metadata'] || max($w, $h) < 480 || $settings['image_processing_library'] === 'gd')
			{
				// Work around issue with mbstring.func_overload = 2
				if ((ini_get('mbstring.func_overload') & 2) && function_exists('mb_internal_encoding')) {
					$previous_encoding = mb_internal_encoding();
					mb_internal_encoding('ISO-8859-1');
				}

				require($root . $ds . 'app' . $ds . 'koken' . $ds . 'icc.php');
				$icc = new JPEG_ICC;

				$icc_cache = $original . '.icc';
				if (file_exists($icc_cache))
				{
					$icc->LoadFromICC($icc_cache);
				}
				else
				{
					$icc->LoadFromJpeg($original);
					$icc->SaveToICC($icc_cache);
				}

				$d->strip();
			}

			$d->render($file);

			if (isset($icc))
			{
				$icc->SaveToJPEG($file);
			}

			Shutter::hook('darkroom.render.complete', array($file));
		}
		else
		{
			header('HTTP/1.1 404 Not Found');
			exit;
		}

		if (!file_exists($file))
		{
			header('HTTP/1.1 500 Internal Server Error');
			exit;
		}

	}

	unlink($lock);

	$mtime = filemtime($file);
	$etag = md5($file . $mtime);

	$ext = strtolower($ext);

 	if ($ext == 'jpg')
 	{
 		$ext = 'jpeg';
 	}

	if ($dl)
	{
		header("Content-Disposition: attachment; filename=\"" . basename($file) . "\"");
		header('Content-type: image/' . $ext);
		header('Content-length: ' . filesize($file));

		dd($file);
	}
	else if ($base64)
	{
		$string = base64_encode(file_get_contents($file));
		die("data:image/$ext;base64,$string");
	}

	if (!$new) {
		if (
			(isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) ||
			(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= filemtime($path_to_cache))
		)
		{
			$server_protocol = (isset($_SERVER['SERVER_PROTOCOL'])) ? $_SERVER['SERVER_PROTOCOL'] : false;

			if (substr(php_sapi_name(), 0, 3) === 'cgi')
			{
				header('Status: 304 Not Modified', true);
			}
			elseif ($server_protocol === 'HTTP/1.1' OR $server_protocol === 'HTTP/1.0')
			{
				header($server_protocol . ' 304 Not Modified', true, $code);
			}
			else
			{
				header('HTTP/1.1 304 Not Modified', true, $code);
			}
			exit;
		}
	}

	header('Content-type: image/' . $ext);
	header('Content-length: ' . filesize($file));
	header('Cache-Control: public');
	header('Expires: ' . gmdate('D, d M Y H:i:s', strtotime('+1 year')) . ' GMT');
	header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
	header('ETag: ' . $etag);

	dd($file);