<?php
/**
 * Builder panel: assets, permissions, REST handlers.
 *
 * All translation status goes through TranslationStatusResolver.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Admin;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WpmlXEtch\AI\AiSettings;
use WpmlXEtch\License\LicenseManager;
use WpmlXEtch\Core\SubscriberInterface;
use WpmlXEtch\Etch\MetaSync;
use WpmlXEtch\Utils\Logger;

/**
 * Handles the WPML panel in Etch builder.
 */
class BuilderPanel implements SubscriberInterface {

	/**
	 * Etch's query-string value that activates builder mode (?etch=magic).
	 * Defined by the Etch core plugin; mirrored here to avoid magic strings.
	 */
	public const ETCH_MAGIC_PARAM = 'magic';

	private readonly TranslationJobManager $job_manager;
	private readonly TranslationDataQuery $data_query;
	private readonly TranslationStatusResolver $status_resolver;
	private readonly PanelConfig $config;
	private readonly MetaSync $meta_sync;
	private readonly ResyncHandler $resync_handler;
	private readonly AiSettings $ai_settings;
	private readonly LicenseManager $license_manager;

	public function __construct( MetaSync $meta_sync, TranslationStatusResolver $status_resolver, TranslationDataQuery $data_query, TranslationJobManager $job_manager, PanelConfig $config, ResyncHandler $resync_handler, AiSettings $ai_settings, LicenseManager $license_manager ) {
		$this->meta_sync       = $meta_sync;
		$this->status_resolver = $status_resolver;
		$this->data_query      = $data_query;
		$this->job_manager     = $job_manager;
		$this->config          = $config;
		$this->resync_handler  = $resync_handler;
		$this->ai_settings     = $ai_settings;
		$this->license_manager = $license_manager;
	}

	public static function getSubscribedEvents(): array {
		return array(
			array( 'wp', 'force_default_language_in_etch', 1 ),
			array( 'template_redirect', 'redirect_translation_to_original', 1 ),
			array( 'wp_enqueue_scripts', 'enqueue', 100 ),
			array( 'rest_request_after_callbacks', 'filter_components_response', 10, 3 ),
			array( 'page_row_actions', 'fix_edit_with_etch_url', 20, 2 ),
			array( 'wpml_ls_html', 'disable_ls_in_etch', 10, 2 ),
			array( 'wpml_footer_language_switcher', 'disable_footer_ls_in_etch' ),
		);
	}

	/**
	 * Filterable via `zs_wxe_user_can_translate` to allow WPML translators
	 * or other custom roles.
	 */
	private function current_user_can_translate(): bool {
		$can = current_user_can( 'manage_options' ) || current_user_can( 'translate' );
		return (bool) apply_filters( 'zs_wxe_user_can_translate', $can );
	}

	/**
	 * Build Etch builder URL for a post, language-prefix-free.
	 *
	 * WPML filters home_url() adding /ca/, /es/ etc. Etch always runs on the
	 * root URL. We bypass WPML's filter by reading the raw option value.
	 */
	private function etch_builder_url( int $post_id ): string {
		// get_option( 'home' ) returns the unfiltered home URL, avoiding
		// WPML's language prefix that home_url() would add.
		$home = untrailingslashit( get_option( 'home' ) );
		return $home . '/?etch=' . self::ETCH_MAGIC_PARAM . '&post_id=' . $post_id;
	}

