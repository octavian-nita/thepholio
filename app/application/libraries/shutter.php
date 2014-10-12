<?php

class Shutter {

	private static $filters = array();
	private static $hooks = array();
	private static $shortcodes = array();
	private static $plugin_info = array();
	private static $scripts = array();
	private static $active_plugins = array();
	public static $active_pulse_plugins = array();
	private static $class_map = array();

	private static function plugin_is_active($callback)
	{
		return in_array( get_class( $callback[0] ), self::$active_plugins);
	}

	public static function get_json_api($url, $to_json = true)
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HEADER, 0);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_USERAGENT, 'Koken/' . KOKEN_VERSION);
		$info = curl_exec($curl);
		curl_close($curl);

		if ($to_json)
		{
			return json_decode($info);
		}
		else
		{
			return $info;
		}
	}

	public static function get_oembed($url)
	{
		if (!defined('FCPATH')) return false; // Shouldn't be called outside of API context

		$parts = explode('url=', $url);
		$url = $parts[0] . 'url=' . urlencode($parts[1]);

		$url = preg_replace('~^http://www\.flickr\.com~', 'https://www.flickr.com', $url);

		$hash = md5($url) . '.oembed.cache';
		$cache = FCPATH . 'storage' . DIRECTORY_SEPARATOR .
					'cache' . DIRECTORY_SEPARATOR .
					'api' . DIRECTORY_SEPARATOR . $hash;

		if (file_exists($cache) && (time() - filemtime($cache)) < 3600)
		{
			$info = file_get_contents($cache);
			$json = json_decode($info, true);
		}
		else
		{
			$json_string = self::get_json_api($url, false);
			$json = json_decode($json_string, true);
			if ($json && !isset($json->error))
			{
				file_put_contents($cache, $json_string);
			}
		}

		return $json;
	}

	public static function init()
	{
		$root = dirname(dirname(dirname(dirname(__FILE__))));

		// Loads Koken internal plugins
		$iterator = new DirectoryIterator("$root/app/plugins");
		foreach($iterator as $fileinfo)
		{
			$dir = $fileinfo->getPath() . '/' . $fileinfo->getFilename();
			$plugin = $dir . '/plugin.php';
			$info = $dir . '/plugin.json';
			$console = file_exists($dir . 'console' . DIRECTORY_SEPARATOR . 'plugin.js');

			if ($fileinfo->isDir() && !$fileinfo->isDot() && file_exists($plugin)) {
				include($plugin);
				$data = json_decode(file_get_contents($info), true);
				$klasses = get_declared_classes();
				$last = array_pop( $klasses );
				$data['php_class'] = new $last;
				$data['php_class_name'] = get_class($data['php_class']);
				self::$active_plugins[] = $data['php_class_name'];
				$data['path'] = $fileinfo->getFilename();
				$data['internal'] = true;
				$data['pulse'] = false;
				$data['console'] = $console;
				self::$plugin_info[] = $data;
			}
		}

		// Loads userland plugins installed in storage/plugins
		$iterator = new DirectoryIterator("$root/storage/plugins");
		foreach($iterator as $fileinfo)
		{
			if ($fileinfo->getFilename() === 'index.html') continue;
			$dir = $fileinfo->getPath() . '/' . $fileinfo->getFilename();
			$plugin = $dir. '/plugin.php';
			$pulse = $dir. '/pulse.json';
			$info = $dir. '/plugin.json';
			$guid = $dir. '/koken.guid';
			$console = file_exists($dir . DIRECTORY_SEPARATOR . 'console' . DIRECTORY_SEPARATOR . 'plugin.js');
			$data = false;

			if ($fileinfo->isDir() && !$fileinfo->isDot()) {
				if (file_exists($info))
				{
					$data = json_decode(file_get_contents($info), true);
					if ($data)
					{
						if (!file_exists($plugin) && !isset($data['oembeds']))
						{
							continue;
						}
						$data['path'] = $fileinfo->getFilename();
						if (file_exists($plugin))
						{
							$raw_plugin = file_get_contents($plugin);
							preg_match('/class\s([^\s]+)\sextends\sKokenPlugin/m', $raw_plugin, $matches);

							if ($matches && !class_exists($matches[1]))
							{
								include($plugin);
								$klasses = get_declared_classes();
								$last = array_pop( $klasses );
								$data['php_class'] = new $last;
								$data['php_class_name'] = get_class($data['php_class']);
								self::$class_map[ $data['php_class_name'] ] = $data['php_class'];
							}
						}
						$data['pulse'] = $data['internal'] = false;
						$data['console'] = $console;
					}
				}
				else if (file_exists($pulse))
				{
					$data = json_decode(file_get_contents($pulse), true);
					$data['path'] = $fileinfo->getFilename();
					$data['plugin'] = '/storage/plugins/' . $data['path'] . '/' . $data['plugin'];
					$data['pulse'] = true;
					$data['internal'] = false;
					$data['ident'] = $data['id'];
				}

				if (file_exists($guid))
				{
					$data['koken_store_guid'] = trim(file_get_contents($guid));
				}

				if ($data)
				{
					self::$plugin_info[] = $data;
				}

			}
		}
	}

	public static function plugins()
	{
		return self::$plugin_info;
	}

	public static function finalize_plugins($plugins)
	{
		foreach($plugins as $plugin)
		{
			if ($plugin['pulse'] && $plugin['activated'])
			{
				self::$active_pulse_plugins[] = array(
					'key' => $plugin['ident'],
					'path' => $plugin['plugin']
				);
			}
			else if (isset($plugin['php_class_name']) && $plugin['activated'] && ($plugin['internal'] || $plugin['setup']))
			{
				self::$active_plugins[] = $plugin['php_class_name'];
			}

			if (!empty($plugin['data']) && isset($plugin['php_class_name']) && isset(self::$class_map[ $plugin['php_class_name'] ]))
			{
				$d = new stdClass;
				foreach($plugin['data'] as $key => $data)
				{
					if (!isset($data['value']))
					{
						$data['value'] = '';
					}
					$d->$key = $data['value'];
				}
				self::$class_map[ $plugin['php_class_name'] ]->set_data( $d );
			}
		}
	}

	public static function hook($name, $obj = null)
	{
		if (!isset(self::$hooks[$name])) return;

		$to_call = self::$hooks[$name];
		if (!empty($to_call))
		{
			foreach($to_call as $callback)
			{
				if (self::plugin_is_active($callback))
				{
					if (is_array($obj) && !isset($obj['__koken__']))
					{
						$data = call_user_func_array($callback, $obj);
					}
					else
					{
						$data = call_user_func($callback, $obj);
					}
				}
			}
		}
	}

	public static function shortcodes($content, $args)
	{
		$scripts = array();

		preg_match_all('/\[([a-z_]+)(\s(.*?))?\]/', $content, $matches);

		foreach($matches[0] as $index => $match) {
			$tag = $match;
			$code = $matches[1][$index];
			$attr = $matches[3][$index];
			if (isset(self::$shortcodes[$code]) && self::plugin_is_active(self::$shortcodes[$code]))
			{
				if (!empty($attr))
				{
					preg_match_all('/([a-z_]+)="([^"]+)?"/', $attr, $attrs);
					$attr = array_combine($attrs[1], $attrs[2]);
				}
				$attr['_relative_root'] = array_shift(explode('api.php', $_SERVER['PHP_SELF']));

				foreach($attr as $key => &$val) {
					$val = str_replace(array('__quot__', '__lt__', '__gt__', '__n__', '__lb__', '__rb__', '__perc__'), array('"', '<', '>', "\n", '[', ']', '%'), $val);
				}

				$filtered = call_user_func(self::$shortcodes[$code], $attr);
				if (is_array($filtered))
				{
					$replacement = $filtered[0];
					if (empty($filtered[1]))
					{
						$filtered[1] = array();
					}
					else if (!is_array($filtered[1]))
					{
						$filtered[1] = array($filtered[1]);
					}
					foreach($filtered[1] as $script)
					{
						if (!in_array($script, $scripts))
						{
							$scripts[] = $script;
						}
					}

				}
				else
				{
					$replacement = $filtered;
				}
				$content = str_replace($tag, $replacement, $content);
			}
		}

		if (!empty($scripts))
		{
			$base = array_shift(explode('/api.php', $_SERVER['REQUEST_URI']));
			foreach($scripts as &$script)
			{
				$script = '<script src="' . $base . $script . '"></script>';
			}
			$content = join('', $scripts) . $content;
		}
		return $content;
	}

	public static function filter($name, $args)
	{
		$data = is_array($args) && isset($args[0]) ? array_shift($args) : $args;

		if (!isset(self::$filters[$name]))
		{
			return $data;
		}

		$to_call = self::$filters[$name];

		if (!empty($to_call))
		{
			foreach($to_call as $callback)
			{
				if (self::plugin_is_active($callback))
				{
					if (is_array($args))
					{
						$data = call_user_func_array($callback, array_merge(array($data), $args));
					}
					else
					{
						$data = call_user_func($callback, $data);
					}
				}
			}
		}

		return $data;
	}

	public static function register_hook($name, $arr)
	{
		if (!isset(self::$hooks[$name]))
		{
			self::$hooks[$name] = array();
		}

		if (in_array($arr, self::$hooks[$name])) return;

		self::$hooks[$name][] = $arr;
	}

	public static function register_filter($name, $arr)
	{
		if (!isset(self::$filters[$name]))
		{
			self::$filters[$name] = array();
		}

		if (in_array($arr, self::$filters[$name])) return;

		self::$filters[$name][] = $arr;
	}

	public static function register_shortcode($name, $arr)
	{
		if (!isset(self::$shortcodes[$name]))
		{
			self::$shortcodes[$name] = $arr;
		}
	}

	public static function register_site_script($path, $plugin)
	{
		$item = array('path' => $path, 'plugin' => $plugin);

		if (!in_array($item, self::$scripts))
		{
			self::$scripts[] = $item;
		}
	}

	private static function get_active_site_script_paths()
	{
		$scripts = array();

		foreach(self::$scripts as $arr)
		{
			if (self::plugin_is_active(array($arr['plugin'])) && file_exists($arr['path']))
			{
				$scripts[] = $arr['path'];
			}
		}

		return $scripts;
	}

	public static function get_site_scripts()
	{
		$scripts = self::get_active_site_script_paths();

		$output = array();
		foreach($scripts as $path)
		{
			$output[] = file_get_contents($path);
		}

		return $output;
	}

	public static function get_site_scripts_timestamp()
	{
		$scripts = self::get_active_site_script_paths();

		if (empty($scripts))
		{
			return KOKEN_VERSION;
		}
		else
		{
			return md5(join('', $scripts));
		}


	}

	public static function hook_exists($name)
	{
		if (!isset(self::$hooks[$name]) || empty(self::$hooks[$name]))
		{
			return false;
		}

		foreach(self::$hooks[$name] as $callback)
		{
			if (self::plugin_is_active($callback))
			{
				return true;
			}
		}

		return false;
	}
}

