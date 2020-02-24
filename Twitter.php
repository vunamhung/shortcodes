<?php

namespace vnh\shortcodes;

use vnh\contracts\Shortcodeable;

use function vnh\request;

class Twitter implements Shortcodeable {
	public $default_atts;
	public $base_url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
	public $access_token = 'AAAAAAAAAAAAAAAAAAAAAJBzagAAAAAAXr%2Fxj2UWtV%2BnQNigsUm%2Bjrlkr4o%3DoYt2AFQFvPpPsJ1wtVmJ3MLetbYnmTWLFzDZJWLnXZtRJRZKOQ';

	public function __construct() {
		$this->default_atts = apply_filters('vnh/twitter/default_atts', [
			'transient_name' => 'vnh_prefix_twitter',
			'username' => 'unsplash',
			'count' => 2,
			'trim_user' => true,
			'icon' => 'twitter',
			'before' => '<div class="latest-tweets">',
			'after' => '</div>',
		]);
	}

	public function add_shortcode() {
		add_shortcode('latest_tweet', [$this, 'callback']);
	}

	public function callback($atts) {
		$atts = shortcode_atts($this->default_atts, $atts);

		if (is_wp_error($this->get_tweets($atts))) {
			return;
		}

		$html = $atts['before'];
		foreach ($this->get_tweets($atts) as $tweet) {
			$html .= '<div class="latest-tweets-item">';
			$html .= '<span>' . get_svg_icon('social--' . $atts['icon']) . '</span>';
			$html .= "<span>{$tweet}</span>";
			$html .= '</div>';
		}
		$html .= $atts['after'];

		echo wp_kses($html, 'default');
	}

	public function get_tweets($atts) {
		$cached_tweets = get_transient($atts['transient_name']);

		if (!empty($cached_tweets)) {
			return $cached_tweets;
		}

		$results = request(
			add_query_arg(['screen_name' => $atts['username'], 'count' => $atts['count'], 'trim_user' => $atts['trim_user']], $this->base_url),
			[
				'httpversion' => '1.1',
				'blocking' => true,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->access_token,
					'Accept-Language' => 'en',
				],
			]
		);

		if (is_wp_error($results)) {
			return $results;
		}

		$tweets = [];
		foreach ($results as $result) {
			if (!empty($result['text'])) {
				$tweets[] = $this->convert_links($result['text']);
			}
		}

		set_transient($atts['transient_name'], $tweets, HOUR_IN_SECONDS);

		return $tweets;
	}

	protected function convert_links($status, $target_blank = true) {
		// the target
		$target = $target_blank ? ' target="_blank" ' : '';

		// convert link to url
		$status = preg_replace(
			'/\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[A-Z0-9+&@#\/%=~_|]/i',
			'<a href="\0" rel="nofollow" target="_blank">\0</a>',
			$status
		);

		// convert @ to follow
		$status = preg_replace(
			'/(@([_a-z0-9\\-]+))/i',
			"<a href='http://twitter.com/$2' title='Follow $2' rel='nofollow' $target >$1</a>",
			$status
		);

		// convert # to search
		$status = preg_replace(
			'/(#([_a-z0-9\\-]+))/i',
			"<a href='https://twitter.com/search?q=$2' title='Search $1' rel='nofollow' $target >$1</a>",
			$status
		);

		return $status;
	}
}
