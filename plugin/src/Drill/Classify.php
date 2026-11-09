<?php

namespace Rafoo\Drill;

use Rafoo\Assets\Fonts;
use Rafoo\Assets\Recognizer;
use Rafoo\Data;
use Rafoo\Http\Hosts;
use Rafoo\Services\Embeds;

defined( 'ABSPATH' ) || exit;

final class Classify {

	const RANK = array(
		'handled' => 0,
		'info'    => 1,
		'warning' => 2,
		'blocker' => 3,
	);

	const AVATAR = array( 'gravatar.com', '*.gravatar.com' );

	const SOCIAL = array( 'accounts.google.com', 'apis.google.com/js/platform', 'apis.google.com/js/api', 'appleid.cdn-apple.com', 'connect.facebook.net/en_us/sdk', 'platform.twitter.com', 'platform.linkedin.com', 'telegram.org/js/telegram-widget' );

	const CORE = array( 's.w.org' ); // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Host name to recognise WordPress emoji requests in the rehearsal report, nothing is loaded from it.

	const SETTINGS = array(
		'fonts'         => true,
		'embeds'        => true,
		'social_login'  => true,
		'core_assets'   => true,
		'browser_guard' => true,
		'html_scan'     => true,
		'mail_queue'    => true,
		'avatars'       => 'outage',
		'captcha'       => 'outage',
		'analytics'     => 'outage',
		'updates'       => 'outage',
		'replace'       => 'always',
	);

	const SLOW_PAGE = 4000;

	const MAX_ISSUES = 120;

	private $issues = array();

	private $context;

	private $stats = array(
		'local'    => 0,
		'handled'  => 0,
		'refused'  => 0,
		'embeds'   => 0,
		'requests' => 0,
		'foreign'  => 0,
	);

	private $local = array();

	public function __construct( array $context ) {
		$this->context = array_merge(
			array(
				'settings' => array(),
				'records'  => array(),
				'excluded' => array(),
				'manual'   => false,
				'owners'   => array(),
			),
			$context
		);
	}

	public static function report( array $pages, array $server, array $context ) {
		$self = new self( $context );
		return $self->build( $pages, $server );
	}