class KokenPlugin {

	protected $data = array();
	protected $require_setup = false;
	public $database_fields = false;

	function after_setup()
	{
		return true;
	}

	function is_compatible()
	{
		return true;
	}

	function require_setup()
	{
		return $this->require_setup;
	}

	function confirm_setup()
	{
		return true;
	}

	function set_data($data)
	{
		$this->data = (object) array_merge((array) $this->data, (array) $data);
	}

	function save_data()
	{
		if (class_exists('Plugin'))
		{
			$p = new Plugin;
			$p->where('path', $this->get_key())->get();

			$p->data = serialize( (array) $this->get_data() );
			$p->save();
		}
		else
		{
			return false;
		}
	}

	function get_data()
	{
		return $this->data;
	}


	/* Following functions are "final" and cannot be overriden in plugin classes */

	final protected function get_key()
	{
		$reflector = new ReflectionClass(get_class($this));
		return basename(dirname($reflector->getFileName()));
	}

	final protected function clear_image_cache($id = false)
	{
		$root = dirname(dirname(dirname(dirname(__FILE__))));
		include_once($root . '/app/helpers/file_helper.php');
		$path = $root . '/storage/cache/images';
		if ($id)
		{
			$padded_id = str_pad($id, 6, '0', STR_PAD_LEFT);
			$path .= '/' . substr($padded_id, 0, 3) . '/' . substr($padded_id, 3);
		}
		delete_files($path, true, 1);
	}

