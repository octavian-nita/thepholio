<?php

class Koken extends DataMapper {

	function _do_tag_filtering($options)
	{
		if ($options['tags_not'])
		{
			$not = true;
			$options['tags'] = $options['tags_not'];
		}
		else
		{
			$not = false;
		}

		$tags = explode(',', urldecode($options['tags']));

		if ($options['match_all_tags'] || $not)
		{
			$content_ids = false;
			$model = $this->model === 'album' ? 'albums' : $this->model;

			foreach($tags as $tag)
			{
				$t = new Tag;
				$t->where('name', $tag)->get();

				if ($t->exists())
				{
					$tag_content_ids = array();
					foreach($t->{$model}->select('id')->get_iterated() as $content)
					{
						$tag_content_ids[] = $content->id;
					}

					if ($content_ids === false)
					{
						$content_ids = $tag_content_ids;
					}
					else
					{
						if ($options['match_all_tags'])
						{
							$content_ids = array_intersect($content_ids, $tag_content_ids);
						}
						else
						{
							$content_ids = array_merge($content_ids, $tag_content_ids);
						}
					}
				}
			}

			if ($not)
			{
				$this->where_not_in('id', $content_ids);
			}
			else
			{
				$this->where_in('id', $content_ids);
			}
		}
		else
		{
			$this->distinct();
			$this->group_start();

			foreach($tags as $tag)
			{
				$t = new Tag;
				$t->where('name', $tag)->get();

				if ($t->exists())
				{
					$this->or_where_related('tag', 'id', $t->id);
				}
			}
			$this->group_end();
		}
	}

	function _update_tag_counts()
	{
		foreach($this->tags->get_iterated() as $tag)
		{
			$tag->update_counts($this->model);
		}

		foreach($this->categories->get_iterated() as $category)
		{
			$category->update_counts($this->model);
		}
	}

	function update_counts($which = 'all')
	{
		if ($which === 'all' || $which === 'album')
		{
			$this->album_count = $this->album->where('listed', 1)->where('total_count >', 0)->where('deleted', 0)->count();
		}

		if ($which === 'all' || $which === 'text')
		{
			$this->text_count = $this->text->where('published', 1)->count();
		}

		if ($which === 'all' || $which === 'content')
		{
			$this->content_count = $this->content->where('visibility', 0)->where('deleted', 0)->count();
		}

		$this->save();
	}

	function _format_tags($tags)
	{
		$t = new Tag;

		$model = $this->model;

		$existing = array();
		foreach($this->tags->select('id,name')->get_iterated() as $tag)
		{
			$existing[] = $tag->name;
		}

		if (empty($tags))
		{
			$remove = $existing;
		}
		else
		{
			$tags = koken_format_tags($tags);

			$add = array_diff($tags, $existing);
			$remove = array_diff($existing, $tags);

			foreach($add as $tag)
			{
				$t->get_by_name($tag);

				if (!$t->exists())
				{
					$t->name = $tag;
				}

				$t->last_used = time();
				$t->save($this);
				$t->update_counts($this->model);
			}
		}

		foreach($remove as $tag)
		{
			$t->get_by_name($tag);

			if ($t->exists())
			{
				$this->delete($t);
				$t->update_counts($this->model);
			}
		}
	}

	function _eager_load_tags($data)
	{
		$ids = array();

		foreach($data as $content) {
			$ids[] = $content->id;
		}

		require(FCPATH . 'storage' .
									DIRECTORY_SEPARATOR . 'configuration' .
									DIRECTORY_SEPARATOR . 'database.php');

		switch ($this->model) {
			case 'text':
				$join_table = $KOKEN_DATABASE['prefix'] . 'join_tags_text';
				break;

			case 'content':
				$join_table = $KOKEN_DATABASE['prefix'] . 'join_content_tags';
				break;

			case 'album':
				$join_table = $KOKEN_DATABASE['prefix'] . 'join_albums_tags';
				break;
		}

		$join_field = $this->model . '_id';

		$tag = new Tag;
		$tag->select($KOKEN_DATABASE['prefix'] . 'tags.*, ' . $join_table . '.' . $this->model . '_id as ' . $join_field)
			->where_related($this->model, 'id', $ids)
			->order_by('name ASC')
			->get_iterated();

		$tag_map = array();
		foreach($tag as $t)
		{
			$key = 'c' . $t->{$join_field};
			if (isset($tag_map[$key]))
			{
				$tag_map[$key][] = $t->_tag_for_output($this->model);
			}
			else
			{
				$tag_map[$key] = array($t->_tag_for_output($this->model));
			}
		}

		return $tag_map;
	}

	function _tag_for_output($model)
	{
		$t = $this->to_array();
		list($t['__koken_url'], $t['url']) = $this->url(array('limit_to' => $model === 'text' ? 'essays' : $model));
		return $t;
	}

	function _get_tags_for_output($options = array())
	{
		if (isset($options['eager_tags']))
		{
			return $options['eager_tags'];
		}
		else
		{
			'q';
			require(FCPATH . 'storage' .
							DIRECTORY_SEPARATOR . 'configuration' .
							DIRECTORY_SEPARATOR . 'database.php');

			$tags = $this->tags->order_by($KOKEN_DATABASE['prefix'] . 'tags.name ASC')->get_iterated();
			$arr = array();

			foreach($tags as $tag)
			{
				$arr[] = $tag->_tag_for_output($this->model);
			}

			return $arr;
		}
	}

	function _validate_time($field)
	{
		$val = $this->{$field};
		$previous = 'old_' . $field;

		if (is_numeric($val))
		{
			return strlen($val) <= 10;
		}
		else if ($val === 'captured_on' && $field === 'published_on')
		{
			$s = new Setting;
			$s->where('name', 'site_timezone')->get();
			$tz = new DateTimeZone($s->value);
			$offset = $tz->getOffset( new DateTime(date('c', $this->captured_on), new DateTimeZone('UTC')) );
			$this->published_on = $this->captured_on - $offset;
			return true;
		}
		else if (isset($this->{$previous}))
		{
			$test = trim(preg_replace('/\-?\s*(year|month|day|hour|second)s?/', '', $test));
			if (strlen($test) === 0)
			{
				$diff = time() - strtotime($val);
				$this->{$field} = $this->{$previous} - $diff;
				return true;
			}
		}
		return false;
	}

}