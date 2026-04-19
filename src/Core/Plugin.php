<?php
/**
 * Main plugin file for WPML x Etch.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Core;

use WpmlXEtch\WPML\StringHandler;
use WpmlXEtch\WPML\TranslationSync;
use WpmlXEtch\WPML\TemplateTranslator;
use WpmlXEtch\WPML\ContentTranslationHandler;
use WpmlXEtch\WPML\LoopTranslator;
use WpmlXEtch\Etch\ComponentParser;
use WpmlXEtch\Etch\MetaSync;
use WpmlXEtch\Etch\DynamicLanguageData;
use WpmlXEtch\Admin\BuilderPanel;
use WpmlXEtch\Admin\ResyncHandler;
use WpmlXEtch\Admin\TranslationDataQuery;
use WpmlXEtch\Admin\TranslationJobManager;
use WpmlXEtch\Admin\TranslationStatusResolver;
use WpmlXEtch\Admin\PanelConfig;
use WpmlXEtch\Admin\HealthCheck;
use WpmlXEtch\AI\AiSettings;
use WpmlXEtch\AI\AiClient;
use WpmlXEtch\AI\AiTranslationHandler;
use WpmlXEtch\License\LicenseManager;
use WpmlXEtch\RestApi\TranslationRoutes;

/**
 * Main Class.
 */
class Plugin
{
    /**
     * Plugin version — reads from ZS_WXE_VERSION constant defined in wpml-x-etch.php.
     * Update the version ONLY in the main plugin file header + ZS_WXE_VERSION.
     */
    public static function version(): string
    {
        return ZS_WXE_VERSION;
    }

    /** Detect Etch dynamic expressions: {variable} or {{"key":"{val}"}}. */
    public const DYNAMIC_EXPR_PATTERN = '/^\{[^}]+\}$|^\{\{.*\}\}$/s';

    private readonly ComponentParser $component_parser;
    private readonly MetaSync $meta_sync;
    private readonly TranslationSync $translation_sync;
    private readonly StringHandler $string_handler;
    private readonly TemplateTranslator $template_translator;
    private readonly LoopTranslator $loop_translator;
    private readonly ContentTranslationHandler $content_translation_handler;
    private readonly BuilderPanel $builder_panel;
    private readonly DynamicLanguageData $dynamic_language_data;
    private readonly AiTranslationHandler $ai_handler;
    private readonly LicenseManager $license_manager;

    /** @var SubscriberInterface[] */
    private array $subscribers = [];

    public function __construct()
    {
        $this->component_parser = new ComponentParser();
        $this->translation_sync = new TranslationSync();
        $this->string_handler = new StringHandler( $this->component_parser );
        $this->template_translator = new TemplateTranslator();
        $this->content_translation_handler = new ContentTranslationHandler();
        $this->loop_translator = new LoopTranslator();
        $this->meta_sync = new MetaSync(
            $this->component_parser,
            $this->string_handler,
            $this->translation_sync,
        );
        $data_query       = new TranslationDataQuery( $this->component_parser );
        $job_manager      = new TranslationJobManager();
        $status_resolver  = new TranslationStatusResolver( $data_query, $job_manager );
        $this->license_manager = new LicenseManager();
        $panel_config = new PanelConfig( $this->license_manager );
        $resync_handler = new ResyncHandler(
            $this->component_parser,
            $this->string_handler,
            $this->translation_sync,
            $this->content_translation_handler,
        );
        $ai_settings = new AiSettings();
        $ai_client   = new AiClient( $ai_settings );
        $this->ai_handler = new AiTranslationHandler(
            $this->component_parser,
            $this->string_handler,
            $this->content_translation_handler,
            $ai_settings,
            $ai_client,
            $resync_handler,
            $job_manager,
            $panel_config,
        );
        $this->builder_panel = new BuilderPanel( $this->meta_sync, $status_resolver, $data_query, $job_manager, $panel_config, $resync_handler, $ai_settings, $this->license_manager );
        $this->dynamic_language_data = new DynamicLanguageData();

        // Update checker runs regardless of Etch/WPML being active.
        $update_checker = new UpdateChecker(ZS_WXE_PLUGIN_FILE);
        $this->register_subscriber_hooks($update_checker);
    }

    /**
     * Initialize the plugin.
     *
     * @return void
     */
    public function init(): void
    {
        if (!defined("ETCH_PLUGIN_FILE") || !defined("ICL_SITEPRESS_VERSION")) {
            return;
        }

        // Health check runs early — warns admins if WPML deps are missing.
        ( new HealthCheck() )->init();

        $this->register_subscribers();
        $this->register_core_hooks();
    }

    /**
     * Register all subscriber hooks.
     */
    private function register_subscribers(): void
    {
        $this->subscribers = [
            $this->meta_sync,
            $this->translation_sync,
            $this->string_handler,
            $this->content_translation_handler,
            $this->template_translator,
            $this->loop_translator,
            $this->builder_panel,
            $this->dynamic_language_data,
        ];

        foreach ($this->subscribers as $subscriber) {
            $this->register_subscriber_hooks($subscriber);
        }
    }

    /**
     * Register hooks for a single subscriber.
     */
    private function register_subscriber_hooks(
        SubscriberInterface $subscriber,
    ): void {
        foreach ($subscriber::getSubscribedEvents() as $key => $value) {
            // Numeric key: array( 'hook', 'method', priority, args )
            if (is_int($key) && is_array($value)) {
                $hook = $value[0];
                $method = $value[1];
                $priority = $value[2] ?? 10;
                $args = $value[3] ?? 1;
                add_filter($hook, [$subscriber, $method], $priority, $args);
                continue;
            }

            // String key with string value: 'hook' => 'method'
            if (is_string($value)) {
                add_filter($key, [$subscriber, $value]);
                continue;
            }

            // String key with array value: 'hook' => ['method', priority, args]
            if (is_array($value)) {
                $method = $value[0];
                $priority = $value[1] ?? 10;
                $args = $value[2] ?? 1;
                add_filter($key, [$subscriber, $method], $priority, $args);
            }
        }
    }

