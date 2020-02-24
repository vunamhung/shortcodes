<?php

namespace vnh\shortcodes;

use vnh\contracts\Enqueueable;
use vnh\contracts\Shortcodeable;
use WP_Error;

class Weather implements Shortcodeable, Enqueueable {
	public $default_args;
	public $api_keys = ['f8567f1c1498c35bc5f9839b9cad4a2d'];
	public $darksky_base_url = 'https://api.darksky.net/forecast';
	public $nominatim_base_url = 'https://nominatim.openstreetmap.org/search';

	public function __construct() {
		$this->default_args = apply_filters('core/shortcode/weather/default_args', [
			'transient_weather' => 'vnh_prefix_weather',
			'transient_coordinates' => 'vnh_prefix_weather_coordinates',
			'address' => null,
			'latitude' => null,
			'longitude' => null,
			'units' => 'auto',
			'lang' => 'en',
			'freq' => 30,
			'before' => '<div class="weather">',
			'after' => '</div>',
		]);
	}

	public function boot() {
		add_action('wp_enqueue_scripts', [$this, 'enqueue']);
	}

	public function enqueue() {
		wp_enqueue_script('skycons', get_theme_file_uri('vendor/vunamhung/shortcodes/js/skycons.js'), false, '1.0.0', true);
		wp_add_inline_script(
			'skycons',
			'var icons = new Skycons(), list = [ "clear-day", "clear-night", "partly-cloudy-day", "partly-cloudy-night", "cloudy", "rain", "sleet", "snow", "wind", "fog" ], i; for(i = list.length; i--; ) icons.set(list[i], list[i]); icons.play();'
		);
	}

	public function add_shortcode() {
		add_shortcode('weather', [$this, 'callback']);
	}

	public function callback($atts) {
		$atts = shortcode_atts($this->default_args, $atts);

		$html = $atts['before'];
		$html .= $this->get_today_weather($atts);
		$html .= $atts['after'];

		echo wp_kses($html, 'default');
	}

	protected function get_today_weather($atts) {
		$forecast = $this->get_weather($atts);

		if (is_wp_error($forecast) || empty($forecast['daily']['data'])) {
			esc_html_e('There is a error with data', 'vnh_textdomain');
			return null;
		}

		$weather_output = '';
		foreach ($forecast['daily']['data'] as $day) {
			$today = date('n/j/Y', current_time('timestamp'));

			if (isset($day['time'], $day['temperatureMin'], $day['temperatureMax']) && $today === date('n/j/Y', $day['time'])) {
				$weather_output .= sprintf(
					'%s<span class="weather__temp">%s&deg; - %s&deg;</span>',
					!empty($day['icon']) ? "<canvas id='{$day['icon']}' width='32' height='32'></canvas>" : '',
					number_format($day['temperatureMin']),
					number_format($day['temperatureMax'])
				);
				break;
			}
		}

		return $weather_output;
	}

	protected function get_weather($atts) {
		$cached_weather = get_transient($atts['transient_weather']);
		$cached_address = get_transient($atts['transient_coordinates'])[0];

		// If weather info is cached and address is not change, use the cached.
		if (!empty($cached_weather) && isset($atts['address']) && $cached_address === $atts['address']) {
			return $cached_weather;
		}

		if (isset($atts['address']) && !is_wp_error($this->get_coordinates($atts))) {
			list($lat, $lng) = $this->get_coordinates($atts);
		} elseif (!empty($atts['latitude'] && !empty($atts['longitude']))) {
			$lat = $atts['latitude'];
			$lng = $atts['longitude'];
		} else {
			return new WP_Error('address_empty', esc_html__('DarkSky needs an address in order to show the weather', 'vnh_textdomain'));
		}

		$weather = $this->advanced_request([
			'lang' => $atts['lang'],
			'units' => $atts['units'],
			'lat' => $lat,
			'lng' => $lng,
			'exclude' => 'flags,currently,hourly',
		]);

		if (is_wp_error($weather)) {
			return $weather;
		}

		set_transient($atts['transient_weather'], $weather, MINUTE_IN_SECONDS * (int) $atts['freq']);

		return $weather;
	}

	public function get_coordinates($atts) {
		list($cached_address, $cached_coordinates) = get_transient($atts['transient_coordinates']);

		if ($cached_address === $atts['address']) {
			return $cached_coordinates;
		}

		$address = str_replace(' ', '+', $atts['address']);
		$results = request(add_query_arg(['q' => $address, 'format' => 'json'], $this->nominatim_base_url));

		if (is_wp_error($results)) {
			return $results;
		}

		$coordinates = [$results[0]['lat'], $results[0]['lon']];
		$info = [$atts['address'], $coordinates];
		set_transient($atts['transient_coordinates'], $info);

		return $coordinates;
	}

	public function advanced_request($args) {
		/*
		 * Choose a api key
		 */
		$current_api_key = false;

		foreach ($this->api_keys as $api_key) {
			if (get_transient(sprintf('vnh_prefix_stat_%s', $api_key)) !== 'disabled') {
				$current_api_key = $api_key;
				break;
			}
		}

		if (!$current_api_key) {
			return false;
		}

		$darksky_url = add_query_arg(
			['lang' => $args['lang'], 'units' => $args['units'], 'exclude' => $args['exclude']],
			sprintf('%s/%s/%s,%s', $this->darksky_base_url, $current_api_key, $args['lat'], $args['lng'])
		);

		$remote = wp_remote_get($darksky_url);

		if (!$remote) {
			return new WP_Error('failed_to_connect', __('Failed to connect to the server', 'vnh_textdomain'));
		}

		if (is_wp_error($remote)) {
			return $remote;
		}

		if (wp_remote_retrieve_response_code($remote) !== 200) {
			return new WP_Error('invalid_status', __('Invalid Status code.', 'vnh_textdomain'), compact('remote'));
		}

		/*
		 * Temporary disable api key if needed
		 */
		$api_calls = (int) wp_remote_retrieve_header($remote, 'x-forecast-api-calls');

		if ($api_calls === 999) {
			$reset = strtotime('tomorrow midnight') - time();

			if ($reset > 0) {
				set_transient(sprintf('vnh_prefix_stat_%s', $current_api_key), 'disabled', $reset);
			}
		}

		$response = wp_remote_retrieve_body($remote);

		$response = json_decode($response, true);

		return $response;
	}
}
