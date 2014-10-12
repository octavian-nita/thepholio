<?php

class Albums extends Koken_Controller {

	function __construct()
	{
		 parent::__construct();
	}

	function tree()
	{
		list($params,) = $this->parse_params(func_get_args());
		$params = array_merge(array(
				'listed' => 1,
				'include_empty' => true,
				'order_by' => 'manual'
			), $params);

		if (!isset($params['listed']) || !$this->auth)
		{
			$params['listed'] = 1;
		}

		if (!$params['listed'])
		{
			$params['order_by'] = 'title';
		}

		if ($params['order_by'] === 'manual')
		{
			$params['order_by'] = 'left_id';
		}

		$a = new Album();
		$a->select('id,title,album_type,level,left_id,right_id,featured,total_count')
					->where('listed', $params['listed'])
					->where('deleted', 0)
					->order_by($params['order_by'] . ' ASC');

		if (!$params['include_empty'])
		{
			$a->where('total_count >', 0);
		}

		$a->get_iterated();

		$data = $levels = array();

		foreach($a as $album)
		{
			if (!isset($levels['_' . $album->level]))
			{
				$levels['_' . $album->level] = array();
			}

			switch($album->album_type)
			{
				case 2:
					$type = 'set';
					break;
				case 1:
					$type = 'smart';
					break;
				default:
					$type = 'standard';
			}

			$arr = array(
				'id' => $album->id,
				'title' => $album->title,
				'album_type' => $type,
				'left_id' => (int) $album->left_id,
				'level' => (int) $album->level,
				'count' => (int) $album->total_count,
				'featured' => $album->featured == 1
			);

			$levels['_' . $album->level][$album->left_id] = $arr;

		}

		if (!empty($levels))
		{
			$count = count($levels);
			$cycles = 0;
			for ($i = 0; $i < $count - 1; $i++)
			{
				$l = $count - $i;
				$next = '_' . ($l - 1);

				foreach($levels["_$l"] as $left => $arr)
				{
					while($left--)
					{
						if (isset($levels[$next][$left]))
						{
							if (!isset($levels[$next][$left]['children']))
							{
								$levels[$next][$left]['children'] = array();
							}
							$levels[$next][$left]['children'][] = $arr;
							break;
						}
					}
				}
			}

			ksort($levels);

			$data = array_values($levels[array_shift(array_keys($levels))]);
		}

		$this->set_response_data($data);
	}

	function categories()
    {
		list($params, $id) = $this->parse_params(func_get_args());
		$c = new Category;

		$params['auth'] = $this->auth;
		$params['limit_to'] = 'albums';

		if (strpos($id, ',') === false)
		{
	    	$final = $c->where_related('album', 'id', $id)->listing($params);
		}
		else
		{
			$final = $c->get_grouped_status(explode(',', $id), 'Album');
		}

		$this->set_response_data($final);
    }

    function topics()
    {
		list($params, $id) = $this->parse_params(func_get_args());
		$t = new Text;

		$params['auth'] = $this->auth;

    	$final = $t->where_related('album', 'id', $id)->listing($params);
		$this->set_response_data($final);
    }

	function _order($order, $album = false)
	{
		$ids = explode(',', $order);
		$new_order_map = array();

		foreach($ids as $key => $val)
		{
			$pos = $key + 1;
			$new_order_map[$val] = $pos;
		}

		$contents = new Album;
		$contents->where_in('id', $ids);

		$sql = $contents->get_sql() . ' ORDER BY FIELD(id, ' . join(',', $ids) . ')';
		$contents->query($sql);

		$next_slot = $album ? $album->left_id + 1 : 1;

		$this->db->trans_begin();
		$start = strtotime(gmdate("M d Y H:i:s", time()));

		foreach($contents as $sub_album)
		{
			$size = ($sub_album->right_id - $sub_album->left_id) + 1;

			if ($sub_album->left_id != $next_slot)
			{
				$delta = $sub_album->left_id - $next_slot;
				$delta = $delta >= 0 ? '- ' . $delta : '+ '. abs($delta);
				$_a = new Album;
				$_a->where('left_id >=', $sub_album->left_id)
						->where('right_id <=', $sub_album->right_id)
						->where('level >=', $sub_album->level)
						->where('modified_on <', $start)
						->update(array(
							'left_id' => "left_id $delta",
							'right_id' => "right_id $delta",
							'modified_on' => $start
						), false);
			}
			$next_slot += $size;
		}
		$this->db->trans_complete();
	}

