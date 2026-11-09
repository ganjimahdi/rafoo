<?php

namespace Rafoo;

use Rafoo\Admin\AdminBar;
use Rafoo\Admin\Page;
use Rafoo\Admin\Rest;
use Rafoo\Admin\SiteHealth;
use Rafoo\Assets\Browser;
use Rafoo\Assets\Library;
use Rafoo\Assets\Rewriter;
use Rafoo\Backup\Api;
use Rafoo\Backup\Job;
use Rafoo\Backup\Schedule;
use Rafoo\Backup\Transfer;
use Rafoo\Captcha\Captcha;
use Rafoo\Assets\Scanner;
use Rafoo\Assets\Store;
use Rafoo\Assets\Sync;
use Rafoo\Cache\Purge;
use Rafoo\Cli\Command;
use Rafoo\Connectivity\Monitor;
use Rafoo\Http\Breaker;
use Rafoo\Http\Guard;
use Rafoo\Http\Log;
use Rafoo\Mail\Queue;
use Rafoo\Report\Telemetry;
use Rafoo\Safety\Rollback;
use Rafoo\Services\Avatars;
use Rafoo\Services\Core;
use Rafoo\Services\Embeds;
use Rafoo\Services\Heartbeat;
use Rafoo\Services\SocialLogin;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const MINUTE = 'rafoo_minute';

	const DAILY = 'rafoo_daily';

	const STANDARD = 'rafoo/rafoo.php';

	private static $instance = null;

	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function complete() {
		return 'complete' === RAFOO_EDITION && is_readable( __DIR__ . '/Updates/Updates.php' );
	}

	public static function self_updates() {
		return is_readable( __DIR__ . '/SelfUpdate/Release.php' );
	}

	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'plugins_loaded', array( Geo\Edge::class, 'boot' ), PHP_INT_MIN );
		Geo\Seo::boot();
		if ( Geo\Store::is_primary() ) {
			Geo\Changes::start();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks whether this is a signed reachability ping; Engine::serve validates it.
		if ( isset( $_GET[ Peers\Engine::QUERY ] ) ) {
			add_action( 'plugins_loaded', array( Peers\Engine::class, 'serve' ), 0 );
		}

		if ( ! Settings::paused() ) {
			Drill\Session::boot();
		}

		$hooks = Settings::paused() ? self::hooks() : array_merge( self::hooks(), self::changes() );
		foreach ( $hooks as $hook ) {
			add_filter( $hook[0], $hook[1], isset( $hook[2] ) ? $hook[2] : 10, isset( $hook[3] ) ? $hook[3] : 1 );
		}

		if ( is_admin() ) {
			add_action( 'admin_menu', array( Page::class, 'menu' ) );
			add_action( 'admin_enqueue_scripts', array( Page::class, 'assets' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( RAFOO_FILE ), array( Page::class, 'links' ) );
			if ( is_multisite() ) {
				add_action( 'network_admin_menu', array( Page::class, 'network_menu' ) );
				add_filter( 'network_admin_plugin_action_links_' . plugin_basename( RAFOO_FILE ), array( Page::class, 'network_links' ) );
			}
			add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
			add_action( 'admin_init', array( __CLASS__, 'setup_redirect' ), 1 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Command::register();
		}
		if ( self::self_updates() ) {
			SelfUpdate\Release::register();
		}
		if ( self::complete() && ! Settings::paused() ) {
			Updates\Updates::register();
			add_action( 'admin_init', array( __CLASS__, 'retire_standard' ) );
		}
	}

	public static function hooks() {
		return array(
			array( 'http_api_debug', array( Guard::class, 'observe' ), 10, 5 ),
			array( 'http_request_args', array( Log::class, 'stamp' ), 100, 2 ),
			array( Queue::HOOK, array( Queue::class, 'run' ), 10, 0 ),
			array( Schedule::HOOK, array( Schedule::class, 'run' ), 10, 0 ),
			array( Job::HOOK, array( Job::class, 'cron' ), 10, 0 ),
			array( 'wp_ajax_' . Job::ACTION, array( Job::class, 'endpoint' ), 10, 0 ),
			array( 'wp_ajax_nopriv_' . Job::ACTION, array( Job::class, 'endpoint' ), 10, 0 ),
			array( 'wp_ajax_' . Transfer::ACTION, array( Transfer::class, 'endpoint' ), 10, 0 ),
			array( 'wp_ajax_nopriv_' . Transfer::ACTION, array( Transfer::class, 'endpoint' ), 10, 0 ),
			array( 'admin_post_' . Api::DOWNLOAD, array( Api::class, 'download' ), 10, 0 ),
			array( 'rafoo_rest_routes', array( Api::class, 'routes' ) ),
			array( 'rafoo_rest_routes', array( Geo\Api::class, 'routes' ) ),
			array( 'rafoo_rest_routes', array( Drill\Api::class, 'routes' ) ),
			array( 'cron_request', array( Geo\Edge::class, 'cron_request' ), 99 ),
			array( 'rafoo_loopback_url', array( Geo\Edge::class, 'loopback' ) ),
			array( 'wp_ajax_' . Geo\Link::ACTION, array( Geo\Link::class, 'endpoint' ), 10, 0 ),
			array( 'wp_ajax_nopriv_' . Geo\Link::ACTION, array( Geo\Link::class, 'endpoint' ), 10, 0 ),
			array( Geo\Health::HOOK, array( Geo\Health::class, 'tick' ), 10, 0 ),
			array( Geo\Planner::HOOK, array( Geo\Planner::class, 'scheduled' ), 10, 0 ),
			array( Geo\Lister::HOOK, array( Geo\Lister::class, 'run' ), 10, 0 ),
			array( Geo\Changes::POKE, array( Geo\Changes::class, 'scheduled_poke' ), 10, 0 ),
			array( 'rafoo_settings_changed', array( Schedule::class, 'changed' ), 10, 2 ),
			array( 'woocommerce_checkout_process', array( Transfer::class, 'block_checkout' ), 1, 0 ),
			array( 'rest_request_before_callbacks', array( Transfer::class, 'block_store' ), 9, 3 ),
			array( 'admin_bar_menu', array( AdminBar::class, 'node' ), 100 ),
			array( 'site_status_tests', array( SiteHealth::class, 'tests' ) ),
			array( 'debug_information', array( SiteHealth::class, 'info' ) ),
			array( 'rafoo_mode_changed', array( Purge::class, 'later' ) ),
			array( 'rafoo_map_changed', array( Purge::class, 'later' ) ),
			array( 'rafoo_mode_changed', array( __CLASS__, 'mode_changed' ) ),
			array( 'cron_schedules', array( __CLASS__, 'schedules' ) ),
			array( 'init', array( __CLASS__, 'init' ) ),
			array( 'rest_api_init', array( Rest::class, 'register' ) ),
			array( 'rafoo_check', array( __CLASS__, 'check' ) ),
			array( 'rafoo_sync', array( __CLASS__, 'sync' ) ),
			array( 'rafoo_scan', array( __CLASS__, 'scan' ) ),
			array( self::DAILY, array( __CLASS__, 'daily' ) ),
		);
	}

	public static function changes() {
		return array(
			array( 'http_request_args', array( Guard::class, 'args' ), 99, 2 ),
			array( 'pre_http_request', array( Guard::class, 'filter' ), 5, 3 ),
			array( 'heartbeat_settings', array( Heartbeat::class, 'settings' ), 99 ),
			array( 'wp_head', array( Browser::class, 'head' ), -1000, 0 ),
			array( 'admin_print_styles', array( Browser::class, 'output' ), -1000, 0 ),
			array( 'login_enqueue_scripts', array( Browser::class, 'output' ), -1000, 0 ),
			array( 'customize_controls_print_styles', array( Browser::class, 'output' ), -1000, 0 ),
			array( 'elementor/editor/wp_head', array( Browser::class, 'output' ), -1000, 0 ),
			array( 'script_loader_src', array( Rewriter::class, 'script_src' ), 999 ),
			array( 'style_loader_src', array( Rewriter::class, 'style_src' ), 999 ),
			array( 'script_loader_tag', array( Rewriter::class, 'script_tag' ), 999, 3 ),
			array( 'style_loader_tag', array( Rewriter::class, 'style_tag' ), 999, 3 ),
			array( 'wp_resource_hints', array( Rewriter::class, 'hints' ), 999, 2 ),
			array( 'template_redirect', array( Rewriter::class, 'start_buffer' ), PHP_INT_MAX - 10 ),
			array( 'pre_get_avatar_data', array( Avatars::class, 'data' ), 20, 2 ),
			array( 'the_content', array( Embeds::class, 'content' ), 99 ),
			array( 'widget_text_content', array( Embeds::class, 'content' ), 99 ),
			array( 'widget_block_content', array( Embeds::class, 'content' ), 99 ),
			array( 'embed_oembed_html', array( Embeds::class, 'content' ), 99 ),
			array( 'init', array( Core::class, 'init' ), 20 ),
			array( 'should_load_remote_block_patterns', array( Core::class, 'patterns' ) ),
			array( 'block_editor_settings_all', array( Core::class, 'editor' ) ),
			array( 'wp_dashboard_setup', array( Core::class, 'dashboard' ) ),
			array( 'wp_network_dashboard_setup', array( Core::class, 'dashboard' ) ),
			array( 'wp_enqueue_scripts', array( SocialLogin::class, 'style' ) ),
			array( 'login_enqueue_scripts', array( SocialLogin::class, 'style' ) ),
			array( 'wp_mail', array( Queue::class, 'remember' ), PHP_INT_MAX ),
			array( 'pre_wp_mail', array( Queue::class, 'pre' ), 10, 2 ),
			array( 'phpmailer_init', array( Queue::class, 'mailer' ), PHP_INT_MAX ),
			array( 'wp_mail_failed', array( Queue::class, 'failed' ) ),
			array( 'wp_mail_succeeded', array( Queue::class, 'sent' ) ),
			array( 'upgrader_pre_install', array( Rollback::class, 'before' ), 5, 2 ),
			array( 'upgrader_process_complete', array( Rollback::class, 'after' ), 20, 2 ),
			array( 'load-plugins.php', array( Rollback::class, 'screens' ), 10, 0 ),
			array( 'load-themes.php', array( Rollback::class, 'screens' ), 10, 0 ),
			array( 'load-update-core.php', array( Rollback::class, 'screens' ), 10, 0 ),
			array( 'wp_ajax_' . Captcha::ACTION, array( Captcha::class, 'endpoint' ), 10, 0 ),
			array( 'wp_ajax_nopriv_' . Captcha::ACTION, array( Captcha::class, 'endpoint' ), 10, 0 ),
			array( 'wp_enqueue_scripts', array( Captcha::class, 'assets' ), 10, 0 ),
			array( 'login_enqueue_scripts', array( Captcha::class, 'assets' ), 10, 0 ),
			array( 'login_form', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'register_form', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'lostpassword_form', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'signup_extra_fields', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'wpmu_validate_user_signup', array( Captcha::class, 'signup' ), 99 ),
			array( 'comment_form_after_fields', array( Captcha::class, 'render_comment' ), 10, 0 ),
			array( 'woocommerce_login_form', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'woocommerce_register_form', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'woocommerce_lostpassword_form', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'woocommerce_checkout_after_customer_details', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'wpforms_display_submit_before', array( Captcha::class, 'render' ), 10, 0 ),
			array( 'wpcf7_form_elements', array( Captcha::class, 'cf7' ) ),
			array( 'authenticate', array( Captcha::class, 'authenticate' ), 99 ),
			array( 'registration_errors', array( Captcha::class, 'registration' ), 99 ),
			array( 'lostpassword_post', array( Captcha::class, 'lost_password' ) ),
			array( 'pre_comment_on_post', array( Captcha::class, 'comment_started' ), 10, 0 ),
			array( 'pre_comment_approved', array( Captcha::class, 'comment' ), 99 ),
			array( 'woocommerce_process_login_errors', array( Captcha::class, 'woo_errors' ) ),
			array( 'woocommerce_process_registration_errors', array( Captcha::class, 'woo_errors' ) ),
			array( 'woocommerce_after_checkout_validation', array( Captcha::class, 'woo_checkout' ), 10, 2 ),
			array( 'rest_request_before_callbacks', array( Captcha::class, 'store_api' ), 10, 3 ),
			array( 'wpcf7_spam', array( Captcha::class, 'cf7_spam' ), 8, 2 ),
			array( 'wpforms_process_before', array( Captcha::class, 'wpforms' ), 10, 2 ),
			array( 'pre_http_request', array( Captcha::class, 'siteverify' ), 4, 3 ),
			array( 'script_loader_tag', array( Captcha::class, 'script_tag' ), 1000, 3 ),
		);
	}

	public static function setup_redirect() {
		$user = get_transient( 'rafoo_setup_redirect' );
		if ( false === $user ) {
			return;
		}
		if ( wp_doing_ajax() || (int) $user !== get_current_user_id() || null !== filter_input( INPUT_GET, 'activate-multi' ) || ! current_user_can( 'manage_options' ) || Network::enforced() ) {
			return;
		}
		delete_transient( 'rafoo_setup_redirect' );
		wp_safe_redirect( Admin\Page::url() . '#/setup' );
		exit;
	}

	public static function privacy() {
		if ( isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], array( 'options-privacy.php', 'privacy-policy-guide.php' ), true ) ) {
			Privacy::policy();
		}
	}

	public static function init() {
		load_plugin_textdomain( 'rafoo', false, dirname( plugin_basename( RAFOO_FILE ) ) . '/languages' );
		Network::sync();
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::schedule();
		}
	}

	public static function schedules( $schedules ) {
		$schedules[ self::MINUTE ] = array(
			'interval' => 60,
			'display'  => 'Rafoo: every minute',
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'rafoo_check' ) ) {
			wp_schedule_event( time() + 30, self::MINUTE, 'rafoo_check' );
		}
		if ( ! wp_next_scheduled( 'rafoo_sync' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'rafoo_sync' );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::DAILY );
		}
		if ( Geo\Store::active() && ! wp_next_scheduled( Geo\Health::HOOK ) ) {
			wp_schedule_event( time() + 60, self::MINUTE, Geo\Health::HOOK );
		}
	}

	public static function check() {
		Monitor::run();
		Peers\Engine::tick();
	}

	public static function sync() {
		$result = Sync::run();
		if ( ! empty( $result['remaining'] ) ) {
			Library::schedule_soon();
		}
	}

	public static function scan() {
		Scanner::run();
		Library::schedule_soon();
	}

	public static function daily() {
		Sync::verify();
		Telemetry::maybe_report();
	}

	public static function mode_changed( $offline ) {
		Breaker::reset();
		if ( ! $offline ) {
			Library::schedule_soon();
			Queue::soon();
		}
	}

	public static function activate() {
		Store::ensure();
		self::schedule();
		if ( ! wp_next_scheduled( 'rafoo_scan' ) ) {
			wp_schedule_single_event( time() + 20, 'rafoo_scan' );
		}
		Queue::soon();
		Schedule::plan();
		State::event( 'activated', array( 'edition' => RAFOO_EDITION ) );
		$network = func_num_args() > 0 && func_get_arg( 0 );
		if ( ! $network && ! ( defined( 'WP_CLI' ) && WP_CLI ) && '' === Settings::get( 'setup' ) ) {
			set_transient( 'rafoo_setup_redirect', get_current_user_id(), 60 );
		}
		Telemetry::ping( 'activate' );
		if ( 'complete' === RAFOO_EDITION ) {
			self::retire_standard();
		}
	}

	public static function deactivate() {
		foreach ( array( 'rafoo_check', 'rafoo_sync', 'rafoo_scan', self::DAILY, Queue::HOOK, Schedule::HOOK, Job::HOOK, Geo\Health::HOOK, Geo\Planner::HOOK, Geo\Lister::HOOK, Geo\Changes::POKE ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		delete_site_transient( Monitor::LOCK );
		Telemetry::ping( 'deactivate' );
		if ( Peers\Keys::member() ) {
			Peers\Engine::leave();
		}
	}

	public static function retire_standard() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( self::STANDARD ) ) {
			deactivate_plugins( self::STANDARD, true, is_plugin_active_for_network( self::STANDARD ) );
		}
	}
}