	private function validate_post( int $post_id ): ?WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found', array( 'status' => 404 ) );
		}
		$is_translatable = (bool) apply_filters( 'wpml_is_translated_post_type', false, $post->post_type );
		if ( ! $is_translatable ) {
			return new WP_Error( 'not_translatable', 'Post type not translatable', array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Redirect translated posts to the original when opening Etch.
	 *
	 * Prevents users from editing a translation post directly in Etch,
	 * which would overwrite WPML-managed translations.
	 */
	/**
	 * Force WPML to the default language when Etch builder is active.
	 *
	 * Without this, arriving from /ca/ or /es/ makes WPML filter all post
	 * queries to that language, so Etch's Content Hub only shows translated
	 * posts instead of all originals.
	 */
	public function force_default_language_in_etch(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::ETCH_MAGIC_PARAM !== sanitize_text_field( wp_unslash( $_GET['etch'] ?? '' ) ) ) {
			return;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		do_action( 'wpml_switch_language', $default_lang );
	}

	public function redirect_translation_to_original(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::ETCH_MAGIC_PARAM !== sanitize_text_field( wp_unslash( $_GET['etch'] ?? '' ) ) || empty( $_GET['post_id'] ) ) {
			return;
		}

		$post_id   = absint( $_GET['post_id'] );
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$current_lang = apply_filters( 'wpml_current_language', null );
		$needs_redirect = false;

		// If browsing in a non-default language, the URL has a /ca/ or /es/
		// prefix that Etch doesn't need. Redirect to the clean root URL.
		if ( $current_lang && $current_lang !== $default_lang ) {
			$needs_redirect = true;
		}

		// If post_id is a translation, resolve to the original.
		$target_id      = $post_id;
		$is_translatable = (bool) apply_filters( 'wpml_is_translated_post_type', null, $post_type );
		if ( $is_translatable ) {
			$original_id = (int) apply_filters( 'wpml_object_id', $post_id, $post_type, true, $default_lang );
			if ( $original_id && $original_id !== $post_id ) {
				$target_id      = $original_id;
				$needs_redirect = true;
			}
		}

		if ( $needs_redirect ) {
			$url = $this->etch_builder_url( $target_id );
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}

	/**
	 * Enqueue builder panel scripts and styles.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked via enqueue context, not form submission.
		if (
			self::ETCH_MAGIC_PARAM !== sanitize_text_field( wp_unslash( $_GET['etch'] ?? '' ) ) ||
			empty( $_GET['post_id'] ) ||
			! $this->current_user_can_translate()
		) {
			return;
		}

		$post_id   = absint( $_GET['post_id'] ?? 0 );
		$post_type = get_post_type( $post_id );

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) || ! is_array( $active_langs ) ) {
			return;
		}

		// The panel always loads (even for non-translatable CPTs) because the
		// page may contain translatable components. Page-level translation data
		// is only populated when the post type itself is translatable.
		$is_translatable = (bool) apply_filters( 'wpml_is_translated_post_type', null, $post_type );

		$state       = $this->build_panel_state( $post_id, $active_langs, $is_translatable );
		$original_id = $state['original_id'];
		$trid        = $state['trid'];

		// Resolve current language code (enqueue-specific, not in build_panel_state).
		$current_lang_code = apply_filters( 'wpml_default_language', null );
		if ( $is_translatable && $trid ) {
			$lang_details = apply_filters( 'wpml_element_language_details', null, array(
				'element_id'   => $post_id,
				'element_type' => 'post_' . $post_type,
			) );
			$current_lang_code = is_object( $lang_details ) && ! empty( $lang_details->language_code )
				? $lang_details->language_code
				: apply_filters( 'wpml_default_language', null );
		}

		// Core assets (always loaded).
		wp_enqueue_style(
			'wxe-panel',
			plugins_url( 'assets/wxe-panel.css', ZS_WXE_PLUGIN_FILE ),
			array(),
			ZS_WXE_VERSION
		);

		// Polyfill for the `interestfor` attribute (Chrome 142+ native, Firefox/Safari
		// not yet). Self-detects support and no-ops on browsers that already ship it.
		// BSD-3 © 2025 Mason Freed — vendored from github.com/mfreed7/interestfor.
		wp_enqueue_script(
			'wxe-interestfor-polyfill',
			plugins_url( 'assets/interestfor.min.js', ZS_WXE_PLUGIN_FILE ),
			array(),
			ZS_WXE_VERSION,
			true
		);

		wp_enqueue_script(
			'wxe-panel',
			plugins_url( 'assets/wxe-panel.js', ZS_WXE_PLUGIN_FILE ),
			array( 'wxe-interestfor-polyfill' ),
			ZS_WXE_VERSION,
			true
		);

		// Locking assets (conditionally loaded based on mode).
		$locking_mode = $this->config->get_locking_mode();
		// Valid modes: 'free', 'supporter', 'pro'

		if ( 'free' === $locking_mode ) {
			// FREE mode: Show locked features with lock icons and reduced opacity
			wp_enqueue_style(
				'wxe-locking',
				plugins_url( 'assets/wxe-locking.css', ZS_WXE_PLUGIN_FILE ),
				array( 'wxe-panel' ),
				ZS_WXE_VERSION
			);

			wp_enqueue_script(
				'wxe-locking',
				plugins_url( 'assets/wxe-locking.js', ZS_WXE_PLUGIN_FILE ),
				array( 'wxe-panel' ),
				ZS_WXE_VERSION,
				true
			);
		} elseif ( 'supporter' === $locking_mode ) {
			// SUPPORTER mode: Hide locked features completely (cleaner interface)
			wp_enqueue_style(
				'wxe-locking',
				plugins_url( 'assets/wxe-locking.css', ZS_WXE_PLUGIN_FILE ),
				array( 'wxe-panel' ),
				ZS_WXE_VERSION
			);

			wp_enqueue_script(
				'wxe-locking',
				plugins_url( 'assets/wxe-locking.js', ZS_WXE_PLUGIN_FILE ),
				array( 'wxe-panel' ),
				ZS_WXE_VERSION,
				true
			);

			wp_enqueue_style(
				'wxe-supporter',
				plugins_url( 'assets/wxe-supporter.css', ZS_WXE_PLUGIN_FILE ),
				array( 'wxe-locking' ),
				ZS_WXE_VERSION
			);
		}
		// PRO mode: No locking files loaded, everything is accessible

		Logger::debug( 'BuilderPanel enqueued', array(
			'post_id'         => $original_id,
			'component_count' => count( $state['components'] ),
		) );

		// Search is available for supporter and pro, locked for free.
		$search_locked = ( 'free' === $locking_mode );

		wp_localize_script( 'wxe-panel', 'wxeBridge', array(
			'languages'        => $state['lang_data'],
			'components'       => $state['components'],
			'jsonLoops'        => $state['json_loops'],
			'loopStatuses'     => $state['all_loop_statuses'],
			'combinedStatus'   => $state['combined_status'],
			'isTranslatable'   => $is_translatable ? 1 : 0,
			'currentPostId'    => $post_id,
			'currentPostType'  => $post_type,
			'currentLang'      => $current_lang_code,
			'postTitle'        => $state['post_title'],
			'etchUrl'          => $this->etch_builder_url( $original_id ),
			'postTypeLabel'    => $state['post_type_label'],
			'contentTypePills' => $this->config->get_content_type_pills(),
			'searchLocked'     => $search_locked,
			'lockingMode'      => $locking_mode,
			'loopPresetActive' => $this->config->is_loop_preset_active(),
			'switcherComponentJson' => $this->config->get_switcher_component_json(),
			'restUrl'          => rest_url( 'wpml-x-etch/v1/' ),
			'restNonce'        => wp_create_nonce( 'wp_rest' ),
			'wpmlSettingsUrl'  => admin_url( 'admin.php?page=tm%2Fmenu%2Fsettings#ml-content-setup-sec-7' ),
			'aiConfigured'     => $this->ai_settings->is_configured(),
			'aiVerified'       => $this->ai_settings->is_verified(),
			'aiAccess'         => ! empty( $this->config->get_pill_access()['ai'] ),
			'licenseStatus'    => $this->license_manager->get_status(),
			'messages'         => $this->get_localized_messages(),
		) );
	}

	/** Strip translated duplicates from etch-api/components responses so only originals appear. */
	public function filter_components_response( mixed $response, mixed $handler, WP_REST_Request $request ): mixed {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}

		if ( $request->get_method() !== 'GET' ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! preg_match( '#^/etch-api/components(/list)?$#', $route ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$filtered = array_values(
			array_filter( $data, function ( $item ) {
				if ( empty( $item['id'] ) ) {
					return true;
				}

				$lang = apply_filters( 'wpml_element_language_details', null, array(
					'element_id'   => (int) $item['id'],
					'element_type' => 'post_wp_block',
				) );

				if ( ! is_object( $lang ) ) {
					return true;
				}

				return property_exists( $lang, 'source_language_code' ) &&
					$lang->source_language_code === null;
			} )
		);

		$response->set_data( $filtered );

		return $response;
	}

	/** Rewrite the "Edit with Etch" row action to always open the original-language post. */
	public function fix_edit_with_etch_url( array $actions, WP_Post $post ): array {
		if ( empty( $actions['edit_with_etch'] ) ) {
			return $actions;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$original_id  = (int) apply_filters(
			'wpml_object_id',
			$post->ID,
			$post->post_type,
			true,
			$default_lang
		);

		if ( ! $original_id || $original_id === $post->ID ) {
			return $actions;
		}

		$edit_url = add_query_arg(
			array(
				'etch'    => self::ETCH_MAGIC_PARAM,
				'post_id' => $original_id,
			),
			trailingslashit( (string) get_option( 'home' ) )
		);

		$actions['edit_with_etch'] = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( $edit_url ),
			__( 'Edit with Etch', 'etch' )
		);

		return $actions;
	}

	/** Suppress WPML language switcher inside the Etch builder. */
	public function disable_ls_in_etch( mixed $html, mixed $args ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- query-param check only.
		return self::ETCH_MAGIC_PARAM === sanitize_text_field( wp_unslash( $_GET['etch'] ?? '' ) ) ? '' : $html;
	}

	/** @see self::disable_ls_in_etch() — same logic for the footer switcher. */
	public function disable_footer_ls_in_etch( mixed $html ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- query-param check only.
		return self::ETCH_MAGIC_PARAM === sanitize_text_field( wp_unslash( $_GET['etch'] ?? '' ) ) ? '' : $html;
	}

	// ─── REST handler delegation ─────────────────────────────────────────────────

	/**
	 * Build the shared translation state for a post.
	 *
	 * Used by both enqueue() (page load) and handle_get_languages_status() (REST).
	 *
	 * @param int   $post_id         The post being edited.
	 * @param array $active_langs    Active WPML languages.
	 * @param bool  $is_translatable Whether the post type is WPML-translatable.
	 */
	private function build_panel_state( int $post_id, array $active_langs, bool $is_translatable = true ): array {
		$post_type   = get_post_type( $post_id );
		$original_id = 0;
		$trid        = 0;
		$lang_data   = array();

		if ( $is_translatable ) {
			$original_id = (int) apply_filters(
				'wpml_object_id',
				$post_id,
				$post_type,
				true,
				apply_filters( 'wpml_default_language', null )
			);
			$trid = $original_id ? (int) apply_filters( 'wpml_element_trid', null, $original_id, 'post_' . $post_type ) : null;
		}

		if ( $trid ) {
			// Heal any half-state rows (status=10 with element_id=NULL) before
			// resolving status. See TranslationJobManager::heal_half_state().
			$this->job_manager->heal_half_states_for_trid( $trid );
			$lang_data = $this->status_resolver->resolve_post_lang_data( $original_id, $post_type, $active_langs );
		}

		$effective_id = $original_id ?: $post_id;
		$components   = $this->data_query->get_components_in_page( $effective_id, $active_langs );

		// Loop statuses (only when unlocked).
		$access             = $this->config->get_pill_access();
		$loops_unlocked     = ! empty( $access['json-loops'] );
		$json_loops         = $loops_unlocked ? $this->config->get_json_loops( $effective_id ) : array();
		$all_loop_names     = array_column( $json_loops, 'name' );
		$context_loop_names = array_column( array_filter( $json_loops, fn( $l ) => $l['onThisPage'] ), 'name' );
		$non_default        = array_values( array_diff( array_keys( $active_langs ), array( apply_filters( 'wpml_default_language', null ) ) ) );
		$all_loop_statuses  = $loops_unlocked ? $this->data_query->get_loop_string_statuses( $all_loop_names, $non_default ) : array();
		$context_loop_statuses = array_intersect_key( $all_loop_statuses, array_flip( $context_loop_names ) );

		$combined_status = array();
		if ( ! empty( $lang_data ) ) {
			$combined_status = $this->data_query->calculate_combined_status( $lang_data, $components, $context_loop_statuses );
		}

		$post_type_obj   = get_post_type_object( $post_type );
		$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post_type;

		return array(
			'original_id'      => $original_id,
			'trid'             => $trid,
			'post_type'        => $post_type,
			'lang_data'        => $lang_data,
			'components'       => $components,
			'json_loops'       => $json_loops,
			'all_loop_statuses' => $all_loop_statuses,
			'combined_status'  => $combined_status,
			'post_title'       => get_the_title( $effective_id ),
			'post_type_label'  => $post_type_label,
		);
	}

	/** All translatable UI strings for wp_localize_script. */
	private function get_localized_messages(): array {
		return array(
			// Progress / error feedback.
			'saving'            => __( 'Saving before we proceed.', 'wpml-x-etch' ),
			'savingSlow'        => __( 'Still saving. This takes a moment.', 'wpml-x-etch' ),
			'preparing'         => __( 'Preparing %s translation.', 'wpml-x-etch' ),
			'waitingAte'        => __( 'Waiting for ATE.', 'wpml-x-etch' ),
			'openingEditor'     => __( 'Opening editor.', 'wpml-x-etch' ),
			'saveFailed'        => __( 'Save failed. Try again.', 'wpml-x-etch' ),
			'ateTimeout'        => __( "ATE didn't respond in time. Try again.", 'wpml-x-etch' ),
			'httpError'         => __( 'HTTP %s error. Try again.', 'wpml-x-etch' ),
			'noContent'         => __( 'Nothing to translate.', 'wpml-x-etch' ),
			'noFilterResults'   => __( 'No items match current filters', 'wpml-x-etch' ),
			'couldNotLoad'      => __( 'Could not load data.', 'wpml-x-etch' ),
			'saveTimeout'       => __( 'Save timeout', 'wpml-x-etch' ),
			// Panel chrome.
			'panelTitle'        => __( 'WPML × Etch', 'wpml-x-etch' ),
			'translations'      => __( 'Translations', 'wpml-x-etch' ),
			'backToBuilder'     => __( 'Back to Builder', 'wpml-x-etch' ),
			'pageFallback'      => __( 'Page', 'wpml-x-etch' ),
			// Section headers.
			'currentContext'    => __( 'Current Context', 'wpml-x-etch' ),
			'currentContextStatus' => __( 'Current context status', 'wpml-x-etch' ),
			'defaultLanguage'   => __( 'Default Language', 'wpml-x-etch' ),
			'components'        => __( 'Components', 'wpml-x-etch' ),
			'filters'           => __( 'Filters', 'wpml-x-etch' ),
			'clear'             => __( 'Clear', 'wpml-x-etch' ),
			'languages'         => __( 'Languages', 'wpml-x-etch' ),
			'noLanguages'       => __( 'No languages configured.', 'wpml-x-etch' ),
			'translation'       => __( 'Translation', 'wpml-x-etch' ),
			'status'            => __( 'Status', 'wpml-x-etch' ),
			// Language Switcher section.
			'sourceLanguage'     => __( 'Source', 'wpml-x-etch' ),
			'sourceLanguageTooltip' => __( 'Default language', 'wpml-x-etch' ),
			'switcherComponent'  => __( 'Lang Switcher Component', 'wpml-x-etch' ),
			'enableComponent'    => __( 'Enable component', 'wpml-x-etch' ),
			'enableComponentDesc' => __( 'Registers a JSON loop so the switcher works as an Etch component.', 'wpml-x-etch' ),
			'copyComponent'      => __( 'Copy Component & Close', 'wpml-x-etch' ),
			'copied'             => __( 'Copied!', 'wpml-x-etch' ),
			'loopEnabled'        => __( 'Language Switcher enabled.', 'wpml-x-etch' ),
			'loopDisabled'       => __( 'Language Switcher disabled.', 'wpml-x-etch' ),
			'reloadToApply'      => __( 'Reload to apply changes.', 'wpml-x-etch' ),
			'saveAndReload'      => __( 'Save & Reload', 'wpml-x-etch' ),
			'dismiss'            => __( 'Dismiss', 'wpml-x-etch' ),
			'reloading'          => __( 'Reloading.', 'wpml-x-etch' ),
			// Footer links.
			'quickAccess'       => __( 'Quick WPML Links', 'wpml-x-etch' ),
			'wpmlSettings'      => __( 'Settings', 'wpml-x-etch' ),
			'wpmlStrings'       => __( 'Strings', 'wpml-x-etch' ),
			'wpmlTranslations'  => __( 'Translations', 'wpml-x-etch' ),
			// AI Translation.
			'aiTranslation'       => __( 'AI Translation', 'wpml-x-etch' ),
			'aiConfigured'        => __( 'Configured', 'wpml-x-etch' ),
			'aiNotConfiguredShort' => __( 'Not configured', 'wpml-x-etch' ),
			'aiNotConfigured'     => __( 'Configure AI settings first.', 'wpml-x-etch' ),
			'aiProvider'          => __( 'Provider', 'wpml-x-etch' ),
			'aiApiKey'            => __( 'API Key', 'wpml-x-etch' ),
			'aiEnterKey'          => __( 'Enter API key', 'wpml-x-etch' ),
			'aiTest'              => __( 'Verify', 'wpml-x-etch' ),
			'aiTesting'           => __( 'Verifying…', 'wpml-x-etch' ),
			'aiTestSuccess'       => __( 'Valid', 'wpml-x-etch' ),
			'aiTestFailed'        => __( 'Invalid key', 'wpml-x-etch' ),
			'aiClearKey'          => __( 'Clear', 'wpml-x-etch' ),
			'aiKeyCleared'        => __( 'Key removed', 'wpml-x-etch' ),
			'aiNotVerified'       => __( 'Not verified', 'wpml-x-etch' ),
			'aiTone'              => __( 'Tone', 'wpml-x-etch' ),
			'aiFormal'            => __( 'Formal', 'wpml-x-etch' ),
			'aiInformal'          => __( 'Informal', 'wpml-x-etch' ),
			'aiSave'              => __( 'Save Settings', 'wpml-x-etch' ),
			'aiTranslating'       => __( 'Translating to %s…', 'wpml-x-etch' ),
			'aiTranslatingAll'    => __( 'Translating to all languages…', 'wpml-x-etch' ),
			'aiComplete'          => __( 'Translated %n strings to %s.', 'wpml-x-etch' ),
			'aiAllComplete'       => __( '%n languages translated.', 'wpml-x-etch' ),
			'aiAlreadyTranslated' => __( 'Already translated.', 'wpml-x-etch' ),
			'aiError'             => __( 'Translation failed.', 'wpml-x-etch' ),
			'aiTranslateAll'      => __( 'Translate all', 'wpml-x-etch' ),
			// Search.
			'search'            => __( 'Search', 'wpml-x-etch' ),
			'searchPlaceholder' => __( 'Search by title…', 'wpml-x-etch' ),
			// Idle state.
			'selectLanguage'    => __( 'Select a language to translate', 'wpml-x-etch' ),
			'securityNote'      => __( "For security reasons, WPML's translation editor opens in a new secure tab.", 'wpml-x-etch' ),
			// Status labels.
			'statusComplete'       => __( 'Complete', 'wpml-x-etch' ),
			'statusNeedsUpdate'    => __( 'Needs Update', 'wpml-x-etch' ),
			'statusInProgress'     => __( 'In Progress', 'wpml-x-etch' ),
			'statusNotTranslated'  => __( 'Not Translated', 'wpml-x-etch' ),
			'statusWaiting'        => __( 'Needs Translation', 'wpml-x-etch' ),
			'statusNotTranslatable'  => __( 'Not Translatable', 'wpml-x-etch' ),
			'statusTranslated'      => __( 'Translated', 'wpml-x-etch' ),
			'notTranslatableInfo'   => __( 'This content type is not enabled for translation in WPML.', 'wpml-x-etch' ),
			'enableTranslation'     => __( 'Enable in WPML Settings', 'wpml-x-etch' ),
			// Locking.
			'upgradeToPro'      => __( 'Upgrade to Pro to browse all content', 'wpml-x-etch' ),
			// Force Sync — the action lives in the sidebar footer title row,
			// to the right of the WPML label, mirroring the title+shortcut
			// pattern of the Languages/Status sections. The hover tooltip
			// surfaces both the global last run and the per-page local run.
			'resync'            => __( 'Force Sync', 'wpml-x-etch' ),
			'resyncNeverRun'    => __( 'Never run', 'wpml-x-etch' ),
			'resyncRetry'       => __( 'Retry', 'wpml-x-etch' ),
			'resyncing'         => __( 'Syncing…', 'wpml-x-etch' ),
			'resyncError'       => __( 'Sync failed. Try again.', 'wpml-x-etch' ),
			'scopeAllSite'      => __( 'All site', 'wpml-x-etch' ),
			'scopeCurrentContext' => __( 'Current context', 'wpml-x-etch' ),
			// Tooltip value lines. `%1$d` / `%2$d` are placeholders for the
			// numerator (complete) / denominator (total). `%s` in scope
			// templates carries the (lowercase, singular) post-type label.
			'entriesFmt'         => __( '%d entries', 'wpml-x-etch' ),
			'thisScopeFmt'       => __( 'This %s', 'wpml-x-etch' ),
			'plusComponentsFmt'  => __( '+ %d components', 'wpml-x-etch' ),
			'plusComponentSingular' => __( '+ 1 component', 'wpml-x-etch' ),
			'translationsCompleteFmt' => __( '%1$d of %2$d translations complete', 'wpml-x-etch' ),
			'languagesCompleteFmt'    => __( '%1$d of %2$d languages complete', 'wpml-x-etch' ),
		);
	}

	/** Refresh language statuses for a post. Forces ATE sync first (webhooks may fail on localhost). */
	public function handle_get_languages_status( int $post_id ): array|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'missing_params', 'Missing parameters', array( 'status' => 400 ) );
		}
		$error = $this->validate_post( $post_id );
		if ( $error ) { return $error; }

		// Flush any pending meta sync queue so that saves completed just before
		// this REST call are reflected in the status (avoids shutdown-hook race).
		$this->meta_sync->process_save_queue();

		// Force WPML to sync & download pending jobs before checking statuses.
		// This ensures translations appear immediately even if webhooks fail (e.g. localhost).
		$this->job_manager->force_ate_sync();

		// Fix needs_update set by WPML's save_translation() self-reinforcing loop.
		// Must run after force_ate_sync (which may complete translations) and before
		// resolving statuses (which reads needs_update from DB).
		$this->meta_sync->fix_needs_update_after_ate();

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) || ! is_array( $active_langs ) ) {
			return new WP_Error( 'no_languages', 'No active languages', array( 'status' => 500 ) );
		}

		$state     = $this->build_panel_state( $post_id, $active_langs );
		$post_type = $state['post_type'];

		return array(
			'languages'       => $state['lang_data'],
			'components'      => $state['components'],
			'loopStatuses'    => $state['all_loop_statuses'],
			'postTitle'       => $state['post_title'],
			'postTypeLabel'   => $state['post_type_label'],
			'combinedStatus'  => $state['combined_status'],
			'isTranslatable'  => apply_filters( 'wpml_is_translated_post_type', null, $post_type ) ? 1 : 0,
			'currentPostType' => $post_type,
		);
	}

	/** Resolve the ATE editor URL. Delegates to TranslationJobManager. */
	public function handle_get_translate_url( int $post_id, string $target_lang, int $component_id = 0 ): array|WP_Error {
		if ( ! $post_id || ! $target_lang ) {
			return new WP_Error( 'missing_params', 'Missing parameters', array( 'status' => 400 ) );
		}
		$error = $this->validate_post( $post_id );
		if ( $error ) { return $error; }

		// If component_id is provided, translate the component instead of the page.
		$translate_id = $component_id ? $component_id : $post_id;

		// Always return to the original page, not the component.
		$return_post_id = $post_id;
		$result         = $this->job_manager->resolve_translate_url( $translate_id, $target_lang, $return_post_id );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'Failed to get translate URL', array(
				'post_id'      => $post_id,
				'target_lang'  => $target_lang,
				'component_id' => $component_id,
				'error'        => $result->get_error_message(),
			) );
			return $result;
		}

		Logger::info( 'Generated ATE URL', array(
			'post_id'      => $post_id,
			'target_lang'  => $target_lang,
			'component_id' => $component_id,
			'job_id'       => $result['job_id'],
		) );

		return $result;
	}

	/**
	 * Resync translations for a post: re-register strings, cleanup,
	 * apply translations, and auto-complete where possible.
	 */
	public function handle_resync( int $post_id ): array|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'missing_params', 'Missing parameters', array( 'status' => 400 ) );
		}
		$error = $this->validate_post( $post_id );
		if ( $error ) { return $error; }

		// Flush pending saves so resync sees current content.
		$this->meta_sync->process_save_queue();

		$result = $this->resync_handler->resync( $post_id );

		// Refresh ATE jobs so they see the latest strings.
		$default_lang = apply_filters( 'wpml_default_language', null );
		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( is_array( $active_langs ) ) {
			foreach ( array_keys( $active_langs ) as $code ) {
				if ( $code !== $default_lang ) {
					$this->job_manager->refresh_job_for_post( $post_id, $code );
				}
			}
		}

		return $result;
	}

	/**
	 * Resync every Etch post on the site (manual "Resync All" button).
	 */
	public function handle_resync_all(): array {
		// Flush pending saves so the global pass sees current content.
		$this->meta_sync->process_save_queue();

		return $this->resync_handler->resync_all();
	}

	/**
	 * Read the persisted last-run state plus a fresh site-health snapshot.
	 *
	 * The dual-scope `{ local, global }` shape comes from the persisted
	 * option; `site_health` is recomputed on every call so the Force Sync
	 * tooltip always shows the current state of the site, not the state
	 * captured at the moment of the last run.
	 */
	public function handle_get_resync_status(): array {
		$status                = $this->resync_handler->get_last_run_status();
		$status['site_health'] = $this->resync_handler->get_site_health();
		return $status;
	}

	/**
	 * Scan icl_translation_status for half-state rows (status=10 with element_id=NULL)
	 * and invoke the healer on each. Intended for one-off repair of sites that
	 * accumulated orphan rows before v1.0.6.
	 *
	 * @param bool $dry_run When true, returns the candidate list without calling
	 *                      the healer. Useful for auditing.
	 * @param int  $trid    Optional filter: only scan this trid.
	 */
	public function handle_heal_half_states( bool $dry_run = false, int $trid = 0 ): array {
		global $wpdb;

		$where  = 'ts.status = ' . (int) TranslationDataQuery::ICL_TM_COMPLETE
			. ' AND t.source_language_code IS NOT NULL'
			. ' AND ( t.element_id IS NULL OR wp.ID IS NULL )';
		$params = array();
		if ( $trid > 0 ) {
			$where   .= ' AND t.trid = %d';
			$params[] = $trid;
		}

		$sql = "SELECT t.trid, t.language_code, t.translation_id, t.element_id
		        FROM {$wpdb->prefix}icl_translations t
		        JOIN {$wpdb->prefix}icl_translation_status ts ON ts.translation_id = t.translation_id
		        LEFT JOIN {$wpdb->posts} wp ON wp.ID = t.element_id
		        WHERE $where
		        ORDER BY t.trid, t.language_code";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );

		$candidates = array();
		foreach ( (array) $rows as $r ) {
			$candidates[] = array(
				'trid'           => (int) $r->trid,
				'language_code'  => $r->language_code,
				'translation_id' => (int) $r->translation_id,
				'element_id'     => $r->element_id === null ? null : (int) $r->element_id,
			);
		}

		if ( $dry_run ) {
			return array(
				'dry_run'    => true,
				'candidates' => $candidates,
				'count'      => count( $candidates ),
			);
		}

		$healed  = array();
		$failed  = array();
		foreach ( $candidates as $c ) {
			$result = $this->job_manager->heal_half_state( $c['trid'], $c['language_code'] );
			if ( $result ) {
				$healed[] = array_merge( $c, array( 'element_id' => $result ) );
			} else {
				$failed[] = $c;
			}
		}

		Logger::info( 'Backfill heal-half-states complete', array(
			'candidates' => count( $candidates ),
			'healed'     => count( $healed ),
			'failed'     => count( $failed ),
		) );

		return array(
			'dry_run' => false,
			'scanned' => count( $candidates ),
			'healed'  => $healed,
			'failed'  => $failed,
		);
	}

	/** Fetch all original wp_block posts with per-language translation status. */
	public function handle_get_all_components( int $post_id ): array|WP_Error {
		if ( ! $post_id ) {
			return new WP_Error( 'missing_params', 'Missing parameters', array( 'status' => 400 ) );
		}
		$error = $this->validate_post( $post_id );
		if ( $error ) { return $error; }

		global $wpdb;

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) ) {
			return new WP_Error( 'no_languages', 'No active languages', array( 'status' => 500 ) );
		}

		// Determine which components are used on the current page.
		$page_component_ids = array();
		$post               = get_post( $post_id );
		if ( $post ) {
			$blocks             = parse_blocks( $post->post_content );
			$page_component_ids = array_keys( $this->data_query->extract_component_refs( $blocks ) );
		}

		// Get all original wp_block posts (source_language_code IS NULL = original).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_blocks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID
				 WHERE p.post_type = %s
				   AND p.post_status = %s
				   AND t.element_type = %s
				   AND t.source_language_code IS NULL
				 ORDER BY p.post_title ASC",
				'wp_block',
				'publish',
				'post_wp_block'
			)
		);

		// Collect all trids first, then batch-fetch translation status maps.
		$block_trids = array();
		foreach ( (array) $all_blocks as $block ) {
			$cid  = (int) $block->ID;
			$trid = (int) apply_filters( 'wpml_element_trid', null, $cid, 'post_wp_block' );
			if ( $trid ) {
				$block_trids[ $cid ] = (int) $trid;
			}
		}

		$batch_lang_data = $this->status_resolver->resolve_batch_lang_data( $block_trids, 'post_wp_block', $active_langs );

		$components = array();
		foreach ( (array) $all_blocks as $block ) {
			$cid       = (int) $block->ID;
			$lang_data = $batch_lang_data[ $cid ] ?? array();
			if ( empty( $lang_data ) ) {
				continue;
			}

			$components[] = array(
				'id'           => $cid,
				'title'        => $block->post_title,
				'languages'    => $lang_data,
				'used_on_page' => in_array( $cid, $page_component_ids, true ),
				'etch_url'     => $this->etch_builder_url( $cid ),
			);
		}

		return $components;
	}

	/** Fetch posts of a given type with per-language translation status. Respects pill access gating. */
	public function handle_get_posts_by_type( string $post_type ): array|WP_Error {
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'invalid_post_type', 'Invalid post type', array( 'status' => 400 ) );
		}

		// Check pill access (gating).
		$access = $this->config->get_pill_access();
		if ( empty( $access[ $post_type ] ) ) {
			return new WP_Error( 'license_required', 'License required', array( 'status' => 403 ) );
		}

		global $wpdb;

		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) || ! is_array( $active_langs ) ) {
			return new WP_Error( 'no_languages', 'No active languages', array( 'status' => 500 ) );
		}

		$element_type = 'post_' . $post_type;
		$default_lang = apply_filters( 'wpml_default_language', null );

		// Get original posts in the default language only.
		// WPML may auto-duplicate posts per language, each with source_language_code = NULL
		// in its own trid. Filtering by the default language ensures we only get true originals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$base_query = "SELECT p.ID, p.post_title
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->prefix}icl_translations t
			   ON t.element_id = p.ID
			   AND t.element_type = %s
			 WHERE p.post_type = %s
			   AND p.post_status = 'publish'
			   AND t.source_language_code IS NULL
			   AND t.language_code = %s";

		$prepare_args = array( $element_type, $post_type, $default_lang );

		// For wp_template, only include posts with Etch blocks, exclude "home" template.
		if ( 'wp_template' === $post_type ) {
			$base_query   .= ' AND p.post_content LIKE %s AND p.post_name != %s';
			$prepare_args[] = '%<!-- wp:etch/%';
			$prepare_args[] = 'home';
		}

		$base_query .= ' ORDER BY p.post_title ASC';

		$posts = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built dynamically with prepare args.
			$wpdb->prepare( $base_query, ...$prepare_args )
		);

		// Collect trids first, then batch-fetch translation status maps.
		$items           = array();
		$processed_ids   = array();
		$processed_trids = array();
		$post_trids      = array();

		foreach ( (array) $posts as $post_row ) {
			$pid = (int) $post_row->ID;
			if ( in_array( $pid, $processed_ids, true ) ) {
				continue;
			}
			if ( ! current_user_can( 'read_post', $pid ) ) {
				continue;
			}
			$processed_ids[] = $pid;

			$trid = (int) apply_filters( 'wpml_element_trid', null, $pid, $element_type );
			if ( ! $trid || in_array( $trid, $processed_trids, true ) ) {
				continue;
			}
			$processed_trids[]  = $trid;
			$post_trids[ $pid ] = (int) $trid;
		}

		$batch_lang_data = $this->status_resolver->resolve_batch_lang_data( $post_trids, $element_type, $active_langs );

		foreach ( (array) $posts as $post_row ) {
			$pid       = (int) $post_row->ID;
			$lang_data = $batch_lang_data[ $pid ] ?? array();
			if ( empty( $lang_data ) ) {
				continue;
			}

			$items[] = array(
				'id'        => $pid,
				'title'     => $post_row->post_title,
				'languages' => $lang_data,
				'etch_url'  => $this->etch_builder_url( $pid ),
			);
		}

		Logger::debug( 'Posts by type fetched', array(
			'post_type'   => $post_type,
			'raw_count'   => count( $posts ),
			'final_count' => count( $items ),
		) );

		return $items;
	}

	/** Aggregate worst-status per content-type pill (single query for all unlocked pills). */
	public function handle_get_pill_statuses(): array {
		$active_langs = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		if ( empty( $active_langs ) || ! is_array( $active_langs ) ) {
			return array();
		}

		$pills      = $this->config->get_content_type_pills();
		$post_types = array();
		foreach ( $pills as $pill ) {
			if ( 'on-this-page' !== $pill['id'] && empty( $pill['locked'] ) ) {
				$post_types[] = $pill['id'];
			}
		}

		return $this->status_resolver->resolve_pill_statuses( $post_types, $active_langs );
	}

	public function handle_toggle_loop_preset(): array {
		return $this->config->handle_toggle_loop_preset();
	}
}