	function index()
	{
		list($params, $id, $slug) = $this->parse_params(func_get_args());
		$params['auth'] = $this->auth;
		// Create or update
		if ($this->method != 'get')
		{
			$a = new Album();
			switch($this->method)
			{
				case 'post':
				case 'put':
					if ($this->method == 'put')
					{
						if (isset($params['order']))
						{
							$this->_order($params['order']);
							$this->redirect("/albums");
						}
						else if (is_null($id))
						{
							$this->error('403', 'Required parameter "id" not present.');
							return;
						}
						// Update
						$a->get_by_id($id);
						if (!$a->exists())
						{
							$this->error('404', "Album with ID: $id not found.");
							return;
						}

						$a->old_created_on = $a->created_on;
						$a->old_published_on = $a->published_on;
					}
					else if (isset($_POST['from_directory']))
					{
						// Cache this to prevent tag spillage from IPTC
						$tags_cache = $_POST['tags'];

						if (is_dir($_POST['from_directory']))
						{
							$_POST['tags'] = '';
							$this->load->helper('directory', 1);
							$files = directory_map($_POST['from_directory']);
							$content_ids = array();
							foreach($files as $file)
							{
								$c = new Content;
								$file = $_POST['from_directory'] . DIRECTORY_SEPARATOR . $file;
								$filename = basename($file);
								list($internal_id, $path) = $c->generate_internal_id();
								if (file_exists($file))
								{
									if ($path)
									{
										$path .= $filename;
									}
									else
									{
										$this->error('500', 'Unable to create directory for upload.');
										return;
									}
									copy($file, $path);
									$from = array();
									$from['filename'] = $filename;
									$from['internal_id'] = $internal_id;
									$from['file_modified_on'] = time();
									$c->from_array($from, array(), true);
									$content_ids[] = $c->id;
								}
							}
						}

						$_POST['tags'] = $tags_cache;
 					}

					// Don't allow these fields to be saved generically
					$private = array('parent_id', 'left_id', 'right_id');

					if ($a->exists())
					{
						$private[] = 'album_type';
					}

					if (isset($_REQUEST['reset_internal_id']) &&
						$_REQUEST['reset_internal_id'] &&
						$a->exists())
					{
						array_shift($private);
						$_POST['internal_id'] = koken_rand();
					}
					else
					{
						$private[] = 'internal_id';
					}

					foreach($private as $p) {
						unset($_POST[$p]);
					}

					if ($a->has_db_permission('lock tables'))
					{
						$s = new Slug;
						$t = new Tag;
						$c = new Content;
						$this->db->query("LOCK TABLE {$a->table} WRITE, {$c->table} WRITE, {$s->table} WRITE, {$t->table} WRITE, {$a->db_join_prefix}albums_content READ");
						$locked = true;
					}
					else
					{
						$locked = false;
					}

					if (!$a->from_array($_POST, array(), true))
					{
						// TODO: More info
						$this->error('500', 'Save failed.');
						return;
					}

					if ($locked)
					{
						$this->db->query('UNLOCK TABLES');
					}

					if (isset($_POST['tags']))
					{
						$a->_format_tags($_POST['tags']);
					}
					else if ($this->method === 'put' && isset($_POST['listed']))
					{
						$a->_update_tag_counts();
					}

					$arr = $a->to_array();
					if ($this->method === 'post')
					{
						Shutter::hook('album.create', $arr);
					}
					else
					{
						Shutter::hook('album.update', $arr);
					}

					if (isset($content_ids))
					{
						$a->manage_content(join(',', $content_ids), 'post', true);
					}
					$this->redirect("/albums/{$a->id}");
					break;
				case 'delete':
					if (is_null($id))
					{
						$this->error('403', 'Required parameter "id" not present.');
						return;
					}
					else
					{
						$prefix = preg_replace('/albums$/', '', $a->table);

						if ($id === 'trash')
						{
							$id = array();
							$trash = new Trash();
							$trash->like('id', 'album-')->get_iterated();
							foreach($trash as $item)
							{
								$album = unserialize(utf8_decode($item->data));
								if (!$album)
								{
									$album = unserialize($item->data);
								}
								$id[] = $album['id'];
							}
						}
						else if (is_numeric($id))
						{
							$id = array($id);
						}
						else
						{
							$id = explode(',', $id);
						}

						$tags = array();

						// Need to loop individually here, otherwise tree can break down
						foreach($id as $album_id)
						{
							$al = new Album;
							$al->get_by_id($album_id);

							if ($al->exists())
							{
								$tags = array_merge($tags, $al->tags);
								$this->db->query("DELETE FROM {$prefix}trash WHERE id = 'album-{$al->id}'");

								if ($al->right_id - $al->left_id > 1)
								{
									$children = new Album;
									$subs = $children->where('deleted', $al->deleted)
													->where('listed', $al->listed)
													->where('left_id >', $al->left_id)
													->where('right_id <', $al->right_id)
													->get_iterated();

									foreach($subs as $sub_album) {
										Shutter::hook('album.delete', $sub_album->to_array());
										$sub_album->delete();
									}
								}

								$s = new Slug;
								$this->db->query("DELETE FROM {$s->table} WHERE id = 'album.{$al->slug}'");

								Shutter::hook('album.delete', $al->to_array());

								$al->delete();

							}
						}

						$al->update_set_counts();
					}
					exit;
					break;
			}
		}
		$a = new Album();

		// No id, so we want a list
		if (is_null($id) && !$slug)
		{
			$final = $a->listing($params);
		}
		// Get album by id
		else
		{
			$defaults = array(
							'neighbors' => false,
							'include_empty_neighbors' => false
						);
			$options = array_merge($defaults, $params);

			$with_token = false;

			if (is_numeric($id))
			{
				$album = $a->where('deleted', 0)->get_by_id($id);
			}
			else
			{
				if ($slug)
				{
					$album = $a->where('deleted', 0)
									->group_start()
										->where('internal_id', $slug)
										->or_where('slug', $slug)
									->group_end()
									->get();
				}
				else
				{
					$album = $a->where('deleted', 0)
									->where('internal_id', $id)
									->get();
				}

				if ($album->exists() && $album->internal_id === ( is_null($id) ? $slug : $id))
				{
					$with_token = true;
				}
			}

			if (!$album->exists())
			{
				$this->error('404', 'Album not found.');
				return;
			}

			if ($a->exists())
			{
				if ($a->listed == 0 && !$this->auth && !$with_token)
				{
					$this->error('403', 'Private content.');
					return;
				}

				$final = $album->to_array($params);
				$final['context'] = $album->context($options, $this->auth);
			}
			else
			{
				$this->error('404', "Album with ID: $id not found.");
				return;
			}

			// TODO: This history stuff won't work here anymore
			// if ($this->method == 'put')
			// {
			// 	$h = new History();
			// 	$h->message = array( 'album:update',  $a->title );
			// 	$h->save();
			// }
			// else if ($this->method == 'post')
			// {
			// 	$h = new History();
			// 	$h->message = array( 'album:create',  $a->title );
			// 	$h->save();
			// }
		}
		$this->set_response_data($final);
	}