    /**
     * Register hooks that belong to the Plugin class itself.
     */
    private function register_core_hooks(): void
    {
        add_action("init", [$this, "load_textdomain"], 1);
        add_action("init", [$this, "register_post_types"], 99);
        add_action("rest_api_init", [$this, "register_rest_routes"]);

        // WPML PB REST API native editor bypass.
        add_filter(
            "wpml_pb_is_editing_translation_with_native_editor",
            "__return_false",
            99,
        );

        // Exclude translation_priority taxonomy from ATE translation jobs.
        add_filter( 'get_translatable_taxonomies', array( $this, 'exclude_translation_priority_taxonomy' ) );

        // UI strings are static — only re-register on version change.
        $this->maybe_register_ui_strings();

    }

    /**
     * Register post types with WPML.
     *
     * @return void
     */
    public function register_post_types(): void
    {
        global $sitepress;

        if (!isset($sitepress) || !is_object($sitepress)) {
            return;
        }

        $settings = $sitepress->get_settings();
        $sync_option = $settings["custom_posts_sync_option"] ?? [];
        $changed = false;

        if (empty($sync_option["wp_block"])) {
            $sync_option["wp_block"] = 1;
            $changed = true;
        }

        $etch_cpts = get_option("etch_cpts", []);
        if (is_array($etch_cpts)) {
            foreach (array_keys($etch_cpts) as $cpt) {
                if (empty($sync_option[$cpt])) {
                    $sync_option[$cpt] = 1;
                    $changed = true;
                }
            }
        }

        // Exclude translation_priority from translatable taxonomies.
        // WPML's own wpml-config.xml declares it with translate="1", which
        // forces taxonomies_sync_option['translation_priority'] = 1 and
        // causes it to appear in ATE jobs. Override it here.
        $tax_sync = $settings["taxonomies_sync_option"] ?? [];
        if (!empty($tax_sync["translation_priority"])) {
            $tax_sync["translation_priority"] = 0;
            $settings["taxonomies_sync_option"] = $tax_sync;
            $changed = true;
        }

        if ($changed) {
            $settings["custom_posts_sync_option"] = $sync_option;
            $sitepress->save_settings($settings);
        }

        // Ensure wp_block posts use ATE.
        $tm_opts = get_option("icl_translation_management_options", []);
        if (empty($tm_opts["doc_translation_method"]["wp_block"])) {
            $tm_opts["doc_translation_method"]["wp_block"] = 1;
            update_option("icl_translation_management_options", $tm_opts);
        }
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_rest_routes(): void
    {
        $routes = new TranslationRoutes( $this->builder_panel, $this->ai_handler, $this->license_manager );
        $routes->register();
    }

    /**
     * Handle plugin activation.
     *
     * @return void
     */
    public function on_activate(): void
    {
        $this->register_post_types();
        $this->maybe_register_ui_strings(true);
        $this->backfill_component_refs();
    }

    /**
     * Backfill _zs_wxe_component_refs meta for existing published posts.
     *
     * @return void
     */
    private function backfill_component_refs(): void
    {
        global $wpdb;

        $offset = 0;
        $batch  = 100;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_content, post_type FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type != 'wp_block'
                   AND post_content LIKE %s
                 LIMIT %d OFFSET %d",
                '%' . $wpdb->esc_like( '<!-- wp:etch/' ) . '%',
                $batch,
                $offset
            ) );

            foreach ( $posts as $post ) {
                $blocks = parse_blocks( $post->post_content );
                $refs   = $this->component_parser->extract_component_refs( $blocks );

                if ( ! empty( $refs ) ) {
                    update_post_meta(
                        (int) $post->ID,
                        '_zs_wxe_component_refs',
                        wp_json_encode( array_values( $refs ) ),
                    );
                } else {
                    delete_post_meta( (int) $post->ID, '_zs_wxe_component_refs' );
                }
            }

            $offset += $batch;
        } while ( ! empty( $posts ) );
    }

    /**
     * Register static UI strings with WPML only when the plugin version changes.
     *
     * @param bool $force Force registration regardless of version check.
     * @return void
     */
    private function maybe_register_ui_strings(bool $force = false): void
    {
        if (
            !$force &&
            get_option("zs_wxe_ui_strings_version") === self::version()
        ) {
            return;
        }

        add_action( 'init', array( $this, 'do_register_ui_strings' ), 30 );
    }

    /**
     * Exclude translation_priority from translatable taxonomies.
     *
     * @param mixed $taxonomies Taxonomy array from WPML.
     * @return mixed
     */
    public function exclude_translation_priority_taxonomy( mixed $taxonomies ): mixed
    {
        if ( isset( $taxonomies['taxs'] ) && is_array( $taxonomies['taxs'] ) ) {
            $taxonomies['taxs'] = array_diff( $taxonomies['taxs'], array( 'translation_priority' ) );
        }
        return $taxonomies;
    }

    /**
     * Register UI strings with WPML and update version flag.
     *
     * @return void
     */
    public function do_register_ui_strings(): void
    {
        $this->string_handler->register_ui_strings();
        update_option( 'zs_wxe_ui_strings_version', self::version() );
    }

    /**
     * Load plugin textdomain for translations.
     *
     * @return void
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            "wpml-x-etch",
            false,
            dirname(plugin_basename(ZS_WXE_PLUGIN_FILE)) . "/languages",
        );
    }
}
