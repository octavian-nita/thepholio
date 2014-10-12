<?php

class Plugins extends Koken_Controller {

	function __construct()
    {
		parent::__construct();
    }

	function call()
	{
		if ($this->auth)
		{
			list($params, $id) = $this->parse_params(func_get_args());

			$p = new Plugin;
			$p->where('path', $params['plugin'])->get();

			if ($p->exists())
			{
				$response = $p->run_plugin_method($params['method'], $this->parse_plugins(), $params);
				if (isset($response['error']))
				{
					$this->error($response['error'], $response['message']);
					return;
				}
				else if (isset($response['koken:redirect']))
				{
					$this->redirect($response['koken:redirect']);
				}
				else
				{
					$this->set_response_data( $response );
				}
			}
		}
		else
		{
			$this->error('401', 'Not authorized to perform this action.');
			return;
		}
	}

	function js()
	{
		$plugins = $this->parse_plugins();
		$js = '';

		foreach($plugins as $plugin)
		{
			if ($plugin['activated'])
			{
				$path = FCPATH . 'storage' .
					DIRECTORY_SEPARATOR . 'plugins' .
					DIRECTORY_SEPARATOR . $plugin['path'] .
					DIRECTORY_SEPARATOR . 'console' .
					DIRECTORY_SEPARATOR . 'plugin.js';

				$tmpl = FCPATH . 'storage' .
					DIRECTORY_SEPARATOR . 'plugins' .
					DIRECTORY_SEPARATOR . $plugin['path'] .
					DIRECTORY_SEPARATOR . 'console' .
					DIRECTORY_SEPARATOR . 'plugin.tmpl.html';

				if (file_exists($path))
				{
					$tmpl = file_exists($tmpl) ? 'true' : 'false';
					$js .= str_replace('Koken.extend(', "Koken.extend('" . $plugin['path'] . "', " . $tmpl . ', ', trim(file_get_contents($path))) . "\n";
				}
			}
		}

		$this->format = 'javascript';
		$this->set_response_data($js);
	}

	function css()
	{
		$plugins = $this->parse_plugins();
		$css = '';
		foreach($plugins as $plugin)
		{
			if ($plugin['activated'])
			{
				$path = FCPATH . 'storage' .
					DIRECTORY_SEPARATOR . 'plugins' .
					DIRECTORY_SEPARATOR . $plugin['path'] .
					DIRECTORY_SEPARATOR . 'console' .
					DIRECTORY_SEPARATOR . 'plugin.css';

				if (file_exists($path))
				{
					$css .= trim(file_get_contents($path)) . "\n";
				}
			}
		}

		$this->format = 'css';
		$this->set_response_data($css);
	}

	function index()
	{

		list($params, $id) = $this->parse_params(func_get_args());
		$plugins = $this->parse_plugins();

		require(FCPATH . 'storage' .
							DIRECTORY_SEPARATOR . 'configuration' .
							DIRECTORY_SEPARATOR . 'database.php');

		switch($this->method)
		{
			case 'delete':
				$p = new Plugin;
				$p->where('id', $id)->get();

				if ($p->exists())
				{
					$p->run_plugin_method('after_uninstall', $plugins);
					$plugin = $p->init($plugins);
					if ($plugin->database_fields)
					{
						$this->load->dbforge();
						foreach($plugin->database_fields as $table => $fields)
						{
							$table = $KOKEN_DATABASE['prefix'] . $table;
							foreach($fields as $column => $info)
							{
								$this->dbforge->drop_column($table, $column);
							}
						}

						$this->_clear_datamapper_cache();
					}

					$p->delete();
				}
				exit;
				break;

			case 'post':
				$p = new Plugin;
				$p->path = $_POST['path'];
				$p->setup = $p->run_plugin_method('require_setup', $plugins) === false;

				if ($p->save())
				{
					$plugin = $p->init($plugins);
					if ($plugin->database_fields)
					{
						$this->load->dbforge();
						foreach($plugin->database_fields as $table => $fields)
						{
							$table = $KOKEN_DATABASE['prefix'] . $table;
							foreach($fields as $column => $info)
							{
								$this->dbforge->add_column($table, array( $column => $info ));
							}
						}

						$this->_clear_datamapper_cache();
					}
					$p->run_plugin_method('after_install', $plugins);
				}

				$this->redirect('/plugins');
				break;

			case 'put':
				unset($_POST['_method']);
				$data = serialize($_POST);
				$p = new Plugin;
				$p->where('id', $id)->get();
				$p->save_data($plugins, $_POST);

				$validate = $p->run_plugin_method('confirm_setup', $plugins, $data);

				if ($validate === true)
				{
					$p->setup = 1;
					$p->save();
					exit;
				}
				else
				{
					$this->set_response_data(array('error' => $validate));
				}
				break;

			default:
				$data = array( 'plugins' => $plugins );
				foreach($data['plugins'] as &$plugin)
				{
					if (isset($plugin['php_class']))
					{
						unset($plugin['php_class']);
					}
				}

				function sortByName($a, $b) {
				    return $a['name'] > $b['name'];
				}

				usort($data['plugins'], 'sortByName');

				$this->set_response_data($data);
				break;
		}

	}
}

/* End of file system.php */
/* Location: ./system/application/controllers/plugins.php */