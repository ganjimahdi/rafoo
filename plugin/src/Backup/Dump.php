<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A backup must read and write the live tables directly; cached results would copy stale data.

final class Dump {

	const STATEMENT_BYTES = 262144;

	const TEMP = 'rfrn_';

	const OLD = 'rfro_';

	const CHECKPOINT = 8388608;

	const BATCH_BYTES = 4194304;

	public static function tables() {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$tables = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N ) as $row ) {
			if ( ! isset( $row[1] ) || 'VIEW' !== strtoupper( (string) $row[1] ) ) {
				$tables[] = (string) $row[0];
			}
		}
		$out = array();
		foreach ( $tables as $table ) {
			if ( 0 !== strpos( $table, self::TEMP ) && 0 !== strpos( $table, self::OLD ) ) {
				$out[] = $table;
			}
		}
		return $out;
	}

	public static function begin( $file ) {
		$sink = Archive\Sink::create( $file );
		$sink->write( "SET NAMES utf8mb4;\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n" );
		$tables = self::tables();
		$sizes  = self::sizes();
		$list   = array();
		foreach ( $tables as $table ) {
			$list[] = isset( $sizes[ $table ] ) ? $sizes[ $table ] : 0;
		}
		return array(
			'sink'   => $sink->checkpoint(),
			'tables' => $tables,
			'sizes'  => $list,
			'table'  => 0,
			'after'  => null,
			'offset' => 0,
			'begun'  => false,
			'rows'   => 0,
			'key'    => '',
			'batch'  => 0,
			'avg'    => 0,
			'since'  => 0,
			'done'   => false,
		);
	}

	public static function dump( array $job, $deadline, $memory, $save ) {
		global $wpdb;
		$sink = Archive\Sink::open( $job['sink'] );
		while ( $job['table'] < count( $job['tables'] ) && microtime( true ) < $deadline ) {
			$table = $job['tables'][ $job['table'] ];
			if ( ! $job['begun'] ) {
				$job['sink']  = $sink->checkpoint();
				$job['since'] = $sink->raw();
				call_user_func( $save, $job );
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				if ( ! $create || ! isset( $create[1] ) ) {
					if ( '' !== $wpdb->last_error ) {
						throw new Problem( 'db_read', array( 'table' => $table, 'error' => Journal::clean( $wpdb->last_error ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
					}
					++$job['table'];
					continue;
				}
				$sink->write( 'DROP TABLE IF EXISTS `' . $table . "`;\n" . str_replace( array( "\r\n", "\n", "\r" ), ' ', $create[1] ) . ";\n" );
				$status       = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
				$job['avg']   = $status && isset( $status['Avg_row_length'] ) ? (int) $status['Avg_row_length'] : 0;
				$job['batch'] = self::rows_for( $job['avg'], $memory );
				$job['key']   = self::numeric_key( $table );
				$job['begun'] = true;
			}
			$job['batch'] = min( (int) $job['batch'], self::rows_for( $job['avg'], $memory ) );
			$rows         = self::batch( $table, $job );
			if ( '' !== $wpdb->last_error ) {
				throw new Problem( 'db_read', array( 'table' => $table, 'error' => Journal::clean( $wpdb->last_error ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
			}
			if ( ! $rows ) {
				++$job['table'];
				$job['begun']  = false;
				$job['after']  = null;
				$job['offset'] = 0;
				$job['rows']   = 0;
				continue;
			}
			$values = array();
			$bytes  = 0;
			foreach ( $rows as $row ) {
				$line     = self::values( $row );
				$values[] = $line;
				$bytes   += strlen( $line );
				if ( $bytes >= self::STATEMENT_BYTES ) {
					$sink->write( 'INSERT INTO `' . $table . '` VALUES ' . implode( ',', $values ) . ";\n" );
					$values = array();
					$bytes  = 0;
				}
			}
			if ( $values ) {
				$sink->write( 'INSERT INTO `' . $table . '` VALUES ' . implode( ',', $values ) . ";\n" );
			}
			$job['rows'] += count( $rows );
			if ( '' !== $job['key'] ) {
				$last         = end( $rows );
				$job['after'] = $last[ $job['key'] ];
			} else {
				$job['offset'] += count( $rows );
			}
			unset( $rows, $values );
			if ( Ledger::memory_tight() ) {
				$job['batch'] = max( 10, (int) ( $job['batch'] / 2 ) );
			}
			if ( $sink->raw() - $job['since'] >= self::CHECKPOINT ) {
				$job['sink']  = $sink->checkpoint();
				$job['since'] = $sink->raw();
				call_user_func( $save, $job );
			}
		}
		if ( $job['table'] >= count( $job['tables'] ) ) {
			$job['sink'] = $sink->close();
			$job['done'] = true;
			return $job;
		}
		$job['sink']  = $sink->checkpoint();
		$job['since'] = $sink->raw();
		return $job;
	}

	public static function done_bytes( array $job ) {
		$done = 0;
		for ( $i = 0; $i < $job['table'] && $i < count( $job['sizes'] ); $i++ ) {
			$done += (int) $job['sizes'][ $i ];
		}
		if ( isset( $job['sizes'][ $job['table'] ] ) ) {
			$done += min( (int) $job['sizes'][ $job['table'] ], (int) $job['rows'] * max( 1, (int) $job['avg'] ) );
		}
		return $done;
	}

	public static function load( $file, array $job, $soft, $hard ) {
		global $wpdb;
		$lines = new Archive\Lines( empty( $job['source'] ) ? Archive\Source::start( $file ) : $job['source'] );
		$saved = $job;
		$wpdb->query( 'SET foreign_key_checks = 0' );
		$wpdb->query( 'SET unique_checks = 0' );
		$wpdb->query( 'SET autocommit = 0' );
		$job['done'] = false;
		try {
			while ( true ) {
				$line = $lines->next();
				if ( null === $line ) {
					$wpdb->query( 'COMMIT' );
					$job['done'] = true;
					break;
				}
				$line = rtrim( $line, "\r" );
				$sql  = '' === $line ? null : self::rename( $line, $job );
				if ( null !== $sql ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statements come from the site's own backup file, written by Dump::dump with escaped values, and run into temporary rfrn_ tables.
					if ( false === $wpdb->query( $sql ) ) {
						if ( false !== stripos( $wpdb->last_error, 'Duplicate entry' ) ) {
							++$job['duplicates'];
						} else {
							$job['errors'][] = Journal::clean( $wpdb->last_error );
							if ( count( $job['errors'] ) > 20 ) {
								throw new Problem( 'db_import', array( 'error' => $job['errors'][0] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error codes and details go to the backup log and the REST reply, never into HTML.
							}
						}
					}
					if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) ) {
						$job['created'][] = $m[1];
					}
				}
				if ( $lines->at_mark() ) {
					$wpdb->query( 'COMMIT' );
					$job['source'] = $lines->mark();
					$job['raw']    = $lines->raw();
					$saved         = $job;
					if ( microtime( true ) >= $soft ) {
						break;
					}
				}
				if ( microtime( true ) >= $hard ) {
					$wpdb->query( 'ROLLBACK' );
					$job = $saved;
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->query( 'SET autocommit = 1' );
			throw $e;
		}
		$wpdb->query( 'SET autocommit = 1' );
		$job['created'] = array_values( array_unique( $job['created'] ) );
		return $job;
	}

	private static function sizes() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A ) as $row ) {
			$out[ (string) $row['Name'] ] = (int) $row['Data_length'];
		}
		return $out;
	}

	private static function rows_for( $avg, $memory ) {
		$bytes = min( self::BATCH_BYTES, Ledger::headroom() / 16 ) * $memory;
		return (int) max( 10, min( 2000, floor( $bytes / max( 64, (int) $avg ) ) ) );
	}

	private static function batch( $table, array $job ) {
		global $wpdb;
		$limit = max( 10, (int) $job['batch'] );
		if ( '' !== $job['key'] ) {
			$rows = null === $job['after']
				? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d', $table, $job['key'], $limit ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i LIMIT %d', $table, $job['key'], $job['after'], $job['key'], $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $limit, $job['offset'] ), ARRAY_A );
		}
		$error = $wpdb->last_error;
		$wpdb->flush();
		$wpdb->last_error = $error;
		return $rows;
	}

	public static function import( $file, array $job, $deadline ) {
		global $wpdb;
		$in = Ledger::open( $file, 'rb' );
		if ( ! $in ) {
			return false;
		}
		$in->fseek( $job['offset'] );
		$wpdb->query( 'SET foreign_key_checks = 0' );
		$job['done'] = false;
		while ( microtime( true ) < $deadline ) {
			$line = $in->eof() ? false : $in->fgets();
			if ( false === $line || ( '' === $line && $in->eof() ) ) {
				$job['done'] = true;
				break;
			}
			$job['offset'] += strlen( $line );
			$line           = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$sql = self::rename( $line, $job );
			if ( null === $sql ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statements come from the site's own backup file, written by Dump::export with escaped values, and run into temporary rfrn_ tables.
			if ( false === $wpdb->query( $sql ) ) {
				$job['errors'][] = substr( $wpdb->last_error, 0, 200 );
				if ( count( $job['errors'] ) > 20 ) {
					return false;
				}
			}
			if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) ) {
				$job['created'][] = $m[1];
			}
		}
		return $job;
	}

	public static function rename( $line, array $job ) {
		if ( 0 === strpos( $line, 'SET ' ) ) {
			return $line;
		}
		if ( ! preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $line, $m ) ) {
			return null;
		}
		$name = $m[2];
		if ( 0 !== strpos( $name, $job['from'] ) ) {
			return null;
		}
		$target = self::TEMP . $job['to'] . substr( $name, strlen( $job['from'] ) );
		return $m[1] . ' `' . $target . '`' . substr( $line, strlen( $m[0] ) );
	}

	public static function swap( array $created, $to ) {
		$created = array_values( array_unique( $created ) );
		$pairs   = array();
		$live    = array();
		$exists  = array_flip( self::tables() );
		foreach ( $created as $temp ) {
			$name = substr( $temp, strlen( self::TEMP ) );
			if ( isset( $exists[ $name ] ) ) {
				self::drop( self::OLD . $name );
				$pairs[] = array( $name, self::OLD . $name, $temp );
				$live[]  = $name;
			} else {
				$pairs[] = array( $temp, $name, '' );
			}
		}
		foreach ( $pairs as $pair ) {
			if ( false === self::rename_pair( $pair ) ) {
				return false;
			}
		}
		return $live;
	}

	public static function undo( array $live ) {
		foreach ( $live as $name ) {
			self::drop( self::TEMP . $name );
			self::rename_pair( array( $name, self::TEMP . $name, self::OLD . $name ) );
		}
		self::drop_prefixed( self::TEMP );
	}

	public static function drop_prefixed( $prefix ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) {
			self::drop( $table );
		}
	}

	public static function fix_prefix( $from, $to ) {
		global $wpdb;
		if ( $from === $to ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', self::TEMP . $to . 'options', $to . 'user_roles', $from . 'user_roles' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s', self::TEMP . $to . 'usermeta', $to, strlen( $from ) + 1, $wpdb->esc_like( $from ) . '%' ) );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::TEMP . $to ) . '%' . $wpdb->esc_like( '_options' ) ) ) as $table ) {
			if ( preg_match( '/_(\d+)_options$/', $table, $m ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $table, $to . $m[1] . '_user_roles', $from . $m[1] . '_user_roles' ) );
			}
		}
	}

	private static function drop( $table ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	private static function rename_pair( array $pair ) {
		global $wpdb;
		if ( '' === $pair[2] ) {
			return $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $pair[0], $pair[1] ) );
		}
		return $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $pair[0], $pair[1], $pair[2], $pair[0] ) );
	}

	private static function numeric_key( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );
		if ( 1 !== count( $keys ) ) {
			return '';
		}
		$column = $keys[0]['Column_name'];
		$type   = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $column ), ARRAY_A );
		return $type && preg_match( '/int/i', $type['Type'] ) ? $column : '';
	}

	public static function values( array $row ) {
		global $wpdb;
		$out = array();
		foreach ( $row as $value ) {
			$out[] = null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
		}
		return '(' . implode( ',', $out ) . ')';
	}
}