	function covers()
	{
		list($params, $id) = $this->parse_params(func_get_args());
		$params['auth'] = $this->auth;

		// Standard add/delete cover
		list($id, $content_id) = $id;

		if ($this->method === 'get')
		{
			$this->redirect("/albums/$id");
		}

		$a = new Album($id);
		$c = new Content();

		if (!$a->exists())
		{
			$this->error('404', 'Album not found.');
			return;
		}

		$cover_count = $a->covers->count();

		if ($cover_count > 50)
		{
			$this->error('403', 'Only 50 covers can be added to any one album.');
			return;
		}

		if ($a->album_type == 2 && $cover_count == 0)
		{
			$subs = new Album();
			$subs->select('id')
				->where('right_id <', $a->right_id)
				->where('left_id >', $a->left_id)
				->where('listed', $a->listed)
				->get_iterated();

			$id_arr = array();

			foreach($subs as $sub)
			{
				$id_arr[] = $sub->id;
			}

			if (!empty($id_arr))
			{
				$subc = new Content();
				$covers = $subc->query("SELECT DISTINCT cover_id FROM {$a->db_join_prefix}albums_covers WHERE album_id IN (" . join(',', $id_arr) . ") GROUP BY album_id LIMIT " . (3 - $cover_count));

				$f_ids = array();
				foreach($covers as $f)
				{
					$f_ids[] = $f->cover_id;
				}

				if (!empty($f_ids))
				{
					$subc->query("SELECT id FROM {$subc->table} WHERE id IN(" . join(',', $f_ids) . ") ORDER BY FIELD(id, " . join(',', array_reverse($f_ids)) . ")");

					foreach($subc as $content)
					{
						$a->save_cover($content);
					}
				}
			}
		}

		if (is_numeric($content_id))
		{
			if ($this->method == 'delete')
			{
				$c->where_related('covers', 'id', $id)->get_by_id($content_id);
			}
			else
			{
				if ($a->album_type == 2)
				{
					$c->get_by_id($content_id);
				}
				else
				{
					$c->where_related('album', 'id', $id)->get_by_id($content_id);
				}
			}

			if (!$c->exists())
			{
				$this->error('404', 'Content not found.');
				return;
			}

			if ($this->method == 'delete')
			{
				$a->delete_cover($c);
				$a->reset_covers();
			}
			else
			{
				$a->delete_cover($c);
				$a->save_cover($c);
			}
		}
		else
		{
			$content_id = explode(',', $content_id);

			if ($this->method == 'delete')
			{
				$c->where_related('covers', 'id', $id)->where_in('id', $content_id)->get_iterated();
			}
			else
			{
				if ($a->album_type == 2)
				{
					$c->where_in('id', $content_id)->get_iterated();
				}
				else
				{
					$c->where_related('album', 'id', $id)->where_in('id', $content_id)->get_iterated();
				}
			}

			if (!$c->result_count())
			{
				$this->error('404', 'Content not found.');
				return;
			}

			if ($this->method == 'delete')
			{
				foreach($c as $cover)
				{
					$a->delete_cover($cover);
				}

				$a->reset_covers();
			}
			else
			{
				foreach($c as $cover)
				{
					$a->delete_cover($cover);
				}

				foreach($content_id as $cid)
				{
					$a->save_cover($c->get_by_id($cid));
				}
			}
		}
		$this->redirect("/albums/$id");
	}

