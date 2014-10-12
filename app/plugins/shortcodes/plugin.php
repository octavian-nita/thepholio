<?php

class BD_Shortcodes extends KokenPlugin {

	function __construct()
	{
		$this->register_shortcode('koken_photo', 'koken_media');
		$this->register_shortcode('koken_video', 'koken_media');
		$this->register_shortcode('koken_oembed', 'koken_oembed');
		$this->register_shortcode('koken_slideshow', 'koken_slideshow');
		$this->register_shortcode('koken_upload', 'koken_upload');
		$this->register_shortcode('koken_code', 'koken_code');
	}

	function koken_oembed($attr)
	{
		if (!isset($attr['url']) || !isset($attr['endpoint'])) { return ''; }

		$endpoint = $attr['endpoint'];

		if (strpos($endpoint, 'maxwidth=') === false)
		{
			if (strpos($endpoint, '?') !== false)
			{
				$endpoint .= '&';
			}
			else
			{
				$endpoint .= '?';
			}

			$endpoint .= 'maxwidth=1920&maxheight=1080';
		}

		if (strpos($endpoint, '?') !== false)
		{
			$endpoint .= '&';
		}
		else
		{
			$endpoint .= '?';
		}

		$info = Shutter::get_oembed($endpoint . 'url=' . $attr['url']);

		if (isset($info['html']))
		{
			$html = preg_replace('/<iframe/', '<iframe style="display:none"', $info['html']);
		}
		else if (isset($info['url'])) {
			$html = '<img src="' . $info['url'] . '" />';
		}
		else
		{
			return '';
		}
		return '<figure class="k-content-embed"><div class="k-content">' . $html . '</div></figure>';
	}

	function koken_media($attr)
	{
		if (!isset($attr['id'])) { return ''; }

		if ($attr['media_type'] === 'image')
		{
			$tag = 'img lazy="true"';
		}
		else
		{
			$tag = 'video';
		}

		$text = '';
		if (!isset($attr['caption']) || $attr['caption'] !== 'none')
		{
			$text .= '<figcaption class="k-content-text">';
			if (!isset($attr['caption']) || $attr['caption'] !== 'caption')
			{
				$text .= '<span class="k-content-title">{{ content.title }}</span>';
			}
			if (!isset($attr['caption']) || $attr['caption'] !== 'title')
			{
				$text .= '<span class="k-content-caption">{{ content.caption }}</span>';
			}
			$text .= '</figcaption>';
		}

		$link_pre = $link_post = $context_param = '';

		if (isset($attr['link']) && $attr['link'] !== 'none')
		{
			if ($attr['link'] === 'detail' || $attr['link'] === 'lightbox')
			{
				$link_pre = '<koken:link' . ( $attr['link'] === 'lightbox' ? ' lightbox="true"': '' ) . '>';
				$link_post = '</koken:link>';
			}
			else if ($attr['link'] === 'album')
			{
				$context_param = " filter:context=\"{$attr['album']}\"";
				$link_pre = '<koken:link data="context.album">';
				$link_post = '</koken:link>';
			}
			else
			{
				$link_pre = '<a href="' . $attr['custom_url'] . '">';
				$link_post = '</a>';
			}
		}

		return <<<HTML
<figure class="k-content-embed">
	<koken:load source="content" filter:id="{$attr['id']}"$context_param>
		<div class="k-content">
			$link_pre
			<koken:$tag />
			$link_post
		</div>
		$text
	</koken:load>
</figure>
HTML;

	}

	function koken_upload($attr)
	{
		$text = '';
		$src = $attr['filename'];
		$link_pre = $link_post = '';

		if (isset($attr['link']) && !empty($attr['link']))
		{
			$link_pre = '<a href="' . $attr['link'] . '"' . ( isset($attr['target']) && $attr['target'] !== 'none' ? ' target="_blank"' : '' ) . '>';
			$link_post = '</a>';
		}

		if (isset($attr['title']) && !empty($attr['title']))
		{
			$text .= '<span class="k-content-title">' . $attr['title'] . '</span>';
		}

		if (isset($attr['caption']) && !empty($attr['caption']))
		{
			$text .= '<span class="k-content-caption">' . $attr['caption'] . '</span>';
		}

		if (!empty($text))
		{
			$text = "<figcaption class=\"k-content-text\">$text</figcaption>";
		}

		if (strpos($src, 'http://') === 0)
		{
			return <<<HTML
<figure class="k-content-embed">
	<div class="k-content">
		$link_pre
		<img src="$src" style="max-width:100%" />
		$link_post
	</div>
	$text
</figure>
HTML;
		}
		else
		{
			return <<<HTML
<figure class="k-content-embed">
	<koken:load source="content" filter:custom="$src">
		<div class="k-content">
			$link_pre
			<koken:img lazy="true" />
			$link_post
		</div>
		$text
	</koken:load>
</figure>
HTML;
		}

	}

	function koken_code($attr)
	{
		if (isset($attr['code']))
		{
			return $attr['code'];
		}
		else
		{
			return '';
		}
	}

	function koken_slideshow($attr)
	{
		$rand = 'p' . md5(uniqid(function_exists('mt_rand') ? mt_rand() : rand(), true));

		if (!isset($attr['link_to']))
		{
			$attr['link_to'] = 'default';
		}

		$attr['link_to'] = 'link_to="' . $attr['link_to'] . '"';

		if (isset($attr['content']))
		{
			$path = '/content/' . $attr['content'];
		}
		else if (isset($attr['album']))
		{
			$path = '/albums/' . $attr['album'] . '/content';
		}

		$text = '';
		if (isset($attr['caption']) && $attr['caption'] !== 'none')
		{
			$text .= '<figcaption id="' . $rand .'_text" class="k-content-text">';
			if ($attr['caption'] !== 'caption')
			{
				$text .= '<span class="k-content-title">&nbsp;</span>';
			}
			if ($attr['caption'] !== 'title')
			{
				$text .= '<span class="k-content-caption">&nbsp;</span>';
			}
			$text .= '</figcaption>';
			$text .= <<<JS
	<script>
		$rand.on( 'transitionstart', function(e) {
			var title = $('#{$rand}_text').find('.k-content-title'),
				caption = $('#{$rand}_text').find('.k-content-caption');

			if (title) {
				title.text(e.data.title || e.data.filename);
			}

			if (caption) {
				caption.html(e.data.caption);
			}
		});
	</script>
JS;
		}

		return <<<HTML
<figure class="k-content-embed">
	<div class="k-content">
		<koken:pulse jsvar="$rand" data_from_url="$path" size="auto" {$attr['link_to']} group="essays" />
	</div>
	$text
</figure>
HTML;

	}
}