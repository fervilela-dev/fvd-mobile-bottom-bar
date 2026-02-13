<?php
/**
 * Plugin Name: FVD Mobile Bottom Bar
 * Plugin URI: https://github.com/fervilela-dev/FVD-Mobile-Bottom-Bar
 * Description: Barra inferior móvil configurable + actualizaciones automáticas desde GitHub Releases (release asset ZIP).
 * Version: 1.2.0
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
  const OPTION_KEY = 'fvd_mbb_settings';
  const MAX_BUTTONS = 6;

  public function __construct() {
    add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
    add_action('wp_footer', [$this, 'render_bar']);
    add_action('admin_menu', [$this, 'register_admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    $this->setup_updater();
  }

  public function enqueue_front() {
    wp_enqueue_style(
      'fvd-mbb',
      plugins_url('assets/bar.css', __FILE__),
      [],
      $this->get_version()
    );
    wp_enqueue_script(
      'fvd-mbb',
      plugins_url('assets/bar.js', __FILE__),
      [],
      $this->get_version(),
      true
    );
    wp_enqueue_style('dashicons');

    $settings = $this->get_settings();
    $inline = $this->build_inline_style($settings);
    if ($inline) {
      wp_add_inline_style('fvd-mbb', $inline);
    }
  }

  public function render_bar() {
    if (is_admin()) return;

    $settings = $this->get_settings();
    $buttons = $settings['buttons'];
    if (empty($buttons)) return;

    echo '<nav class="fvd-mbb"><div class="fvd-mbb__inner">';

    foreach ($buttons as $index => $button) {
      $url = esc_url($button['url'] ?: '#');
      $fallback_url = esc_url($button['url'] ?: '#');
      $color = esc_attr($button['color']);
      $size = intval($button['size']);
      $icon = esc_attr($button['icon'] ?: 'dashicons-admin-links');
      $label = trim($button['label'] ?? '') ?: 'Botón ' . ($index + 1);
      $action_type = $button['action_type'] ?? 'url';
      $provider = $button['integration_provider'] ?? 'none';
      $selector = trim((string)($button['target_selector'] ?? ''));
      if (!$selector) {
        $selector = $this->get_default_selector($action_type, $provider);
      }

      if ($action_type === 'url') {
        printf(
          '<a href="%1$s" class="fvd-mbb__item" aria-label="%4$s" style="color:%2$s;font-size:%3$dpx;"><span class="dashicons %5$s"></span></a>',
          $url,
          $color,
          $size,
          esc_attr($label),
          $icon
        );
      } else {
        printf(
          '<button type="button" class="fvd-mbb__item fvd-mbb__item--trigger" aria-label="%1$s" style="color:%2$s;font-size:%3$dpx;" data-fvd-action="%4$s" data-fvd-provider="%5$s" data-fvd-selector="%6$s" data-fvd-fallback="%7$s"><span class="dashicons %8$s"></span></button>',
          esc_attr($label),
          $color,
          $size,
          esc_attr($action_type),
          esc_attr($provider),
          esc_attr($selector),
          $fallback_url,
          $icon
        );
      }
    }

    echo '</div></nav>';
  }

  public function register_admin_menu() {
    $capability = 'manage_options';

    add_menu_page(
      'FerVilela',
      'FerVilela',
      $capability,
      'fervilela',
      [$this, 'render_settings_page'],
      'dashicons-admin-generic',
      58
    );

    add_submenu_page(
      'fervilela',
      'Barra Inferior',
      'Barra inferior',
      $capability,
      'fvd-mobile-bottom-bar',
      [$this, 'render_settings_page']
    );
  }

  public function register_settings() {
    register_setting(
      'fvd_mbb_settings_group',
      self::OPTION_KEY,
      [
        'type' => 'array',
        'sanitize_callback' => [$this, 'sanitize_settings'],
      ]
    );
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

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $settings = $this->get_settings();
    ?>
    <div class="wrap">
      <h1>FerVilela · Barra inferior</h1>
      <p>Configura la barra inferior móvil/desktop. Ajusta cantidad de botones, íconos, colores, tamaños, vistas y color de fondo.</p>
      <form method="post" action="options.php">
        <?php
          settings_fields('fvd_mbb_settings_group');
          $option_name = self::OPTION_KEY;
        ?>
        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row">Cantidad de botones</th>
              <td>
                <input type="number" min="1" max="<?php echo esc_attr(self::MAX_BUTTONS); ?>" name="<?php echo esc_attr($option_name); ?>[buttons_count]" value="<?php echo esc_attr($settings['buttons_count']); ?>" />
                <p class="description">Máximo <?php echo esc_html(self::MAX_BUTTONS); ?> botones.</p>
              </td>
            </tr>
            <tr>
              <th scope="row">Color de fondo de la barra</th>
              <td>
                <input type="color" name="<?php echo esc_attr($option_name); ?>[background_color]" value="<?php echo esc_attr($settings['background_color']); ?>" />
              </td>
            </tr>
            <tr>
              <th scope="row">Visibilidad</th>
              <td>
                <fieldset>
                  <?php
                    $vis_options = [
                      'mobile' => 'Solo móviles (0-767px)',
                      'tablet' => 'Solo tablet (768-1024px)',
                      'desktop' => 'Solo desktop (≥1025px)',
                      'custom' => 'Rango personalizado',
                    ];
                  ?>
                  <?php foreach ($vis_options as $value => $label): ?>
                    <label>
                      <input type="radio" name="<?php echo esc_attr($option_name); ?>[visibility]" value="<?php echo esc_attr($value); ?>" <?php checked($settings['visibility'], $value); ?> />
                      <?php echo esc_html($label); ?>
                    </label><br />
                  <?php endforeach; ?>
                  <div style="margin-top:8px;padding:8px;border:1px solid #ccd0d4;max-width:420px;">
                    <strong>Rango personalizado (px)</strong><br />
                    <label>Mínimo <input type="number" min="0" name="<?php echo esc_attr($option_name); ?>[custom_min_width]" value="<?php echo esc_attr($settings['custom_min_width']); ?>" /></label>
                    &nbsp;
                    <label>Máximo <input type="number" min="0" name="<?php echo esc_attr($option_name); ?>[custom_max_width]" value="<?php echo esc_attr($settings['custom_max_width']); ?>" /></label>
                    <p class="description">Se mostrará solo dentro del rango definido cuando selecciones "Rango personalizado". Deja máximo vacío para sin límite superior.</p>
                  </div>
                </fieldset>
              </td>
            </tr>
            <tr>
              <th scope="row">Botones</th>
              <td>
                <?php
                  $action_types = $this->get_action_types();
                  $providers = $this->get_integration_providers();
                ?>
                <?php for ($i = 0; $i < $settings['buttons_count']; $i++): $button = $settings['buttons'][$i]; ?>
                  <fieldset style="margin-bottom:14px;padding:12px;border:1px solid #ccd0d4;">
                    <legend style="font-weight:bold;">Botón <?php echo intval($i + 1); ?></legend>
                    <p>
                      <label>Etiqueta accesible (aria-label) opcional<br />
                        <input type="text" style="width:100%;" name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][label]" value="<?php echo esc_attr($button['label']); ?>" placeholder="Buscar" />
                      </label>
                      <span class="description">Si se deja vacío, se usa "Botón N".</span>
                    </p>
                    <p>
                      <label>Tipo de acción<br />
                        <select name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][action_type]">
                          <?php foreach ($action_types as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($button['action_type'], $value); ?>><?php echo esc_html($label); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </p>
                    <p>
                      <label>URL (enlace o fallback)<br />
                        <input type="url" style="width:100%;" name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][url]" value="<?php echo esc_attr($button['url']); ?>" placeholder="https://ejemplo.com" />
                      </label>
                    </p>
                    <p>
                      <label>Proveedor de integración<br />
                        <select name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][integration_provider]">
                          <?php foreach ($providers as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($button['integration_provider'], $value); ?>><?php echo esc_html($label); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </p>
                    <p>
                      <label>Selector CSS objetivo (opcional)<br />
                        <input type="text" style="width:100%;" name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][target_selector]" value="<?php echo esc_attr($button['target_selector']); ?>" placeholder=".elementor-search-form__toggle" />
                      </label>
                      <span class="description">Si lo dejas vacío, se usa un selector sugerido para Elementor o Royal Addons según el tipo/proveedor.</span>
                    </p>
                    <p>
                      <label>Color del ícono<br />
                        <input type="color" name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][color]" value="<?php echo esc_attr($button['color']); ?>" />
                      </label>
                      &nbsp;
                      <label>Tamaño del ícono (px)<br />
                        <input type="number" min="12" max="48" name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][size]" value="<?php echo esc_attr($button['size']); ?>" />
                      </label>
                    </p>
                    <p>
                      <label>Clase Dashicon<br />
                        <input type="text" style="width:260px;" name="<?php echo esc_attr($option_name); ?>[buttons][<?php echo intval($i); ?>][icon]" value="<?php echo esc_attr($button['icon']); ?>" placeholder="dashicons-admin-home" />
                      </label>
                      <span class="description">Usa clases de <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noreferrer">Dashicons</a>.</span>
                    </p>
                  </fieldset>
                <?php endfor; ?>
              </td>
            </tr>
          </tbody>
        </table>
        <?php submit_button('Guardar configuración'); ?>
      </form>
    </div>
    <?php
  }

  public function sanitize_settings($input) {
    $defaults = $this->get_default_settings();
    $clean = $defaults;

    $count = isset($input['buttons_count']) ? intval($input['buttons_count']) : $defaults['buttons_count'];
    if ($count < 1) $count = 1;
    if ($count > self::MAX_BUTTONS) $count = self::MAX_BUTTONS;
    $clean['buttons_count'] = $count;

    $bg = $input['background_color'] ?? $defaults['background_color'];
    $bg = sanitize_hex_color($bg);
    $clean['background_color'] = $bg ?: $defaults['background_color'];

    $visibility = $input['visibility'] ?? $defaults['visibility'];
    $allowed_visibility = ['mobile', 'tablet', 'desktop', 'custom'];
    $clean['visibility'] = in_array($visibility, $allowed_visibility, true) ? $visibility : $defaults['visibility'];

    $min = isset($input['custom_min_width']) ? intval($input['custom_min_width']) : 0;
    $max = isset($input['custom_max_width']) ? intval($input['custom_max_width']) : 0;
    if ($min < 0) $min = 0;
    if ($max < 0) $max = 0;
    if ($max && $max < $min) {
      $tmp = $min;
      $min = $max;
      $max = $tmp;
    }
    $clean['custom_min_width'] = $min;
    $clean['custom_max_width'] = $max;

    $buttons = [];
    $provided = $input['buttons'] ?? [];
    $defaults_buttons = $this->get_default_buttons();

    for ($i = 0; $i < $count; $i++) {
      $row = $provided[$i] ?? [];
      $base = $defaults_buttons[$i] ?? end($defaults_buttons);

      $url = isset($row['url']) ? esc_url_raw(trim($row['url'])) : $base['url'];
      $color = isset($row['color']) ? sanitize_hex_color($row['color']) : $base['color'];
      $size = isset($row['size']) ? intval($row['size']) : $base['size'];
      $icon = isset($row['icon']) ? sanitize_key($row['icon']) : $base['icon'];
      $label = isset($row['label']) ? sanitize_text_field($row['label']) : $base['label'];
      $action_type = isset($row['action_type']) ? sanitize_key($row['action_type']) : $base['action_type'];
      $provider = isset($row['integration_provider']) ? sanitize_key($row['integration_provider']) : $base['integration_provider'];
      $selector = isset($row['target_selector']) ? sanitize_text_field(trim($row['target_selector'])) : $base['target_selector'];

      $allowed_action_types = array_keys($this->get_action_types());
      if (!in_array($action_type, $allowed_action_types, true)) {
        $action_type = $base['action_type'];
      }

      $allowed_providers = array_keys($this->get_integration_providers());
      if (!in_array($provider, $allowed_providers, true)) {
        $provider = $base['integration_provider'];
      }

      if ($size < 12) $size = 12;
      if ($size > 48) $size = 48;
      if (!$color) $color = $base['color'];
      if (!$icon) $icon = $base['icon'];
      if (!$label) $label = $base['label'];

      $buttons[] = [
        'url' => $url,
        'color' => $color,
        'size' => $size,
        'icon' => $icon,
        'label' => $label,
        'action_type' => $action_type,
        'integration_provider' => $provider,
        'target_selector' => $selector,
      ];
    }

    $clean['buttons'] = $buttons;

    return $clean;
  }

  private function get_settings(): array {
    $defaults = $this->get_default_settings();
    $saved = get_option(self::OPTION_KEY, []);
    if (!is_array($saved)) $saved = [];

    $merged = array_merge($defaults, $saved);
    $merged['buttons'] = $this->merge_buttons($merged);
    return $merged;
  }

  private function get_default_settings(): array {
    return [
      'buttons_count' => 4,
      'background_color' => '#0b0f19',
      'visibility' => 'mobile',
      'custom_min_width' => 0,
      'custom_max_width' => 0,
      'buttons' => $this->get_default_buttons(),
    ];
  }

  private function get_default_buttons(): array {
    return [
      ['url' => home_url('/'), 'color' => '#ffffff', 'size' => 22, 'icon' => 'dashicons-admin-home', 'label' => '', 'action_type' => 'url', 'integration_provider' => 'none', 'target_selector' => ''],
      ['url' => '#', 'color' => '#ffffff', 'size' => 22, 'icon' => 'dashicons-search', 'label' => '', 'action_type' => 'search', 'integration_provider' => 'elementor', 'target_selector' => ''],
      ['url' => '#', 'color' => '#ffffff', 'size' => 22, 'icon' => 'dashicons-cart', 'label' => '', 'action_type' => 'cart', 'integration_provider' => 'elementor', 'target_selector' => ''],
      ['url' => '#', 'color' => '#ffffff', 'size' => 22, 'icon' => 'dashicons-admin-users', 'label' => '', 'action_type' => 'url', 'integration_provider' => 'none', 'target_selector' => ''],
      ['url' => '#', 'color' => '#ffffff', 'size' => 22, 'icon' => 'dashicons-format-gallery', 'label' => '', 'action_type' => 'url', 'integration_provider' => 'none', 'target_selector' => ''],
      ['url' => '#', 'color' => '#ffffff', 'size' => 22, 'icon' => 'dashicons-share', 'label' => '', 'action_type' => 'url', 'integration_provider' => 'none', 'target_selector' => ''],
    ];
  }

  private function merge_buttons(array $settings): array {
    $count = $settings['buttons_count'] ?? 4;
    $buttons = $settings['buttons'] ?? [];
    $defaults = $this->get_default_buttons();
    $merged = [];

    for ($i = 0; $i < $count; $i++) {
      $base = $defaults[$i] ?? end($defaults);
      $row = $buttons[$i] ?? [];
      $merged[] = array_merge($base, array_intersect_key($row, $base));
    }

    return $merged;
  }

  private function build_inline_style(array $settings): string {
    $css = '';
    $bg = $settings['background_color'] ?: '#0b0f19';
    $css .= ".fvd-mbb__inner{background:{$bg};}\n";

    [$min, $max] = $this->get_visibility_range($settings);

    if ($min > 0) {
      $css .= "@media (max-width:" . ($min - 1) . "px){.fvd-mbb{display:none;}}\n";
    }
    if ($max !== null) {
      $css .= "@media (min-width:" . ($max + 1) . "px){.fvd-mbb{display:none;}}\n";
    }

    return $css;
  }

  private function get_visibility_range(array $settings): array {
    switch ($settings['visibility']) {
      case 'tablet':
        return [768, 1024];
      case 'desktop':
        return [1025, null];
      case 'custom':
        $min = intval($settings['custom_min_width'] ?? 0);
        $max = $settings['custom_max_width'] ? intval($settings['custom_max_width']) : null;
        if ($max !== null && $max < $min) {
          $tmp = $min;
          $min = $max;
          $max = $tmp;
        }
        return [$min, $max];
      case 'mobile':
      default:
        return [0, 767];
    }
  }

  private function get_version(): string {
    if (!function_exists('get_plugin_data')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data(__FILE__, false, false);
    return $data['Version'] ?? '0.0.0';
  }

  private function get_action_types(): array {
    return [
      'url' => 'Enlace URL',
      'search' => 'Abrir buscador',
      'cart' => 'Abrir mini carrito',
      'custom_trigger' => 'Disparar selector personalizado',
    ];
  }

  private function get_integration_providers(): array {
    return [
      'none' => 'Ninguno',
      'elementor' => 'Elementor',
      'royal-elementor' => 'Royal Elementor Addons',
      'custom' => 'Personalizado',
    ];
  }

  private function get_default_selector(string $action_type, string $provider): string {
    if ($provider === 'custom' || $provider === 'none') {
      return '';
    }

    if ($action_type === 'search' && $provider === 'elementor') {
      return '.elementor-search-form__toggle, .elementor-widget-search-form .elementor-search-form__icon';
    }

    if ($action_type === 'search' && $provider === 'royal-elementor') {
      return '.rea-ajax-search-toggle, .rea-search-toggle, .wpr-search-toggle';
    }

    if ($action_type === 'cart' && $provider === 'elementor') {
      return '.elementor-menu-cart__toggle_button, .elementor-widget-woocommerce-menu-cart .elementor-menu-cart__toggle_button';
    }

    if ($action_type === 'cart' && $provider === 'royal-elementor') {
      return '.rea-mini-cart-toggle, .wpr-mini-cart-toggle, .wpr-cart-toggle';
    }

    return '';
  }
}

new FVD_Mobile_Bottom_Bar();
}
