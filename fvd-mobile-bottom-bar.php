<?php
/**
 * Plugin Name: FVD Mobile Bottom Bar
 * Plugin URI: https://github.com/fervilela-dev/FVD-Mobile-Bottom-Bar
 * Description: Barra inferior móvil configurable + actualizaciones automáticas desde GitHub Releases (release asset ZIP).
 * Version: 1.0.0
 * Author: FerVilela Digital Consulting
 * Author URI: https://fervilela.com
 * Text Domain: fvd-mobile-bottom-bar
 * GitHub Plugin URI: https://github.com/fervilela-dev/FVD-Mobile-Bottom-Bar
 * Primary Branch: main
 */

defined('ABSPATH') || exit;

if (!class_exists('FVD_Mobile_Bottom_Bar')) {

final class FVD_Mobile_Bottom_Bar {

  const UPDATE_TRANSIENT = 'fvd_mbb_update_payload';
  const GITHUB_REPO = 'fervilela-dev/FVD-Mobile-Bottom-Bar';
  const SLUG = 'fvd-mobile-bottom-bar';
  const RELEASE_ASSET_ZIP = 'fvd-mobile-bottom-bar.zip';

  public function __construct() {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
    add_action('wp_footer', [$this, 'render_bar']);
    $this->setup_updater();
  }

  public function enqueue_front() {
    wp_enqueue_style(
      'fvd-mbb',
      plugins_url('assets/bar.css', __FILE__),
      [],
      $this->get_version()
    );
    wp_enqueue_style('dashicons');
  }

  public function render_bar() {
    if (is_admin()) return;

    echo '<nav class="fvd-mbb"><div class="fvd-mbb__inner">';
    echo '<a href="' . esc_url(home_url('/')) . '" class="fvd-mbb__item"><span class="dashicons dashicons-admin-home"></span></a>';
    echo '<a href="#" class="fvd-mbb__item"><span class="dashicons dashicons-search"></span></a>';
    echo '<a href="#" class="fvd-mbb__item"><span class="dashicons dashicons-cart"></span></a>';
    echo '<a href="#" class="fvd-mbb__item"><span class="dashicons dashicons-admin-users"></span></a>';
    echo '</div></nav>';
  }

  private function setup_updater() {
    add_filter('pre_set_site_transient_update_plugins', [$this, 'maybe_set_update']);
  }

  public function maybe_set_update($transient) {
    if (!is_object($transient)) return $transient;

    $payload = $this->fetch_latest_release_asset();
    if (!$payload) return $transient;

    $current_version = $this->get_version();
    if (version_compare($payload['tag_name'], $current_version, '<=')) return $transient;

    $item = (object) [
      'slug' => self::SLUG,
      'plugin' => plugin_basename(__FILE__),
      'new_version' => $payload['tag_name'],
      'package' => $payload['zip_url'],
      'url' => $payload['html_url'],
    ];

    $transient->response[plugin_basename(__FILE__)] = $item;
    return $transient;
  }

  private function fetch_latest_release_asset() {
    $cached = get_site_transient(self::UPDATE_TRANSIENT);
    if (is_array($cached)) return $cached;

    $request = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest', [
      'timeout' => 10,
      'headers' => [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => self::SLUG,
      ],
    ]);

    if (is_wp_error($request)) return false;
    if (wp_remote_retrieve_response_code($request) !== 200) return false;

    $body = json_decode(wp_remote_retrieve_body($request), true);
    if (!is_array($body) || empty($body['tag_name'])) return false;

    $zip = '';
    if (!empty($body['assets']) && is_array($body['assets'])) {
      foreach ($body['assets'] as $asset) {
        if (!empty($asset['name']) && !empty($asset['browser_download_url'])) {
          if ($asset['name'] === self::RELEASE_ASSET_ZIP) {
            $zip = $asset['browser_download_url'];
            break;
          }
        }
      }
    }

    if (!$zip) return false;

    $payload = [
      'tag_name' => ltrim((string)$body['tag_name'], 'v'),
      'zip_url' => $zip,
      'html_url' => $body['html_url'] ?? '',
      'published_at' => $body['published_at'] ?? '',
    ];

    set_site_transient(self::UPDATE_TRANSIENT, $payload, HOUR_IN_SECONDS);
    return $payload;
  }

  private function get_version(): string {
    if (!function_exists('get_plugin_data')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data(__FILE__, false, false);
    return $data['Version'] ?? '0.0.0';
  }
}

new FVD_Mobile_Bottom_Bar();
}
