<?php

namespace vnh\shortcodes;

use vnh\contracts\Shortcodeable;
use WP_Error;

use function vnh\request;

class Instagram implements Shortcodeable {
	public $base_url = 'https://www.instagram.com/%s/?__a=1';
	public $default_atts;

	public function __construct() {
		$this->default_atts = apply_filters('vnh/instagram/default_atts', [
			'transient_name' => 'vnh_prefix_instagram',
			'size' => 'large',
			'username' => 'unsplash',
			'number' => 6,
			'before' => '<ul class="instagram-photos" data-size="%s">',
			'after' => '</ul>',
			'content' =>
				'<li class="instagram-photos__item"><a href="%link_url" rel="nofollow" target="_blank" ><img src="%img_url" alt="%img_alt" /></a></li>',
		]);
	}

	public function add_shortcode() {
		add_shortcode('instagram', [$this, 'callback']);
	}

	public function callback($atts) {
		$atts = shortcode_atts($this->default_atts, $atts);

		$html = sprintf($atts['before'], esc_attr($atts['size']));

		if (!is_array($this->get_photos($atts))) {
			esc_html_e('Nothing Found', 'vnh_textdomain');
			return;
		}

		foreach ($this->get_photos($atts) as $photo) {
			$content = str_replace(['%link_url', '%img_url', '%img_alt'], ['%1$s', '%2$s', '%3$s'], $atts['content']);
			$html .= sprintf($content, $photo['link'], $photo[$atts['size']], $photo['description']);
		}

		$html .= $atts['after'];

		echo wp_kses($html, 'default');
	}

	protected function get_photos($atts) {
		$cached_instagram = get_transient($atts['transient_name']);

		if (!empty($cached_instagram)) {
			return $cached_instagram;
		}

		$username = strtolower(trim($atts['username']));

		$results = request(sprintf($this->base_url, $username), [
			'user-agent' =>
				'Mozilla/5.0 (Mobile; Windows Phone 8.1; Android 4.0; ARM; Trident/7.0; Touch; rv:11.0; IEMobile/11.0; NOKIA; 909) like iPhone OS 7_0_3 Mac OS X AppleWebKit/537 (KHTML, like Gecko) Mobile Safari/537',
		]);

		if (is_wp_error($results) && $results->error_data['too_many_requests'] === true) {
			set_transient($atts['transient_name'], 'too_many_requests', HOUR_IN_SECONDS);
		}

		if (!is_wp_error($results) && isset($results['graphql']['user']['edge_owner_to_timeline_media']['edges'])) {
			$images = $results['graphql']['user']['edge_owner_to_timeline_media']['edges'];
		} else {
			return new WP_Error('bad_json', esc_html__('Instagram has returned invalid data.', 'vnh_textdomain'));
		}

		$instagram = [];

		foreach ($images as $image) {
			if ($image['node']['is_video'] === true) {
				$type = 'video';
			} else {
				$type = 'image';
			}

			$caption = esc_html__('Instagram Image', 'vnh_textdomain');
			if (!empty($image['node']['edge_media_to_caption']['edges'][0]['node']['text'])) {
				$caption = wp_kses($image['node']['edge_media_to_caption']['edges'][0]['node']['text'], []);
			}

			$instagram[] = [
				'description' => $caption,
				'link' => trailingslashit('//instagram.com/p/' . $image['node']['shortcode']),
				'time' => $image['node']['taken_at_timestamp'],
				'comments' => $image['node']['edge_media_to_comment']['count'],
				'likes' => $image['node']['edge_liked_by']['count'],
				'thumbnail' => $image['node']['thumbnail_resources'][0]['src'],
				'small' => $image['node']['thumbnail_resources'][2]['src'],
				'large' => $image['node']['thumbnail_resources'][4]['src'],
				'original' => $image['node']['display_url'],
				'type' => $type,
			];
		}

		if (empty($instagram)) {
			return new WP_Error('no_images', esc_html__('Instagram did not return any images.', 'vnh_textdomain'));
		}

		$instagram = array_slice($instagram, 0, $atts['number']);

		set_transient($atts['transient_name'], $instagram, apply_filters('core/shortcode/instagram/cache_expiration', HOUR_IN_SECONDS));

		return $instagram;
	}
}