	function content()
	{
		list($params, $id, $slug) = $this->parse_params(func_get_args());
		$params['auth'] = $this->auth;

		$a = new Album;
		$c = new Content;

		if (is_null($id) && !$slug)
		{
			$this->error('403', 'Required parameter "id" not present.');
			return;
		}
		else if (is_array($id))
		{
			list($id, $content_id) = $id;
		}

		if ($this->method != 'get')
		{

			$album = $a->get_by_id($id);

			if (!$album->exists())
			{
				$this->error('404', 'Album not found.');
				return;
			}

			$tail = '';

			if (isset($params['order']))
			{
				if ($album->album_type == 2)
				{
					$this->_order($params['order'], $album);
				}
				else
				{
					$ids = explode(',', $params['order']);
					$new_order_map = array();

					foreach($ids as $key => $val)
					{
						$pos = $key + 1;
						$new_order_map[$val] = $pos;
					}

					$album->trans_begin();
					foreach($album->contents->include_join_fields()->get_iterated() as $c)
					{
						if (isset($new_order_map[$c->id]) && $new_order_map[$c->id] != $c->join_order)
						{
							$album->set_join_field($c, 'order', $new_order_map[$c->id]);
						}
					}
					$album->trans_commit();
				}
			}
			else
			{
				if (!isset($content_id))
				{
					$this->error('403', 'Required content id not present.');
					return;
				}
				else if ($album->album_type == 1)
				{
					$this->error('403', 'You cannot manually add content to smart albums.');
					return;
				}
				if ($id == $content_id && $album->album_type == 2)
				{
					$this->error('403', 'Album cannot be added to itself.');
					return;
				}
				$album->manage_content($content_id, $this->method, $this->input->post('match_album_visibility'));
			}

			if ($this->method == 'delete')
			{
				exit;
			}
			else
			{
				$this->redirect("/albums/{$album->id}/content");
				exit;
			}
		}

		$with_token = false;

		if (is_numeric($id))
		{
			$album = $a->where('deleted', 0)->get_by_id($id);
		}
		else
		{
			if ($slug)
			{
				$album = $a->where('deleted', 0)
								->group_start()
									->where('internal_id', $slug)
									->or_where('slug', $slug)
								->group_end()
								->get();
			}
			else
			{
				$album = $a->where('deleted', 0)
								->where('internal_id', $id)
								->get();
			}

			if ($album->exists() && $album->internal_id === ( is_null($id) ? $slug : $id))
			{
				$with_token = true;
			}
		}

		if ($album->exists())
		{
			if ($album->listed == 0 && !$this->auth && !$with_token)
			{
				$this->error('403', 'Private content.');
				return;
			}
		}
		else
		{
			$this->error('404', 'Album not found.');
			return;
		}

		if ($album->album_type == 2)
		{
			$options = array(
				'neighbors' => false,
				'include_empty_neighbors' => false,
				'order_by' => 'manual',
				'with_context' => true
			);
			$params = array_merge($options, $params);
			if ($params['order_by'] === 'manual')
			{
				$params['order_by'] = 'left_id';
				$params['order_direction'] = 'asc';
			}
			$final = $album->listing($params);
		}
		else
		{
			$options = array(
				'order_by' => 'manual',
				'covers' => null,
				'neighbors' => false,
				'include_empty_neighbors' => false,
				'in_album' => $album,
				'with_context' => true,
				'is_cover' => true,
				'visibility' => 'any',
			);
			$params = array_merge($options, $params);

			if ($params['covers'])
			{
				if ($params['order_by'] === 'manual')
				{
					$params['order_by'] = 'cover_id';
				}
				$c = $album->covers;
			}
			else
			{
				$c->where('deleted', 0);
				if (!is_null($params['covers']))
				{
					$cids = array();
					foreach($album->covers->get_iterated() as $c)
					{
						$cids[] = $c->id;
					}
					$c->where_not_in('id', $cids);
				}
				$c->where_related_album('id', $album->id);

				if ($params['order_by'] === 'manual')
				{
					$params['order_by'] = 'order';
					$params['order_direction'] = 'asc';
				}
			}

			$final = $c->listing($params);
		}
		$params['include_parent'] = true;
		unset($params['category']);
		unset($params['tags']);
		$final['album'] = $album->to_array($params);

		if (isset($final['album']['covers']) && !empty($final['album']['covers']) && isset($final['content']))
		{
			$covers = array();
			foreach($final['album']['covers'] as $cover)
			{
				$covers[] = $cover['id'];
			}

			foreach($final['content'] as &$c)
			{
				$c['is_cover'] = in_array($c['id'], $covers);
			}
		}

		if ($params['with_context'])
		{
			$final['album']['context'] = $album->context($params, $this->auth);
		}
		$this->set_response_data($final);
	}
}

/* End of file albums.php */
/* Location: ./system/application/controllers/albums.php */