	public function build( array $pages, array $server ) {
		$by_page = array();
		foreach ( $server as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['p'] ) ) {
				continue;
			}
			$by_page[ (int) $record['p'] ][] = $record;
		}
		if ( $this->context['manual'] ) {
			$pages = $this->manual_pages( $pages, $by_page );
		}
		$out = array();
		foreach ( $pages as $id => $page ) {
			$out[] = $this->page( (int) $id, $page, isset( $by_page[ (int) $id ] ) ? $by_page[ (int) $id ] : array() );
		}
		$issues = array_values( $this->issues );
		foreach ( $issues as $index => $issue ) {
			$issues[ $index ]['fix']     = $this->fixes( $issue );
			$issues[ $index ]['origins'] = $this->origins( $issue );
			unset( $issues[ $index ]['from'] );
		}
		usort(
			$issues,
			static function ( $a, $b ) {
				$rank = self::RANK[ $b['sev'] ] - self::RANK[ $a['sev'] ];
				if ( 0 !== $rank ) {
					return $rank;
				}
				return count( $b['pages'] ) - count( $a['pages'] ) ? count( $b['pages'] ) - count( $a['pages'] ) : strcmp( $a['subject'], $b['subject'] );
			}
		);
		$this->stats['local'] = count( $this->local );
		foreach ( $issues as $issue ) {
			if ( 'handled' === $issue['sev'] ) {
				++$this->stats['handled'];
			}
		}
		return array(
			'pages'  => $out,
			'issues' => array_slice( $issues, 0, self::MAX_ISSUES ),
			'stats'  => $this->stats,
		);
	}

	public static function category( $url ) {
		$host = Hosts::of( $url );
		if ( '' === $host ) {
			return '';
		}
		if ( Recognizer::parse( $url ) ) {
			return 'library';
		}
		if ( Fonts::HOST === $host || Fonts::FILES === $host ) {
			return 'fonts';
		}
		if ( self::listed( $url, self::AVATAR ) ) {
			return 'avatar';
		}
		$lists = Data::get( 'hosts' );
		if ( self::listed( $url, isset( $lists['captcha_scripts'] ) ? (array) $lists['captcha_scripts'] : array() ) ) {
			return 'captcha';
		}
		if ( self::listed( $url, Embeds::NEEDLES ) ) {
			return 'embed';
		}
		if ( self::listed( $url, isset( $lists['analytics'] ) ? (array) $lists['analytics'] : array() ) ) {
			return 'analytics';
		}
		if ( self::listed( $url, self::SOCIAL ) ) {
			return 'social';
		}
		if ( self::listed( $url, self::CORE ) ) {
			return 'core';
		}
		return Hosts::foreign( $host ) ? 'foreign' : 'unknown';
	}

	public static function listed( $url, array $needles ) {
		$host = Hosts::of( $url );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		foreach ( $needles as $needle ) {
			$needle = strtolower( (string) $needle );
			$cut    = strpos( $needle, '/' );
			$base   = false === $cut ? $needle : substr( $needle, 0, $cut );
			$rest   = false === $cut ? '' : substr( $needle, $cut );
			if ( '' === $base ) {
				continue;
			}
			if ( '.' === substr( $base, -1 ) ) {
				$hit = 0 === strpos( $host, $base ) || 0 === strpos( $host, 'www.' . $base );
			} else {
				$hit = Hosts::matches( $host, 0 === strpos( $base, '*.' ) ? $base : '*.' . $base );
			}
			if ( $hit && ( '' === $rest || 0 === strpos( $path, $rest ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function owner_of_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( preg_match( '#/mu-plugins/([A-Za-z0-9._\-]+?)(?:\.php)?(?:/|$)#', $path, $m ) ) {
			return 'mu:' . sanitize_key( $m[1] );
		}
		if ( preg_match( '#/plugins/([A-Za-z0-9._\-]+)/#', $path, $m ) ) {
			return 'plugin:' . sanitize_key( $m[1] );
		}
		if ( preg_match( '#/themes/([A-Za-z0-9._\-]+)/#', $path, $m ) ) {
			return 'theme:' . sanitize_key( $m[1] );
		}
		if ( preg_match( '#/wp-(includes|admin)/#', $path ) ) {
			return 'core';
		}
		return '';
	}

	public static function score( array $issues, array $pages, array $readiness ) {
		$score = 100;
		$info  = 0;
		foreach ( $issues as $issue ) {
			if ( 'blocker' === $issue['sev'] ) {
				$score -= 12;
			} elseif ( 'warning' === $issue['sev'] ) {
				$score -= 4;
			} elseif ( 'info' === $issue['sev'] && $info < 5 ) {
				--$score;
				++$info;
			}
		}
		foreach ( $pages as $page ) {
			if ( 'broken' === $page['status'] ) {
				$score -= 8;
			}
		}
		foreach ( $readiness as $item ) {
			if ( ! empty( $item['ok'] ) ) {
				continue;
			}
			if ( 'blocker' === $item['sev'] ) {
				$score -= 15;
			} elseif ( 'warning' === $item['sev'] ) {
				$score -= 5;
			}
		}
		return max( 0, min( 100, $score ) );
	}

	public static function grade( $score ) {
		if ( $score >= 90 ) {
			return 'ready';
		}
		if ( $score >= 70 ) {
			return 'good';
		}
		return $score >= 40 ? 'weak' : 'poor';
	}

	private function manual_pages( array $pages, array &$by_page ) {
		$pages   = array();
		$paths   = array();
		$records = isset( $by_page[0] ) ? $by_page[0] : array();
		unset( $by_page[0] );
		$next = 1;
		foreach ( $records as $record ) {
			$path = isset( $record['u'] ) ? (string) $record['u'] : '/';
			$id   = isset( $paths[ $path ] ) ? $paths[ $path ] : 0;
			if ( ! $id ) {
				if ( 'page' !== ( isset( $record['k'] ) ? $record['k'] : '' ) || $next > 30 || preg_match( '#(\.(ico|txt|xml|png|jpe?g|gif|webp|svg|css|js|map|json)|/wp-cron\.php)(\?|$)#i', $path ) ) {
					continue;
				}
				$id             = $next++;
				$paths[ $path ] = $id;
				$pages[ $id ]   = array(
					'kind'   => 'visit',
					'url'    => $path,
					'admin'  => false !== strpos( $path, '/wp-admin/' ),
					'client' => null,
					'frame'  => 'manual',
				);
			}
			$by_page[ $id ][] = $record;
		}
		foreach ( isset( $this->context['beacons'] ) ? (array) $this->context['beacons'] : array() as $client ) {
			$path = (string) wp_parse_url( isset( $client['u'] ) ? $client['u'] : '', PHP_URL_PATH );
			$path = '' === $path ? '/' : $path;
			foreach ( $paths as $known => $id ) {
				if ( strtok( $known, '?' ) === $path && null === $pages[ $id ]['client'] ) {
					$pages[ $id ]['client'] = $client;
					break;
				}
			}
		}
		return $pages;
	}

	private function page( $id, array $page, array $records ) {
		$client = isset( $page['client'] ) && is_array( $page['client'] ) ? $page['client'] : null;
		$base   = isset( $page['base'] ) && is_array( $page['base'] ) ? $page['base'] : null;
		$main   = null;
		$code   = 0;
		$ms     = 0;
		$fatal  = false;
		foreach ( $records as $record ) {
			if ( 'page' === ( isset( $record['k'] ) ? $record['k'] : '' ) && null === $main ) {
				$main = $record;
				$code = (int) $record['c'];
				$ms   = (int) $record['ms'];
			}
			$this->server( $id, $record );
			if ( ! empty( $record['f'] ) ) {
				$fatal = true;
			}
		}
		if ( null === $main && $records ) {
			$code = (int) $records[0]['c'];
			$ms   = (int) $records[0]['ms'];
		}
		if ( $code >= 500 && ! $fatal ) {
			$this->add( 'http_error', 'blocker', 'http-' . $code . '-' . $id, $id, array( 'code' => $code ) );
		} elseif ( $code >= 400 && 'custom' !== ( isset( $page['kind'] ) ? $page['kind'] : '' ) ) {
			$this->add( 'http_error', 'warning', 'http-' . $code . '-' . $id, $id, array( 'code' => $code ) );
		}
		if ( $ms >= self::SLOW_PAGE ) {
			$this->add( 'slow_page', 'warning', 'slow-' . $id, $id, array( 'ms' => $ms ) );
		}
		if ( $client ) {
			$this->client( $id, $client, $base );
		}
		$cached = ! $this->context['manual'] && ! $records && $client && $code < 1;
		if ( $cached ) {
			$this->add( 'cached', 'info', 'cached-' . $id, $id, array() );
		}
		$frame = isset( $page['frame'] ) ? (string) $page['frame'] : 'ok';
		if ( ! $client && 'manual' !== $frame ) {
			$this->add( 'frame', 'info', 'frame-' . $id, $id, array( 'reason' => $frame ) );
		}
		$counts = array(
			'blocker' => 0,
			'warning' => 0,
			'info'    => 0,
			'handled' => 0,
		);
		foreach ( $this->issues as $issue ) {
			if ( in_array( $id, $issue['pages'], true ) ) {
				++$counts[ $issue['sev'] ];
			}
		}
		if ( $fatal || $code >= 500 || $counts['blocker'] > 0 ) {
			$status = 'broken';
		} elseif ( $counts['warning'] > 0 ) {
			$status = 'warning';
		} elseif ( ! $client && ! $records ) {
			$status = 'unknown';
		} else {
			$status = 'ok';
		}
		return array(
			'id'     => $id,
			'kind'   => isset( $page['kind'] ) ? (string) $page['kind'] : 'custom',
			'url'    => isset( $page['url'] ) ? (string) $page['url'] : '',
			'admin'  => ! empty( $page['admin'] ),
			'code'   => $code,
			'server' => $ms,
			'load'   => $client ? (int) $client['l'] : 0,
			'normal' => $base ? (int) ( isset( $base['l'] ) ? $base['l'] : 0 ) : 0,
			'frame'  => $frame,
			'fatal'  => $fatal,
			'status' => $status,
			'counts' => $counts,
		);
	}

	private function server( $id, array $record ) {
		foreach ( isset( $record['r'] ) ? (array) $record['r'] : array() as $request ) {
			if ( ! is_array( $request ) || empty( $request['h'] ) ) {
				continue;
			}
			++$this->stats['requests'];
			$host   = strtolower( (string) $request['h'] );
			$origin = isset( $request['o'] ) ? (string) $request['o'] : '';
			$state  = isset( $request['s'] ) ? (string) $request['s'] : '';
			if ( 'refused' === $state ) {
				++$this->stats['refused'];
				$this->add( 'server', 'handled', $host, $id, array( 'host' => $host ), $origin );
			} elseif ( 'trial' === $state ) {
				$this->add( 'server_unknown', 'warning', $host, $id, array( 'host' => $host, 'ms' => (int) $request['m'] ), $origin );
			} elseif ( 'failed' === $state ) {
				$this->add( 'server', 'info', $host, $id, array( 'host' => $host ), $origin );
			}
		}
		foreach ( isset( $record['a'] ) ? (array) $record['a'] : array() as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['k'] ) ) {
				continue;
			}
			$state = (string) $asset['s'];
			$key   = (string) $asset['k'];
			if ( 'local' === $state ) {
				$this->local[ $key ] = true;
			} elseif ( 'embed' === $state ) {
				++$this->stats['embeds'];
				$this->add( 'embed', 'handled', Hosts::of( $key ), $id, array( 'host' => Hosts::of( $key ) ) );
			} elseif ( 'dropped' === $state ) {
				$font = Fonts::parse( $key );
				$this->add( 'fonts', 'handled', $font ? $font['key'] : $key, $id, array( 'url' => $font ? $font['origin'] : $key ) );
			} elseif ( 'missing' === $state && 'font' !== ( isset( $asset['t'] ) ? $asset['t'] : '' ) ) {
				$asset_info = Recognizer::parse( $key );
				if ( $asset_info ) {
					$this->library( $id, $asset_info, 'warning', $key );
				}
			}
		}
		if ( ! empty( $record['f'] ) && is_array( $record['f'] ) ) {
			$fatal = $record['f'];
			$this->add(
				'php_error',
				'blocker',
				substr( md5( (string) $fatal['m'] . (string) $fatal['f'] ), 0, 12 ),
				$id,
				array(
					'message' => (string) $fatal['m'],
					'file'    => (string) $fatal['f'],
					'line'    => (int) $fatal['l'],
				),
				(string) $fatal['o']
			);
		}
	}

	private function client( $id, array $client, $base ) {
		foreach ( (array) $client['r'] as $resource ) {
			$this->resource( $id, $resource['u'], ! empty( $resource['b'] ), $resource['i'], false );
		}
		foreach ( (array) $client['x'] as $url ) {
			$this->resource( $id, $url, false, 'other', true );
		}
		$known = array();
		if ( $base && ! empty( $base['e'] ) ) {
			foreach ( (array) $base['e'] as $error ) {
				$known[ self::error_key( $error ) ] = true;
			}
		}
		foreach ( (array) $client['e'] as $error ) {
			$key    = self::error_key( $error );
			$origin = self::owner_of_url( $error['f'] );
			$this->add(
				'js_error',
				isset( $known[ $key ] ) ? 'info' : 'warning',
				$key,
				$id,
				array(
					'message' => $error['m'],
					'file'    => $error['f'],
					'line'    => (int) $error['l'],
					'normal'  => isset( $known[ $key ] ),
				),
				$origin
			);
		}
	}

	private function resource( $id, $url, $blocking, $initiator, $blocked ) {
		$host = Hosts::of( $url );
		if ( '' === $host || Hosts::allowed( $host ) ) {
			return;
		}
		++$this->stats['foreign'];
		$category = self::category( $url );
		if ( 'library' === $category ) {
			$asset = Recognizer::parse( $url );
			$this->library( $id, $asset, $blocked ? 'warning' : ( $blocking ? 'blocker' : 'warning' ), $url );
			return;
		}
		if ( $blocked ) {
			$severity = 'captcha' === $category ? 'blocker' : 'handled';
		} elseif ( $blocking ) {
			$severity = 'unknown' === $category ? 'warning' : 'blocker';
		} elseif ( 'unknown' === $category || 'analytics' === $category ) {
			$severity = 'unknown' === $category ? 'info' : 'warning';
		} else {
			$severity = 'warning';
		}
		$subject = $host;
		$meta    = array(
			'host'      => $host,
			'initiator' => $initiator,
			'blocking'  => $blocking,
			'blocked'   => $blocked,
		);
		if ( 'fonts' === $category ) {
			$font = Fonts::parse( $url );
			if ( $font ) {
				$subject     = $font['key'];
				$meta['url'] = $font['origin'];
			}
		}
		$this->add( $category, $severity, $subject, $id, $meta );
	}

	private function library( $id, array $asset, $severity, $url ) {
		$records = $this->context['records'];
		$record  = isset( $records[ $asset['key'] ] ) ? $records[ $asset['key'] ] : null;
		$status  = $record ? (string) $record['status'] : 'new';
		if ( $record && in_array( $record['origin'], (array) $this->context['excluded'], true ) ) {
			$status = 'excluded';
		}
		$this->add(
			'library',
			$severity,
			$asset['key'],
			$id,
			array(
				'url'     => $asset['origin'],
				'package' => isset( $asset['pkg'] ) ? (string) $asset['pkg'] : '',
				'version' => isset( $asset['ver'] ) ? (string) $asset['ver'] : '',
				'cdn'     => isset( $asset['cdn'] ) ? (string) $asset['cdn'] : '',
				'file'    => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				'status'  => $status,
				'error'   => $record ? (string) $record['error'] : '',
				'host'    => Hosts::of( $asset['origin'] ),
			)
		);
	}

	private function add( $category, $severity, $subject, $page, array $meta, $origin = '' ) {
		$key = $category . '|' . $subject;
		if ( ! isset( $this->issues[ $key ] ) ) {
			if ( count( $this->issues ) >= self::MAX_ISSUES * 2 ) {
				return;
			}
			$this->issues[ $key ] = array(
				'id'      => substr( md5( $key ), 0, 12 ),
				'cat'     => $category,
				'sev'     => $severity,
				'subject' => (string) $subject,
				'pages'   => array(),
				'count'   => 0,
				'meta'    => $meta,
				'from'    => array(),
			);
		}
		$issue = &$this->issues[ $key ];
		if ( self::RANK[ $severity ] > self::RANK[ $issue['sev'] ] ) {
			$issue['sev'] = $severity;
		}
		if ( ! in_array( $page, $issue['pages'], true ) ) {
			$issue['pages'][] = $page;
		}
		++$issue['count'];
		foreach ( array( 'blocking', 'normal' ) as $flag ) {
			if ( isset( $meta[ $flag ] ) && $meta[ $flag ] && empty( $issue['meta'][ $flag ] ) ) {
				$issue['meta'][ $flag ] = true;
			}
		}
		if ( isset( $meta['blocked'] ) && ! $meta['blocked'] ) {
			$issue['meta']['blocked'] = false;
		}
		if ( isset( $meta['ms'] ) ) {
			$issue['meta']['ms'] = max( isset( $issue['meta']['ms'] ) ? (int) $issue['meta']['ms'] : 0, (int) $meta['ms'] );
		}
		if ( '' !== $origin && ! in_array( $origin, $issue['from'], true ) && count( $issue['from'] ) < 6 ) {
			$issue['from'][] = $origin;
		}
		unset( $issue );
	}

	private function fixes( array $issue ) {
		$settings = $this->context['settings'];
		$fix      = array();
		$setting  = function ( $key ) use ( $settings, &$fix ) {
			$want = self::SETTINGS[ $key ];
			$have = isset( $settings[ $key ] ) ? $settings[ $key ] : null;
			if ( true === $want ? true !== $have : 'off' === $have ) {
				$fix[] = array(
					'a' => 'setting',
					't' => $key,
				);
			}
		};
		$host     = isset( $issue['meta']['host'] ) ? (string) $issue['meta']['host'] : '';
		$client   = in_array( $issue['cat'], array( 'library', 'fonts', 'avatar', 'embed', 'captcha', 'analytics', 'social', 'core', 'foreign', 'unknown' ), true );
		switch ( $issue['cat'] ) {
			case 'library':
				if ( isset( $settings['replace'] ) && 'off' === $settings['replace'] ) {
					$setting( 'replace' );
				}
				if ( isset( $settings['html_scan'] ) && ! $settings['html_scan'] ) {
					$setting( 'html_scan' );
				}
				if ( 'excluded' === $issue['meta']['status'] ) {
					$fix[] = array(
						'a' => 'include',
						't' => $issue['subject'],
					);
				} elseif ( 'ready' !== $issue['meta']['status'] ) {
					$fix[] = array(
						'a' => 'download',
						't' => 'new' === $issue['meta']['status'] ? $issue['meta']['url'] : $issue['subject'],
					);
				}
				break;
			case 'fonts':
				$setting( 'fonts' );
				if ( ! empty( $issue['meta']['url'] ) && ! $fix ) {
					$fix[] = array(
						'a' => 'download',
						't' => $issue['meta']['url'],
					);
				}
				break;
			case 'avatar':
				$setting( 'avatars' );
				break;
			case 'embed':
				$setting( 'embeds' );
				break;
			case 'captcha':
				$setting( 'captcha' );
				break;
			case 'analytics':
				$setting( 'analytics' );
				break;
			case 'social':
				$setting( 'social_login' );
				break;
			case 'core':
				$setting( 'core_assets' );
				break;
		}
		if ( $client && 'handled' !== $issue['sev'] && isset( $settings['browser_guard'] ) && ! $settings['browser_guard'] ) {
			$setting( 'browser_guard' );
		}
		$blockable = in_array( $issue['cat'], array( 'foreign', 'unknown', 'server_unknown', 'social', 'analytics', 'avatar', 'core' ), true ) || ( 'server' === $issue['cat'] && 'handled' !== $issue['sev'] );
		if ( '' !== $host && $blockable && 'handled' !== $issue['sev'] && ! $this->blocked( $host ) ) {
			$fix[] = array(
				'a' => 'block',
				't' => $host,
			);
		}
		if ( '' !== $host && in_array( $issue['cat'], array( 'unknown', 'server_unknown' ), true ) ) {
			$fix[] = array(
				'a' => 'allow',
				't' => $host,
			);
		}
		return $fix;
	}

	private function origins( array $issue ) {
		$from   = $issue['from'];
		$key    = $issue['cat'] . '|' . $issue['subject'];
		$owners = $this->context['owners'];
		if ( isset( $owners[ $key ] ) ) {
			foreach ( (array) $owners[ $key ] as $owner ) {
				if ( ! in_array( $owner, $from, true ) && count( $from ) < 6 ) {
					$from[] = $owner;
				}
			}
		}
		return array_values( $from );
	}

	private function blocked( $host ) {
		foreach ( isset( $this->context['settings']['block_hosts'] ) ? (array) $this->context['settings']['block_hosts'] : array() as $rule ) {
			if ( false === strpos( $rule, '/' ) && Hosts::matches( $host, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	private static function error_key( array $error ) {
		$message = preg_replace( '/\d+/', '0', strtolower( (string) $error['m'] ) );
		return substr( md5( $message . '|' . strtok( (string) $error['f'], '?' ) ), 0, 12 );
	}
}
