<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Replace {

	const BATCH = 400;

	public static function pairs( $from, $to ) {
		$from  = untrailingslashit( (string) $from );
		$to    = untrailingslashit( (string) $to );
		$pairs = array();
		if ( '' === $from || $from === $to ) {
			return $pairs;
		}
		$pairs[ $from ]                          = $to;
		$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
		$bare_from                               = preg_replace( '#^https?:#', '', $from );
		$bare_to                                 = preg_replace( '#^https?:#', '', $to );
		if ( $bare_from !== $from ) {
			$pairs[ $bare_from ]                          = $bare_to;
			$pairs[ str_replace( '/', '\\/', $bare_from ) ] = str_replace( '/', '\\/', $bare_to );
		}
		$pairs[ rawurlencode( $from ) ] = rawurlencode( $to );
		return $pairs;
	}

	public static function value( $value, array $pairs ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$found = false;
		foreach ( $pairs as $search => $unused ) {
			if ( false !== strpos( $value, (string) $search ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return $value;
		}
		if ( is_serialized( $value, false ) ) {
			return self::serialized( $value, $pairs );
		}
		return strtr( $value, $pairs );
	}

	public static function serialized( $value, array $pairs ) {
		$out    = '';
		$length = strlen( $value );
		$i      = 0;
		while ( $i < $length ) {
			if ( 's' === $value[ $i ] && preg_match( '/\Gs:(\d+):"/', $value, $m, 0, $i ) ) {
				$size  = (int) $m[1];
				$start = $i + strlen( $m[0] );
				if ( $start + $size + 2 > $length || '";' !== substr( $value, $start + $size, 2 ) ) {
					return strtr( $value, $pairs );
				}
				$inner = substr( $value, $start, $size );
				$inner = is_serialized( $inner, false ) ? self::serialized( $inner, $pairs ) : strtr( $inner, $pairs );
				$out  .= 's:' . strlen( $inner ) . ':"' . $inner . '";';
				$i     = $start + $size + 2;
				continue;
			}
			$out .= $value[ $i ];
			++$i;
		}
		return false === @unserialize( $out, array( 'allowed_classes' => false ) ) && 'b:0;' !== $out ? $value : $out;
	}

	public static function start( array $tables ) {
		return array(
			'tables'  => array_values( $tables ),
			'table'   => 0,
			'after'   => null,
			'changed' => 0,
		);
	}

	public static function run( array $job, array $pairs, $deadline ) {
		global $wpdb;
		$job['done'] = false;
		while ( $job['table'] < count( $job['tables'] ) && microtime( true ) < $deadline ) {
			$table   = $job['tables'][ $job['table'] ];
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			$key     = '';
			$texts   = array();
			foreach ( (array) $columns as $column ) {
				if ( 'PRI' === $column['Key'] && '' === $key && preg_match( '/int/i', $column['Type'] ) ) {
					$key = $column['Field'];
				}
				if ( preg_match( '/char|text|blob/i', $column['Type'] ) && 'guid' !== $column['Field'] ) {
					$texts[] = $column['Field'];
				}
			}
			if ( ! $texts ) {
				++$job['table'];
				$job['after'] = null;
				continue;
			}
			if ( '' === $key ) {
				foreach ( $pairs as $search => $replace ) {
					foreach ( $texts as $field ) {
						$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = REPLACE(%i, %s, %s) WHERE %i NOT LIKE %s AND %i NOT LIKE %s', $table, $field, $field, $search, $replace, $field, 'a:%', $field, 'O:%' ) );
					}
				}
				++$job['table'];
				$job['after'] = null;
				continue;
			}
			$rows = null === $job['after']
				? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d', $table, $key, self::BATCH ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i LIMIT %d', $table, $key, $job['after'], $key, self::BATCH ), ARRAY_A );
			if ( ! $rows ) {
				++$job['table'];
				$job['after'] = null;
				continue;
			}
			foreach ( $rows as $row ) {
				$update = array();
				foreach ( $texts as $field ) {
					$next = self::value( $row[ $field ], $pairs );
					if ( $next !== $row[ $field ] ) {
						$update[ $field ] = $next;
					}
				}
				if ( $update ) {
					$wpdb->update( $table, $update, array( $key => $row[ $key ] ) );
					++$job['changed'];
				}
				$job['after'] = $row[ $key ];
			}
		}
		$job['done'] = $job['table'] >= count( $job['tables'] );
		return $job;
	}
}
