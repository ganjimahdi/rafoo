<?php

namespace Rafoo\Assets;

use Rafoo\Captcha\Captcha;
use Rafoo\Http\Hosts;
use Rafoo\Services\Analytics;
use Rafoo\Services\Embeds;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Rewriter {

	const TAG = '#<(script|link)\b[^>]*>#i';

	const HINT = '#<link\b[^>]*\brel\s*=\s*["\']?(?:preconnect|dns-prefetch)["\']?[^>]*>#i';

	const REL = '#\brel\s*=\s*["\']?([a-z\- ]+)#i';

	public static function script_src( $src ) {
		return self::swap( $src );
	}

	public static function style_src( $src ) {
		if ( ! self::external( $src ) ) {
			return $src;
		}
		if ( false !== strpos( $src, Fonts::HOST ) ) {
			return self::font( $src );
		}
		return self::swap( $src );
	}

	public static function script_tag( $tag, $handle, $src ) {
		if ( self::external( $src ) && Hosts::blocked( $src ) ) {
			return '';
		}
		self::learn_integrity( $tag, $src );
		return $tag;
	}

	public static function style_tag( $tag, $handle, $href ) {
		if ( self::external( $href ) && Hosts::blocked( $href ) ) {
			return '';
		}
		if ( ! self::external( $href ) ) {
			return $tag;
		}
		if ( false !== strpos( $href, Fonts::HOST ) && Fonts::enabled() && State::offline() ) {
			return '';
		}
		self::learn_integrity( $tag, $href );
		return $tag;
	}

	public static function swap( $src ) {
		if ( ! self::external( $src ) ) {
			return $src;
		}
		$asset = Recognizer::parse( $src );
		if ( ! $asset ) {
			return $src;
		}
		$local = Library::local_url( $asset['key'] );
		if ( '' !== $local ) {
			return $local;
		}
		if ( 'off' !== Settings::get( 'replace' ) ) {
			Library::note( $asset );
		}
		return $src;
	}

	public static function font( $href ) {
		if ( ! Fonts::enabled() ) {
			return $href;
		}
		$font = Fonts::parse( $href );
		if ( ! $font ) {
			return $href;
		}
		$local = Fonts::local_url( $font['key'] );
		if ( '' !== $local ) {
			return $local;
		}
		Library::note( $font, '', 'font' );
		return $href;
	}

	public static function hints( $urls, $relation ) {
		if ( ! in_array( $relation, array( 'dns-prefetch', 'preconnect' ), true ) || ! is_array( $urls ) ) {
			return $urls;
		}
		$needed = null;
		$out    = array();
		foreach ( $urls as $entry ) {
			$href = is_array( $entry ) ? ( isset( $entry['href'] ) ? $entry['href'] : '' ) : $entry;
			$host = strtolower( (string) wp_parse_url( ( 0 === strpos( $href, '//' ) ? 'https:' : '' ) . $href, PHP_URL_HOST ) );
			if ( '' === $host ) {
				$host = strtolower( preg_replace( '#[/:].*$#', '', ltrim( $href, '/' ) ) );
			}
			if ( '' !== $host && Hosts::blocked( 'https://' . $host . '/' ) ) {
				continue;
			}
			if ( self::managed_host( $host ) ) {
				$needed = null === $needed ? self::needed_hosts() : $needed;
				if ( ! isset( $needed[ $host ] ) ) {
					continue;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	public static function start_buffer() {
		if ( ! ( Settings::get( 'html_scan' ) || Analytics::active() || Captcha::replacing() || Settings::get( 'block_hosts' ) || Browser::active() ) || ! self::bufferable() ) {
			return;
		}
		Browser::buffering();
		ob_start( array( __CLASS__, 'buffer' ) );
	}

	public static function buffer( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 64 || ! self::is_html_response() ) {
			return $html;
		}
		if ( Analytics::active() ) {
			$html = Analytics::strip( $html );
		}
		if ( Captcha::replacing() ) {
			$html = Captcha::strip( $html );
		}
		if ( Settings::get( 'block_hosts' ) ) {
			$html = self::strip_blocked( $html );
		}
		if ( Settings::get( 'html_scan' ) ) {
			$html = self::rewrite( $html );
		}
		return Browser::active() ? Browser::inject( $html ) : $html;
	}

	public static function strip_blocked( $html ) {
		$out = preg_replace_callback(
			'#<script\b([^>]*)>\s*</script\s*>#is',
			static function ( $m ) {
				return self::blocked_attr( $m[1], 'src' ) ? '' : $m[0];
			},
			$html
		);
		$out = null === $out ? $html : $out;
		$out = preg_replace_callback(
			'#<link\b[^>]*>#i',
			static function ( $m ) {
				return self::blocked_attr( $m[0], 'href' ) ? '' : $m[0];
			},
			$out
		);
		$out = null === $out ? $html : $out;
		$res = preg_replace_callback(
			'#<iframe\b[^>]*>#i',
			static function ( $m ) {
				if ( ! preg_match( '#\bsrc\s*=\s*(["\'])([^"\']+)\1#i', $m[0], $src, PREG_OFFSET_CAPTURE ) || ! Hosts::blocked( html_entity_decode( $src[2][0] ) ) ) {
					return $m[0];
				}
				$tag = substr_replace( $m[0], 'about:blank', $src[2][1], strlen( $src[2][0] ) );
				return preg_replace( '#^<iframe\b#i', '<iframe data-rafoo-blocked="' . esc_attr( $src[2][0] ) . '"', $tag );
			},
			$out
		);
		return null === $res ? $out : $res;
	}

	private static function blocked_attr( $attributes, $name ) {
		if ( ! preg_match( '#\b' . $name . '\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $attributes, $m ) ) {
			return false;
		}
		return Hosts::blocked( html_entity_decode( $m[2] ) );
	}

	private static function rewrite( $html ) {
		$offline = State::offline();
		$embeds  = $offline && Embeds::enabled();
		if ( ! self::mentions_managed( $html ) && ! ( $embeds && Embeds::mentions( $html ) ) ) {
			return $html;
		}
		$rewritten = preg_replace_callback( self::TAG, array( __CLASS__, 'rewrite_tag' ), $html );
		if ( null === $rewritten ) {
			return $html;
		}
		$rewritten = self::rewrite_imports( $rewritten );
		if ( $embeds ) {
			$rewritten = Embeds::replace( $rewritten );
		}
		return self::prune_hints( $rewritten );
	}

	public static function rewrite_tag( $m ) {
		$tag  = $m[0];
		$name = strtolower( $m[1] );
		$attr = 'script' === $name ? 'src' : 'href';
		if ( ! preg_match( '#\b' . $attr . '\s*=\s*(["\'])([^"\']+)\1#i', $tag, $found, PREG_OFFSET_CAPTURE ) ) {
			return $tag;
		}
		$url = $found[2][0];
		if ( false === strpos( $url, '//' ) ) {
			return $tag;
		}
		$rel = '';
		if ( 'link' === $name ) {
			$rel = preg_match( self::REL, $tag, $r ) ? strtolower( trim( $r[1] ) ) : '';
			if ( ! preg_match( '/\b(stylesheet|preload|modulepreload|prefetch)\b/', $rel ) ) {
				return $tag;
			}
		}

		if ( false !== strpos( $url, Fonts::HOST ) ) {
			if ( ! Fonts::enabled() ) {
				return $tag;
			}
			$local = self::font( $url );
			if ( $local !== $url ) {
				return self::replace_at( $tag, $found[2][1], $url, $local );
			}
			return State::offline() ? '' : $tag;
		}

		if ( false !== strpos( $url, Fonts::FILES ) && 'link' === $name && State::offline() ) {
			return '';
		}

		$asset = Recognizer::parse( $url );
		if ( ! $asset ) {
			return $tag;
		}
		$local = Library::local_url( $asset['key'] );
		if ( '' !== $local ) {
			return self::replace_at( $tag, $found[2][1], $url, $local );
		}
		if ( 'off' !== Settings::get( 'replace' ) ) {
			Library::note( $asset, self::integrity( $tag ) );
		}
		return $tag;
	}

	private static function rewrite_imports( $html ) {
		if ( false === strpos( $html, '@import' ) || false === strpos( $html, Fonts::HOST ) || ! Fonts::enabled() ) {
			return $html;
		}
		$out = preg_replace_callback(
			'#@import\s+(?:url\(\s*)?(["\']?)((?:https?:)?//fonts\.googleapis\.com/[^"\')\s]+)\1\s*\)?\s*;#i',
			static function ( $m ) {
				$local = self::font( $m[2] );
				if ( $local !== $m[2] ) {
					return '@import url("' . esc_url( $local ) . '");';
				}
				return State::offline() ? '' : $m[0];
			},
			$html
		);
		return null === $out ? $html : $out;
	}

	private static function prune_hints( $html ) {
		if ( ! preg_match_all( self::HINT, $html, $hints ) ) {
			return $html;
		}
		$body = str_replace( $hints[0], '', $html );
		foreach ( $hints[0] as $tag ) {
			if ( ! preg_match( '#\bhref\s*=\s*["\']?((?:https?:)?//)?([a-z0-9.\-]+)#i', $tag, $h ) ) {
				continue;
			}
			$host = strtolower( $h[2] );
			$used = false !== stripos( $body, $host ) || ( Fonts::FILES === $host && false !== stripos( $body, Fonts::HOST ) );
			if ( self::managed_host( $host ) && ! $used ) {
				$html = str_replace( $tag, '', $html );
			}
		}
		return $html;
	}

	private static function replace_at( $tag, $offset, $old, $new ) {
		return substr_replace( $tag, esc_url( $new ), $offset, strlen( $old ) );
	}

	private static function learn_integrity( $tag, $src ) {
		if ( ! is_string( $tag ) || false === stripos( $tag, 'integrity' ) || ! self::external( $src ) ) {
			return;
		}
		$asset = Recognizer::parse( $src );
		if ( $asset && '' === Library::local_url( $asset['key'] ) ) {
			Library::note( $asset, self::integrity( $tag ) );
		}
	}

	private static function integrity( $tag ) {
		return preg_match( '#\bintegrity\s*=\s*(["\'])([^"\']+)\1#i', $tag, $m ) ? $m[2] : '';
	}

	public static function external( $src ) {
		if ( ! is_string( $src ) || false === strpos( $src, '//' ) ) {
			return false;
		}
		$host = wp_parse_url( 0 === strpos( $src, '//' ) ? 'https:' . $src : $src, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host && ! in_array( strtolower( $host ), Hosts::own(), true );
	}

	private static function managed_host( $host ) {
		return in_array( $host, Recognizer::hosts(), true ) || Fonts::HOST === $host || Fonts::FILES === $host;
	}

	private static function mentions_managed( $html ) {
		foreach ( array_merge( Recognizer::needles(), array( Fonts::HOST, Fonts::FILES ) ) as $needle ) {
			if ( false !== strpos( $html, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function needed_hosts() {
		$needed = array();
		foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
			foreach ( $deps->registered as $dependency ) {
				if ( ! self::external( $dependency->src ) ) {
					continue;
				}
				$host = strtolower( (string) wp_parse_url( ( 0 === strpos( $dependency->src, '//' ) ? 'https:' : '' ) . $dependency->src, PHP_URL_HOST ) );
				if ( ! self::managed_host( $host ) || isset( $needed[ $host ] ) ) {
					continue;
				}
				if ( Fonts::HOST === $host ) {
					$font = Fonts::enabled() ? Fonts::parse( $dependency->src ) : null;
					if ( ! $font || '' === Fonts::local_url( $font['key'] ) ) {
						$needed[ $host ]        = true;
						$needed[ Fonts::FILES ] = true;
					}
					continue;
				}
				$asset = Recognizer::parse( $dependency->src );
				if ( ! $asset || '' === Library::local_url( $asset['key'] ) ) {
					$needed[ $host ] = true;
				}
			}
		}
		return $needed;
	}

	private static function bufferable() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_trackback() ) {
			return false;
		}
		return ! ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) );
	}

	private static function is_html_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'html' );
			}
		}
		return true;
	}
}
