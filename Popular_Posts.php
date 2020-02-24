<?php

namespace vnh\shortcodes;

use vnh\contracts\Shortcodeable;
use WP_Query;

class Popular_Posts implements Shortcodeable {
	public $default_atts;

	public function __construct() {
		$this->default_atts = apply_filters('vnh/popular_posts/default_atts', [
			'number_of_posts' => 5,
			'thumbnail' => 'no',
			'thumbnail_width' => 100,
			'thumbnail_height' => 100,
			'before' => '<ul class="popular-posts">',
			'after' => '</ul>',
			'content' =>
				'<li class="popular-posts__item">%img_url<div class="popular-posts__wrap"><a href="%link_url">%title</a><span>( %count )</span></div></li>',
		]);
	}

	public function add_shortcode() {
		add_shortcode('popular_posts', [$this, 'callback']);
	}

	public function callback($atts) {
		$atts = shortcode_atts($this->default_atts, $atts);

		$query = new WP_Query([
			'posts_per_page' => $atts['number_of_posts'],
			'orderby' => 'comment_count',
		]);

		if ($query->have_posts()):
			$html = $atts['before'];

			while ($query->have_posts()):
				$query->the_post();

				$count = get_comments_number_text('0');

				$thumbnail = '';
				if ($atts['thumbnail'] === '1') {
					$thumbnail = get_the_post_thumbnail(get_the_ID(), [$atts['thumbnail_width'], $atts['thumbnail_height']]);
				}

				$content = str_replace(['%link_url', '%title', '%count', '%img_url'], ['%1$s', '%2$s', '%3$s', '%4$s'], $atts['content']);
				$html .= sprintf($content, get_the_permalink(), get_the_title(), $count, $thumbnail);
			endwhile;

			wp_reset_postdata();

			$html .= $atts['after'];

			echo wp_kses($html, 'default');
		else:
			esc_html_e('Nothing Found', 'vnh_textdomain');
		endif;
	}
}
