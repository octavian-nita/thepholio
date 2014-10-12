<?php

class Contents extends Koken_Controller {

	function __construct()
    {
         parent::__construct();
    }

    function cache()
    {
    	if ($this->method === 'delete')
    	{
			$this->load->helper('file');
			delete_files( FCPATH . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'images', true, 1 );
    	}
    	exit;
    }

    function albums()
    {
		list($params, $id) = $this->parse_params(func_get_args());
		$a = new Album;

		if (isset($params['context']))
		{
			$a->where('id !=', $params['context']);
		}

		$params['auth'] = $this->auth;
		$params['listed'] = false;
		$params['flat'] = true;

    	$final = $a->where_related('content', 'id', $id)->listing($params);
		$this->set_response_data($final);
    }

    function categories()
    {
		list($params, $id) = $this->parse_params(func_get_args());
		$c = new Category;

		$params['auth'] = $this->auth;
		$params['limit_to'] = 'content';

		if (strpos($id, ',') === false)
		{
	    	$final = $c->where_related('content', 'id', $id)->listing($params);
		}
		else
		{
			$final = $c->get_grouped_status(explode(',', $id), 'Content');
		}

		$this->set_response_data($final);
    }

	function index()
	{
		list($params, $id, $slug) = $this->parse_params(func_get_args());

		// Create or update
		if ($this->method != 'get')
		{
			$c = new Content();
			switch($this->method)
			{
				case 'post':
				case 'put':
					if ($this->method == 'put')
					{
						// Update
						$c->get_by_id($id);
						if (!$c->exists())
						{
							$this->error('404', "Content with ID: $id not found.");
							return;
						}

						$c->old_published_on = $c->published_on;
						$c->old_captured_on = $c->captured_on;
						$c->old_uploaded_on = $c->uploaded_on;
					}

					if (isset($_REQUEST['name']))
					{
						if (isset($_REQUEST['upload_session_start']))
						{
							$s = new Setting;
							$s->where('name', 'last_upload')->get();
							if ($s->exists() && $s->value != $_REQUEST['upload_session_start'])
							{
								$s->value = $_REQUEST['upload_session_start'];
								$s->save();
							}
						}

						$file_name = $c->clean_filename($_REQUEST['name']);

						$chunk = isset($_REQUEST["chunk"]) ? $_REQUEST["chunk"] : 0;
						$chunks = isset($_REQUEST["chunks"]) ? $_REQUEST["chunks"] : 0;

						$tmp_dir = FCPATH . 'storage' . DIRECTORY_SEPARATOR . 'tmp';
						$tmp_path = $tmp_dir . DIRECTORY_SEPARATOR . $file_name;

						make_child_dir($tmp_dir);

						if ($chunks == 0 || $chunk == ($chunks - 1))
						{
							if (isset($_REQUEST['text']))
							{
								$path = FCPATH . 'storage' .
										DIRECTORY_SEPARATOR . 'custom' .
										DIRECTORY_SEPARATOR;
								$internal_id = false;
							}
							else if (isset($_REQUEST['plugin']))
							{
								$info = pathinfo($_REQUEST['name']);
								$path = FCPATH . 'storage' .
										DIRECTORY_SEPARATOR . 'plugins' .
										DIRECTORY_SEPARATOR . $_REQUEST['plugin'] .
										DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR;
								$file_name = $_REQUEST['basename'] . '.' . $info['extension'];
								$internal_id = false;
							}
							else
							{
								list($internal_id, $path) = $c->generate_internal_id();
							}
							if ($path)
							{
								$path .= $file_name;
								if ($chunks == 0)
								{
									$tmp_path = $path;
								}
							}
							else
							{
								$this->error('500', 'Unable to create directory for upload.');
								return;
							}
						}

						// Look for the content type header
						if (isset($_SERVER["HTTP_CONTENT_TYPE"]))
						{
							$contentType = $_SERVER["HTTP_CONTENT_TYPE"];
						}
						else if (isset($_SERVER["CONTENT_TYPE"]))
						{
							$contentType = $_SERVER["CONTENT_TYPE"];
						}
						else
						{
							$contentType = '';
						}

						if (strpos($contentType, "multipart") !== false) {
							if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']))
							{
								$out = fopen($tmp_path, $chunk == 0 ? "wb" : "ab");
								if ($out)
								{
									// Read binary input stream and append it to temp file
									$in = fopen($_FILES['file']['tmp_name'], "rb");

									if ($in)
									{
										while ($buff = fread($in, 4096))
										{
											fwrite($out, $buff);
										}
									}
									else
									{
										$this->error('500', 'Unable to read input stream.');
										return;
									}

									fclose($out);
									unlink($_FILES['file']['tmp_name']);
								}
								else
								{
									$this->error('500', 'Unable to write to output file.');
									return;
								}
							}
							else
							{
								$this->error('500', 'Unable to move uploaded file.');
								return;
							}
						}
						else
						{
							$out = fopen($tmp_path, $chunk == 0 ? "wb" : "ab");
							if ($out)
							{
								// Read binary input stream and append it to temp file
								$in = fopen("php://input", "rb");

								if ($in)
								{
									while ($buff = fread($in, 4096))
									{
										fwrite($out, $buff);
									}
								}
								else
								{
									$this->error('500', 'Unable to read uploaded file.');
									return;
								}
								fclose($out);
							}
							else
							{
								$this->error('500', 'Unable to open output stream.');
								return;
							}
						}

						if ($chunk < ($chunks - 1))
						{
							// Don't continue until all chunks are uploaded
							exit;
						}
						else if ($chunks > 0)
						{
							// Done, move to permanent location and save to DB
							rename($tmp_path, $path);
						}

						if (!$internal_id) {
							// Custom text uploads can stop here
							die( json_encode( array( 'filename' => $file_name ) ) );
						}

						$from = array();
						$from['filename'] = $file_name;
						$from['internal_id'] = $internal_id;
						$from['file_modified_on'] = time();
					}
					else if (isset($_POST['localfile']))
					{
						$filename = basename($_REQUEST['localfile']);
						list($internal_id, $path) = $c->generate_internal_id();
						if (!file_exists($_REQUEST['localfile']))
						{
							$this->error('500', '"localfile" does not exist.');
							return;
						}
						if ($path)
						{
							$path .= $filename;
						}
						else
						{
							$this->error('500', 'Unable to create directory for upload.');
							return;
						}
						copy($_REQUEST['localfile'], $path);
						$from = array();
						$from['filename'] = $filename;
						$from['internal_id'] = $internal_id;
						$from['file_modified_on'] = time();
					}
					else if (isset($_POST['from_url']))
					{
						$filename = basename($_POST['from_url']);
						list($internal_id, $path) = $c->generate_internal_id();
						if ($path)
						{
							$path .= $filename;
						}
						else
						{
							$this->error('500', 'Unable to create directory for upload.');
							return;
						}
						if ($this->_download(urldecode($_POST['from_url']), $path, true) && file_exists($path))
						{
							$from = array();
							$from['filename'] = $filename;
							$from['internal_id'] = $internal_id;
							$from['file_modified_on'] = time();
						}
						else
						{
							$this->error('500', 'Unable to import file from provided URL.');
							return;
						}
					}
					else if (is_null($id))
					{
						$this->error('403', 'New content records must be accompanied by an upload.');
						return;
					}

					if (isset($from))
					{
						$from = array_merge($_POST, $from);
					}
					else
					{
						$from = $_POST;
					}

					if (isset($_REQUEST['rotate']) &&
						is_numeric($_REQUEST['rotate']) &&
						$c->exists())
					{
						$r = $_REQUEST['rotate'];
						if (abs($r) != 90)
						{
							$this->error('403', 'Rotation can only be done in multiples of 90.');
							return;
						}
						$path = $c->path_to_original();

						$info = pathinfo($path);
						$midsize = preg_replace('/\.' . $info['extension'] . '$/', '.1600.' . $info['extension'], $path);

						$s = new Setting;
						$s->where('name', 'image_processing_library')->get();

						include_once(FCPATH . 'app' . DIRECTORY_SEPARATOR . 'koken' . DIRECTORY_SEPARATOR . 'DarkroomUtils.php');

						$d = DarkroomUtils::init($s->value);

						$d->rotate($path, $r);

						if (file_exists($midsize))
						{
							$d->rotate($midsize, $r);
						}

						$c->clear_cache();
						$from['width'] = $c->height;
						$from['height'] = $c->width;
						$from['aspect_ratio'] = $from['width'] / $from['height'];
						$from['file_modified_on'] = time();
					}

					if (isset($_REQUEST['reset_internal_id']) &&
						$_REQUEST['reset_internal_id'] &&
						$c->exists())
					{
						list($from['internal_id'],) = $c->generate_internal_id(true);
					}

					$hook = 'content.' . ( $id ? 'update' : 'create' );

					if (isset($from['filename']) && $id)
					{
						$c->clear_cache();
						$hook .= '_with_upload';
						$c->_before();
					}

					$from = Shutter::filter("api.$hook", array_merge($from, array('id' => $id, 'file' => isset($path) ? $path : $c->path_to_original() )));

					unset($from['file']);

					$c->from_array($from, array(), true);

					if (isset($_POST['tags']))
					{
						$c->_format_tags($_POST['tags']);
					}
					else if ($this->method === 'put' && isset($_POST['visibility']))
					{
						$c->_update_tag_counts();
					}

					$c->_readify();

					$content = $c->to_array(array('auth' => true));

					if (($hook === 'content.create' || $hook === 'content.update_with_upload') && ENVIRONMENT === 'production')
					{
						$this->load->library('mcurl');
						if ($this->mcurl->is_enabled())
						{
							$options = array(
								CURLOPT_HTTPHEADER => array(
									'Connection: Close',
									'Keep-Alive: 0'
								)
							);

							$this->mcurl->add_call('normal', 'get', $content['presets']['medium_large']['url'], array(), $options);
							$this->mcurl->add_call('cropped', 'get', $content['presets']['medium_large']['cropped']['url'], array(), $options);
							$this->mcurl->execute();
						}
					}

					Shutter::hook($hook, $content);

					$this->redirect("/content/{$c->id}" . ( isset($params['context']) ? '/context:' . $params['context'] : '' ));
					break;
				case 'delete':
					if (is_null($id))
					{
						$this->error('403', 'Required parameter "id" not present.');
						return;
					}
					else
					{
						$t = new Tag();
						if (is_numeric($id))
						{
							$content = $c->get_by_id($id);
							if ($c->exists())
							{
								$trash = new Trash();
								var_dump($this->db->query("DELETE from {$trash->table} WHERE id = 'content-{$c->id}'"));
								$c->do_delete();
							}
							else
							{
								$this->error('404', "Content with ID: $id not found.");
								return;
							}
						}
						else
						{
							$is_trash = $id === 'trash';

							if ($id === 'trash')
							{
								$id = array();
								$trash = new Trash();
								$trash->like('id', 'content-')->get_iterated();
								foreach($trash as $item)
								{
									$content = unserialize(utf8_decode($item->data));
									if (!$content)
									{
										$content = unserialize($item->data);
									}
									$id[] = $content['id'];
								}
							}
							else
							{
								$id = explode(',', $id);
							}

							/*
								Multiple delete
							 	/content/n1/n2/n3
							*/
							// Keep track of tags to --
							$tags = array();

							$c->where_in('id', $id);
							$contents = $c->get_iterated();
							$trash = new Trash();
							foreach($contents as $c)
							{
								if ($c->exists())
								{
									$tags = array_merge($tags, $c->tags);
									$this->db->query("DELETE from {$trash->table} WHERE id = 'content-{$c->id}'");
									$c->do_delete();
								}
							}
						}
					}
					exit;
					break;
			}
		}
		$c = new Content();
		if ($slug || (isset($id) && strpos($id, ',') === false))
		{
			$options = array(
				'context' => false,
				'neighbors' => false
			);
			$options = array_merge($options, $params);

			$original_context = $options['context'];

			if ($options['context'] && !in_array($options['context'], array('stream', 'favorites', 'features')) && strpos($options['context'], 'tag-') !== 0 && strpos($options['context'], 'category-') !== 0)
			{
				if (is_numeric($options['context']))
				{
					$context_field = 'id';
				}
				else
				{
					$context_field = 'slug';
					$options['context'] = str_replace('slug-', '', $options['context']);
				}

				$a = new Album();
				$a->group_start()
						->where($context_field, $options['context'])
						->or_where('internal_id', $options['context'])
					->group_end()
					->get();

				$c->include_join_fields()->where_related_album('id', $a->id);
			}

			$with_token = false;

			if (is_numeric($id))
			{
				$content = $c->where('deleted', 0)->get_by_id($id);
			}
			else
			{
				if ($slug)
				{
					$content = $c->where('deleted', 0)
									->group_start()
										->where('internal_id', $slug)
										->or_where('slug', $slug)
										->or_where('old_slug', $slug)
									->group_end()
									->get();
				}
				else
				{
					$content = $c->where('deleted', 0)
									->where('internal_id', $id)
									->get();
				}

				if ($content->exists() && $content->internal_id === ( is_null($id) ? $slug : $id))
				{
					$with_token = true;
				}
			}

			if ($content->exists())
			{
				if (($c->visibility == 1 && !$this->auth && !$with_token) || (!$this->auth && !is_numeric($id) && $c->visibility == 2))
				{
					$this->error('403', 'Private content.');
					return;
				}

				$options['auth'] = $this->auth;

				if ($options['neighbors'])
				{
					// Make sure $neighbors is at least 2
					$options['neighbors'] = max($options['neighbors'], 2);

					// Make sure neighbors is even
					if ($options['neighbors'] & 1 != 0)
					{
						$options['neighbors']++;
					}

					$options['neighbors'] = $options['neighbors']/2;
					$single_neighbors = false;
				}
				else
				{
					$options['neighbors'] = 1;
					$single_neighbors = true;
				}

				if ($options['context'] && !in_array($original_context, array('stream', 'favorites', 'features')) && strpos($original_context, 'tag-') !== 0 && strpos($original_context, 'category-') !== 0)
				{
					$options['in_album'] = $a;
				}

				$final = $content->to_array($options);

				if ($options['context'])
				{
					// TODO: Performance check
					$next = new Content;
					$prev = new Content;
					$in_a = new Album;

					$next->where('deleted', 0);
					$prev->where('deleted', 0);

					$options['context'] = urldecode($options['context']);

					if (!in_array($original_context, array('stream', 'favorites', 'features')) && strpos($original_context, 'tag-') !== 0 && strpos($original_context, 'category-') !== 0)
					{
						if (!isset($options['context_order']))
						{
							$options['context_order'] = 'manual';
							$options['context_order_direction'] = 'ASC';
						}

						$final['context']['album'] = $a->to_array(array('auth' => $this->auth || $options['context'] === $a->internal_id));

						$in_a->where("$context_field !=", $options['context']);

						$next->where_related_album('id', $a->id);
						$prev->where_related_album('id', $a->id);

						if ($options['context_order'] === 'manual')
						{
							$next->order_by_join_field('album', 'order', 'ASC')
								->group_start()
									->where_join_field('album', 'order >', $content->join_order)
									->or_group_start()
										->where_join_field('album', 'order', $content->join_order)
										->where_join_field('album', 'id >', $content->join_id)
									->group_end()
								->group_end();

							$prev->order_by_join_field('album', 'order', 'DESC')
								->group_start()
									->where_join_field('album', 'order <', $content->join_order)
									->or_group_start()
										->where_join_field('album', 'order', $content->join_order)
										->where_join_field('album', 'id <', $content->join_id)
									->group_end()
								->group_end();
						}
						else
						{
							$next_operator = strtolower($options['context_order_direction']) === 'desc' ? '<' : '>';
							$prev_operator = $next_operator === '<' ? '>' : '<';

							$next
								->group_start()
									->where($options['context_order'] . " $next_operator", $content->{$options['context_order']})
									->or_group_start()
										->where($options['context_order'], $content->{$options['context_order']})
										->where("id $next_operator", $content->id)
									->group_end()
								->group_end();

							$prev
								->group_start()
									->where($options['context_order'] . " $prev_operator", $content->{$options['context_order']})
									->or_group_start()
										->where($options['context_order'], $content->{$options['context_order']})
										->where("id $prev_operator", $content->id)
									->group_end()
								->group_end();
						}

						if (!$this->auth)
						{
							$next->where('visibility <', $final['context']['album']['listed'] ? 1 : 2);
							$prev->where('visibility <', $final['context']['album']['listed'] ? 1 : 2);
						}

						$in_album = $a;

						$final['context']['type'] = 'album';
						$final['context']['title'] = $a->title;
						$final['context']['__koken_url'] = $final['context']['album']['__koken_url'];
						$final['context']['url'] = $final['context']['album']['url'];
					}
					else
					{
						if (!isset($options['context_order']))
						{
							$options['context_order'] = 'captured_on';
							$options['context_order_direction'] = 'DESC';
						}
						else if ($options['context_order'] === 'manual' && $original_context === 'favorites')
						{
							$options['context_order'] = 'favorite_order';
							$options['context_order_direction'] = 'ASC';
						}
						else if ($options['context_order'] === 'manual' && $original_context === 'features')
						{
							$options['context_order'] = 'featured_order';
							$options['context_order_direction'] = 'ASC';
						}

						$next_operator = strtolower($options['context_order_direction']) === 'desc' ? '<' : '>';
						$prev_operator = $next_operator === '<' ? '>' : '<';

						$next
							->group_start()
								->where($options['context_order'] . " $next_operator", $content->{$options['context_order']})
								->or_group_start()
									->where($options['context_order'], $content->{$options['context_order']})
									->where("id $next_operator", $content->id)
								->group_end()
							->group_end();

						$prev
							->group_start()
								->where($options['context_order'] . " $prev_operator", $content->{$options['context_order']})
								->or_group_start()
									->where($options['context_order'], $content->{$options['context_order']})
									->where("id $prev_operator", $content->id)
								->group_end()
							->group_end();

						if (strpos($original_context, 'tag-') === 0 )
						{
							$tag = str_replace('tag-', '', urldecode($original_context));
							$t = new Tag;
							$t->where('name', $tag)->get();
							if ($t->exists())
							{
								$next->where_related_tag('id', $t->id);
								$prev->where_related_tag('id', $t->id);
								$final['context']['type'] = 'tag';
								$final['context']['title'] = $tag;
								$final['context']['slug'] = $tag;

								$t->model = 'tag_contents';
								$t->slug = $t->name;
								$url = $t->url();

								if ($url)
								{
									list($final['context']['__koken_url'], $final['context']['url']) = $url;
								}
							}
						}
						else if (strpos($original_context, 'category-') === 0)
						{
							$category = str_replace('category-', '', $original_context);
							$cat = new Category;
							$cat->where('slug', $category)->get();
							if ($cat->exists())
							{
								$next->where_related_category('id', $cat->id);
								$prev->where_related_category('id', $cat->id);
								$final['context']['type'] = 'category';
								$final['context']['title'] = $cat->title;
								$final['context']['slug'] = $cat->slug;

								$cat->model = 'category_contents';
								$url = $cat->url();

								if ($url)
								{
									list($final['context']['__koken_url'], $final['context']['url']) = $url;
								}
							}
						}
						else if ($original_context === 'favorites')
						{
							$url_data = $prev->get_data();
							$urls = $prev->form_urls();
							$next->where('favorite', 1);
							$prev->where('favorite', 1);
							$final['context']['type'] = 'favorite';
							$final['context']['title'] = $url_data['favorite']['plural'];
							$final['context']['__koken_url'] = $urls['favorites'];

							if ($final['context']['__koken_url'])
							{
								$final['context']['url'] = $prev->get_base() . $final['context']['__koken_url'] . ( defined('DRAFT_CONTEXT') && !is_numeric(DRAFT_CONTEXT) ? '&preview=' . DRAFT_CONTEXT : '' );
							}
						}
						else if ($original_context === 'features')
						{
							$url_data = $prev->get_data();
							$urls = $prev->form_urls();
							$next->where('featured', 1);
							$prev->where('featured', 1);
							$final['context']['type'] = 'feature';
							$final['context']['title'] = $url_data['feature']['plural'];
							$final['context']['__koken_url'] = isset($urls['features']) ? $urls['features'] : false;

							if ($final['context']['__koken_url'])
							{
								$final['context']['url'] = $prev->get_base() . $final['context']['__koken_url'] . ( defined('DRAFT_CONTEXT') && !is_numeric(DRAFT_CONTEXT) ? '&preview=' . DRAFT_CONTEXT : '' );
							}
						}

						if (!$this->auth)
						{
							$next->where('visibility', 0);
							$prev->where('visibility', 0);
						}

						$in_album = false;
					}

					$max = $next->get_clone()->count();
					$min = $prev->get_clone()->count();
					$final['context']['total'] = $max + $min + 1;
					$final['context']['position'] = $min + 1;
					$pre_limit = $next_limit = $options['neighbors'];

					if ($min < $pre_limit)
					{
						$next_limit += ($pre_limit - $min);
						$pre_limit = $min;
					}
					if ($max < $next_limit)
					{
						$pre_limit = min($min, $pre_limit + ($next_limit - $max));
						$next_limit = $max;
					}

					$final['context']['previous'] = array();
					$final['context']['next'] = array();

					if ($next_limit > 0)
					{
						if ($options['context_order'] !== 'manual')
						{
							$next->order_by($options['context_order'] . ' ' . $options['context_order_direction'] . ', id ' . $options['context_order_direction']);
						}

						$next->limit($next_limit)->get_iterated();

						foreach($next as $c)
						{
							$final['context']['next'][] = $c->to_array( array('auth' => $this->auth, 'in_album' => $in_album, 'context' => $original_context) );
						}
					}

					if ($pre_limit > 0)
					{
						if ($options['context_order'] !== 'manual')
						{
							$dir = strtolower($options['context_order_direction']) === 'desc' ? 'asc' : 'desc';
							$prev->order_by($options['context_order'] . ' ' . $dir . ', id ' . $dir);
						}

						$prev->limit($pre_limit)->get_iterated();

						foreach($prev as $c)
						{
							$final['context']['previous'][] = $c->to_array( array('auth' => $this->auth, 'in_album' => $in_album, 'context' => $original_context) );
						}
						$final['context']['previous'] = array_reverse($final['context']['previous']);
					}
				}
			}
			else
			{
				$this->error('404', "Content with ID: $id not found.");
				return;
			}
		}
		else if (isset($params['custom']))
		{
			$final = $c->to_array_custom($params['custom']);
		}
		else
		{
			$c->where('deleted', 0);
			$params['auth'] = $this->auth;
			$final = $c->listing($params, $id);
		}

		$this->set_response_data($final);
	}
}

/* End of file contents.php */
/* Location: ./system/application/controllers/contents.php */