	final protected function get_file_path()
	{
		$root = dirname(dirname(dirname(dirname(__FILE__))));
		return $root . '/storage/plugins/' . $this->get_key();
	}

	final protected function get_storage_path()
	{
		return $this->get_file_path() . '/storage';
	}

	final protected function get_path()
	{
		return Koken::$location['real_root_folder'] . '/storage/plugins/' . $this->get_key();
	}

	final protected function request_token()
	{
		if (class_exists('Application') && isset($_POST))
		{
			$a = new Application;
			$a->single_use = 1;
			$a->role = 'read-write';
			$a->token = koken_rand();
			$a->save();
			return $a->token;
		}
		else
		{
			return false;
		}
	}

	final protected function register_hook($hook, $method)
	{
		Shutter::register_hook($hook, array($this, $method));
	}

	final protected function register_filter($filter, $method)
	{
		Shutter::register_filter($filter, array($this, $method));
	}

	final protected function register_shortcode($shortcode, $method)
	{
		Shutter::register_shortcode($shortcode, array($this, $method));
	}

	final protected function register_site_script($path)
	{
		Shutter::register_site_script($path, $this);
	}

	final protected function download_file($f, $to)
	{
		if (extension_loaded('curl')) {
			$cp = curl_init($f);
			$fp = fopen($to, "w+");
			if (!$fp) {
				curl_close($cp);
				return false;
			} else {
				curl_setopt($cp, CURLOPT_FILE, $fp);
				curl_exec($cp);
				$code = curl_getinfo($cp, CURLINFO_HTTP_CODE);
				curl_close($cp);
				fclose($fp);

				if ($code >= 400)
				{
					unlink($to);
					return false;
				}
			}
		} elseif (ini_get('allow_url_fopen')) {
			if (!copy($f, $to)) {
				return false;
			}
		}
		return true;
	}
}

Shutter::init();