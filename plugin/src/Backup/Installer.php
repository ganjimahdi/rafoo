<?php

namespace Rafoo\Backup;

use Rafoo\Assets\Store;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const NAME = 'rafoo-restore.php';

	const CLASSES = array( 'Tar', 'Source', 'Unpack', 'Lines' );

	public static function source() {
		$code = '';
		foreach ( self::CLASSES as $class ) {
			$text  = (string) Store::fs()->get_contents( __DIR__ . '/Archive/' . $class . '.php' );
			$text  = (string) preg_replace( '/^<\?php\s*/', '', $text );
			$text  = (string) preg_replace( '/^namespace [^;]+;\s*/m', '', $text );
			$text  = (string) preg_replace( "/^defined\( 'ABSPATH' \) \|\| exit;\s*/m", '', $text );
			$code .= $text . "\n";
		}
		return str_replace(
			array( '/*__RAFOO_ARCHIVE__*/', "'__RAFOO_TEXT__'" ),
			array( $code, "'" . addcslashes( (string) wp_json_encode( self::text(), JSON_UNESCAPED_UNICODE ), "'\\" ) . "'" ),
			self::template()
		);
	}

	public static function text() {
		return array(
			'lang'     => str_replace( '_', '-', determine_locale() ),
			'dir'      => is_rtl() ? 'rtl' : 'ltr',
			'title'    => __( 'Restore the site with Rafoo', 'rafoo' ),
			'missing'  => __( 'This server lacks PHP extensions Rafoo needs (zlib, sodium and mysqli).', 'rafoo' ),
			'intro'    => __( 'Enter the details of the new database and the key Rafoo showed on the old site. All files and data are restored on this host.', 'rafoo' ),
			'host'     => __( 'Database host', 'rafoo' ),
			'name'     => __( 'Database name', 'rafoo' ),
			'user'     => __( 'Username', 'rafoo' ),
			'pass'     => __( 'Password', 'rafoo' ),
			'key'      => __( 'Restore key', 'rafoo' ),
			'start'    => __( 'Start restore', 'rafoo' ),
			'checking' => __( 'Checking…', 'rafoo' ),
			/* translators: %s: error code */
			'error'    => __( 'Error: %s', 'rafoo' ),
			'bad_key'  => __( 'The key is not correct.', 'rafoo' ),
			'locked'   => __( 'Too many failed attempts. Send the file again.', 'rafoo' ),
			'archive'  => __( 'The backup files on this host are missing or incomplete. Prepare the move again on the old site.', 'rafoo' ),
			'damaged'  => __( 'A backup file on this host is damaged. Prepare the move again on the old site.', 'rafoo' ),
			'done'     => __( 'Restore finished. Go to the dashboard', 'rafoo' ),
			'steps'    => array(
				'verify'  => __( 'Checking the backup', 'rafoo' ),
				'files'   => __( 'Restoring files', 'rafoo' ),
				'config'  => __( 'Setting up wp-config.php', 'rafoo' ),
				'import'  => __( 'Restoring the database', 'rafoo' ),
				'replace' => __( 'Updating the site address', 'rafoo' ),
				'finish'  => __( 'Cleaning up', 'rafoo' ),
			),
		);
	}

	private static function template() {
		return <<<'PHP'
<?php
define( 'ABSPATH', __DIR__ . '/' );

$rr_text = (array) json_decode( '__RAFOO_TEXT__', true );

const RAFOO_STATE = __DIR__ . '/rafoo-restore-state.php';
const RAFOO_GUARD = "<?php exit; ?>\n";

ini_set( 'display_errors', '0' );
@set_time_limit( 120 );

/*__RAFOO_ARCHIVE__*/

final class RR_Extract {
	private $root;
	private $out = null;
	private $stats = array();
	public function __construct( $root ) {
		$this->root = $root;
	}
	public function restore( array $state ) {
		$this->stats = array_merge( array( 'files' => 0, 'meta' => '' ), $state );
	}
	public function state() {
		return $this->stats;
	}
	public function open( array $entry ) {
		$entry['act'] = 'skip';
		$path         = (string) $entry['path'];
		if ( 'rafoo/backup.json' === $path ) {
			$entry['act']        = 'meta';
			$this->stats['meta'] = '';
			return $entry;
		}
		if ( 'rafoo/database.sql.gz' === $path ) {
			$target = $this->root . '/rafoo-restore.sql.gz';
		} elseif ( 0 === strpos( $path, 'files/' ) ) {
			$relative = substr( $path, 6 );
			if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) || 0 === strpos( $relative, 'rafoo-restore' ) || preg_match( '/^rafoo-.+(-p\d{3}\.rfa|\.json)$/', $relative ) ) {
				return $entry;
			}
			$target = $this->root . '/' . $relative;
		} else {
			return $entry;
		}
		if ( ! is_dir( dirname( $target ) ) && ! @mkdir( dirname( $target ), 0755, true ) ) {
			return $entry;
		}
		try {
			$this->out = new SplFileObject( $target . '.rafoo-part', 'wb' );
		} catch ( Throwable $e ) {
			return $entry;
		}
		$entry['act']     = 'write';
		$entry['target']  = base64_encode( $target );
		$entry['written'] = 0;
		return $entry;
	}
	public function write( array $entry, $chunk ) {
		if ( 'meta' === $entry['act'] ) {
			$this->stats['meta'] .= $chunk;
		} elseif ( 'write' === $entry['act'] && null !== $this->out ) {
			if ( $this->out->fwrite( $chunk ) !== strlen( $chunk ) ) {
				throw new RuntimeException( 'disk_full' );
			}
			$entry['written'] += strlen( $chunk );
		}
		return $entry;
	}
	public function close( array $entry ) {
		if ( 'write' !== $entry['act'] ) {
			return;
		}
		if ( null !== $this->out ) {
			$this->out->fflush();
		}
		$this->out = null;
		$target    = (string) base64_decode( $entry['target'] );
		if ( @rename( $target . '.rafoo-part', $target ) ) {
			@touch( $target, (int) $entry['mtime'] );
			++$this->stats['files'];
		}
	}
	public function resume( array $entry ) {
		if ( 'write' !== $entry['act'] ) {
			return $entry;
		}
		$target    = (string) base64_decode( $entry['target'] );
		$this->out = new SplFileObject( $target . '.rafoo-part', 'c+b' );
		$this->out->ftruncate( (int) $entry['written'] );
		$this->out->fseek( (int) $entry['written'] );
		return $entry;
	}
	public function sync() {
		if ( null !== $this->out ) {
			$this->out->fflush();
		}
	}
	public function abandon() {
		$this->out = null;
	}
}

function rr_state() {
	$text  = is_file( RAFOO_STATE ) ? (string) file_get_contents( RAFOO_STATE ) : '';
	$state = json_decode( (string) substr( $text, strlen( RAFOO_GUARD ) ), true );
	return is_array( $state ) ? $state : array( 'step' => 'new', 'tries' => 0 );
}

function rr_save( array $state ) {
	file_put_contents( RAFOO_STATE, RAFOO_GUARD . json_encode( $state ) );
}

function rr_json( array $data ) {
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

function rr_manifest() {
	foreach ( (array) glob( __DIR__ . '/rafoo-*.json' ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( is_array( $data ) && isset( $data['parts'] ) && 2 <= (int) $data['format'] ) {
			$data['file'] = basename( $file );
			return $data;
		}
	}
	throw new RuntimeException( 'archive' );
}

function rr_db( array $state ) {
	mysqli_report( MYSQLI_REPORT_OFF );
	$host = $state['db']['host'];
	$port = (int) ( ini_get( 'mysqli.default_port' ) ? ini_get( 'mysqli.default_port' ) : 3306 );
	if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
		$host = $m[1];
		$port = (int) $m[2];
	}
	$db = @new mysqli( $host, $state['db']['user'], $state['db']['pass'], $state['db']['name'], $port );
	if ( $db->connect_errno ) {
		throw new RuntimeException( 'database: ' . $db->connect_error );
	}
	$db->set_charset( 'utf8mb4' );
	return $db;
}

function rr_key( $text, $salt ) {
	$text = strtr( trim( (string) $text ), '-_', '+/' );
	$raw  = base64_decode( $text . str_repeat( '=', ( 4 - strlen( $text ) % 4 ) % 4 ), true );
	if ( false === $raw || 32 !== strlen( $raw ) ) {
		throw new RuntimeException( 'key' );
	}
	return sodium_crypto_generichash( $raw . $salt, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES );
}

function rr_serialized( $value, array $pairs ) {
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
			$inner = preg_match( '/^[aOsibdN][:;]/', $inner ) ? rr_serialized( $inner, $pairs ) : strtr( $inner, $pairs );
			$out  .= 's:' . strlen( $inner ) . ':"' . $inner . '";';
			$i     = $start + $size + 2;
			continue;
		}
		$out .= $value[ $i ];
		++$i;
	}
	return $out;
}

function rr_replace( $value, array $pairs ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return $value;
	}
	foreach ( array_keys( $pairs ) as $search ) {
		if ( false !== strpos( $value, (string) $search ) ) {
			return preg_match( '/^[aO]:\d+:/', $value ) ? rr_serialized( $value, $pairs ) : strtr( $value, $pairs );
		}
	}
	return $value;
}

function rr_pairs( $from, $to ) {
	$from  = rtrim( (string) $from, '/' );
	$to    = rtrim( (string) $to, '/' );
	$pairs = array();
	if ( '' === $from || $from === $to ) {
		return $pairs;
	}
	$pairs[ $from ]                                 = $to;
	$pairs[ str_replace( '/', '\\/', $from ) ]      = str_replace( '/', '\\/', $to );
	$bare_from                                      = (string) preg_replace( '#^https?:#', '', $from );
	$bare_to                                        = (string) preg_replace( '#^https?:#', '', $to );
	$pairs[ $bare_from ]                            = $bare_to;
	$pairs[ str_replace( '/', '\\/', $bare_from ) ] = str_replace( '/', '\\/', $bare_to );
	return $pairs;
}

function rr_here() {
	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );
	$host  = preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost' ) );
	$path  = rtrim( str_replace( '\\', '/', dirname( (string) ( isset( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : '/' ) ) ), '/' );
	return ( $https ? 'https' : 'http' ) . '://' . $host . $path;
}

function rr_step( array $state, $deadline ) {
	$parts = $state['manifest']['parts'];
	switch ( $state['step'] ) {
		case 'verify':
			$part = $parts[ $state['index'] ];
			$file = __DIR__ . '/' . $part['name'];
			if ( ! is_file( $file ) || filesize( $file ) !== (int) $part['size'] ) {
				throw new RuntimeException( 'archive' );
			}
			if ( ! hash_equals( (string) $part['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
				throw new RuntimeException( 'damaged' );
			}
			$state['unpack']   = Unpack::begin( $file, hex2bin( $state['key'] ) );
			$state['step']     = 'files';
			$state['progress'] = array( $state['index'], count( $parts ) );
			return $state;
		case 'files':
			$handler           = new RR_Extract( __DIR__ );
			$reader            = new Unpack( $state['unpack'], $handler );
			$result            = $reader->run( $deadline, $deadline + 5 );
			$state['progress'] = array( $state['index'] + 0.5, count( $parts ) );
			if ( ! $result['done'] ) {
				$state['unpack'] = $result['state'];
				return $state;
			}
			$stats = $handler->state();
			$meta  = json_decode( (string) $stats['meta'], true );
			if ( is_array( $meta ) ) {
				$state['meta'] = $meta;
			}
			$state['unpack'] = null;
			++$state['index'];
			if ( $state['index'] < count( $parts ) ) {
				$state['step'] = 'verify';
				return $state;
			}
			if ( empty( $state['meta'] ) || ! is_file( __DIR__ . '/rafoo-restore.sql.gz' ) ) {
				throw new RuntimeException( 'damaged' );
			}
			$state['step'] = 'config';
			return $state;
		case 'config':
			$config = __DIR__ . '/wp-config.php';
			$code   = is_file( $config ) ? (string) file_get_contents( $config ) : '';
			if ( '' === $code && is_file( __DIR__ . '/wp-config-sample.php' ) ) {
				$code = (string) file_get_contents( __DIR__ . '/wp-config-sample.php' );
			}
			foreach ( array( 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass', 'DB_HOST' => 'host' ) as $constant => $field ) {
				$line = "define( '" . $constant . "', " . var_export( (string) $state['db'][ $field ], true ) . ' );';
				$code = (string) preg_replace_callback(
					"/define\(\s*['\"]" . $constant . "['\"]\s*,.*?\)\s*;/s",
					static function () use ( $line ) {
						return $line;
					},
					$code,
					1
				);
			}
			$prefix = var_export( (string) $state['meta']['prefix'], true );
			$code   = (string) preg_replace_callback(
				'/\$table_prefix\s*=.*?;/s',
				static function () use ( $prefix ) {
					return '$table_prefix = ' . $prefix . ';';
				},
				$code,
				1
			);
			$code = (string) preg_replace( "/define\(\s*['\"](WP_HOME|WP_SITEURL)['\"]\s*,\s*(['\"]).*?\\2\s*\)\s*;/s", '', $code );
			file_put_contents( $config, $code );
			$state['step']   = 'import';
			$state['import'] = null;
			return $state;
		case 'import':
			$db = rr_db( $state );
			$db->query( 'SET foreign_key_checks = 0' );
			$db->query( 'SET unique_checks = 0' );
			$db->query( "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
			$db->autocommit( false );
			$lines = new Lines( null === $state['import'] ? Source::start( __DIR__ . '/rafoo-restore.sql.gz' ) : $state['import'] );
			$total = isset( $state['meta']['sql_bytes'] ) ? (int) $state['meta']['sql_bytes'] : 0;
			while ( true ) {
				$line = $lines->next();
				if ( null === $line ) {
					$db->commit();
					$state['step']  = 'replace';
					$state['table'] = 0;
					$state['after'] = null;
					break;
				}
				$line = rtrim( $line, "\r" );
				if ( '' !== $line && ! $db->query( $line ) && 1062 !== (int) $db->errno ) {
					$state['errors'][] = substr( $db->error, 0, 160 );
				}
				if ( $lines->at_mark() ) {
					$db->commit();
					$state['import'] = $lines->mark();
					if ( microtime( true ) >= $deadline ) {
						break;
					}
				}
				if ( microtime( true ) >= $deadline + 5 ) {
					$db->rollback();
					break;
				}
			}
			$state['progress'] = array( $lines->raw(), max( 1, $total ) );
			$state['errors']   = array_slice( $state['errors'], 0, 50 );
			return $state;
		case 'replace':
			$db     = rr_db( $state );
			$pairs  = array_merge( rr_pairs( (string) $state['meta']['home'], $state['url'] ), rr_pairs( (string) $state['meta']['siteurl'], $state['url'] ) );
			$tables = $state['meta']['tables'];
			while ( $state['table'] < count( $tables ) && microtime( true ) < $deadline && $pairs ) {
				$table   = $tables[ $state['table'] ];
				$columns = $db->query( 'SHOW COLUMNS FROM `' . $db->real_escape_string( $table ) . '`' );
				$key     = '';
				$texts   = array();
				while ( $columns && ( $column = $columns->fetch_assoc() ) ) {
					if ( 'PRI' === $column['Key'] && '' === $key && preg_match( '/int/i', $column['Type'] ) ) {
						$key = $column['Field'];
					}
					if ( preg_match( '/char|text|blob/i', $column['Type'] ) && 'guid' !== $column['Field'] ) {
						$texts[] = $column['Field'];
					}
				}
				if ( '' === $key || ! $texts ) {
					++$state['table'];
					$state['after'] = null;
					continue;
				}
				$where = null === $state['after'] ? '' : ' WHERE `' . $key . '` > ' . (int) $state['after'];
				$rows  = $db->query( 'SELECT `' . $key . '`, `' . implode( '`, `', $texts ) . '` FROM `' . $table . '`' . $where . ' ORDER BY `' . $key . '` LIMIT 400' );
				if ( ! $rows || 0 === $rows->num_rows ) {
					++$state['table'];
					$state['after'] = null;
					continue;
				}
				while ( $row = $rows->fetch_assoc() ) {
					$sets = array();
					foreach ( $texts as $field ) {
						$next = rr_replace( $row[ $field ], $pairs );
						if ( $next !== $row[ $field ] ) {
							$sets[] = '`' . $field . "` = '" . $db->real_escape_string( (string) $next ) . "'";
						}
					}
					if ( $sets ) {
						$db->query( 'UPDATE `' . $table . '` SET ' . implode( ', ', $sets ) . ' WHERE `' . $key . '` = ' . (int) $row[ $key ] );
					}
					$state['after'] = $row[ $key ];
				}
			}
			$state['progress'] = array( $state['table'], count( $tables ) );
			if ( $state['table'] >= count( $tables ) || ! $pairs ) {
				$options = $state['meta']['prefix'] . 'options';
				$url     = $db->real_escape_string( $state['url'] );
				$db->query( 'UPDATE `' . $options . "` SET option_value = '" . $url . "' WHERE option_name IN ('home', 'siteurl')" );
				$state['step'] = 'finish';
			}
			return $state;
		case 'finish':
			$files = array( __DIR__ . '/rafoo-restore.sql.gz', __DIR__ . '/' . $state['manifest']['file'] );
			foreach ( $parts as $part ) {
				$files[] = __DIR__ . '/' . $part['name'];
			}
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			$state['step'] = 'done';
			return $state;
	}
	return $state;
}

$state  = rr_state();
$action = (string) ( isset( $_POST['action'] ) ? $_POST['action'] : '' );

if ( 'done' === $state['step'] && '' === $action ) {
	@unlink( RAFOO_STATE );
	@unlink( __FILE__ );
	header( 'Location: ' . rr_here() . '/wp-admin/' );
	exit;
}

if ( 'start' === $action ) {
	if ( $state['tries'] >= 20 ) {
		rr_json( array( 'ok' => false, 'error' => 'locked' ) );
	}
	try {
		$state['db'] = array(
			'host' => (string) ( isset( $_POST['host'] ) ? $_POST['host'] : 'localhost' ),
			'name' => (string) ( isset( $_POST['name'] ) ? $_POST['name'] : '' ),
			'user' => (string) ( isset( $_POST['user'] ) ? $_POST['user'] : '' ),
			'pass' => (string) ( isset( $_POST['pass'] ) ? $_POST['pass'] : '' ),
		);
		$manifest = rr_manifest();
		foreach ( $manifest['parts'] as $part ) {
			$file = __DIR__ . '/' . basename( (string) $part['name'] );
			if ( ! is_file( $file ) || filesize( $file ) !== (int) $part['size'] ) {
				throw new RuntimeException( 'archive' );
			}
		}
		$first = __DIR__ . '/' . basename( (string) $manifest['parts'][0]['name'] );
		$head  = Source::head( $first );
		if ( ! $head['encrypted'] || 'K' !== $head['mode'] ) {
			throw new RuntimeException( 'archive' );
		}
		$key   = rr_key( (string) ( isset( $_POST['key'] ) ? $_POST['key'] : '' ), $head['salt'] );
		$probe = new Source( Source::start( $first, $key ) );
		try {
			$probe->read();
		} catch ( RuntimeException $e ) {
			throw new RuntimeException( 'wrong_password' === $e->getMessage() ? 'key' : 'damaged' );
		}
		rr_db( $state )->close();
		$state['manifest'] = $manifest;
		$state['key']      = bin2hex( $key );
		$state['index']    = 0;
		$state['unpack']   = null;
		$state['url']      = rr_here();
		$state['step']     = 'verify';
		$state['errors']   = array();
		rr_save( $state );
		rr_json( array( 'ok' => true ) );
	} catch ( Throwable $e ) {
		++$state['tries'];
		unset( $state['db'] );
		rr_save( $state );
		rr_json( array( 'ok' => false, 'error' => $e->getMessage() ) );
	}
}

if ( 'step' === $action ) {
	if ( in_array( $state['step'], array( 'new', 'done' ), true ) ) {
		rr_json( array( 'ok' => true, 'step' => $state['step'] ) );
	}
	try {
		$state = rr_step( $state, microtime( true ) + 15 );
		rr_save( $state );
		rr_json( array( 'ok' => true, 'step' => $state['step'], 'progress' => isset( $state['progress'] ) ? $state['progress'] : array( 0, 0 ), 'url' => $state['url'] ) );
	} catch ( Throwable $e ) {
		rr_json( array( 'ok' => false, 'error' => $e->getMessage() ) );
	}
}

$ready = function_exists( 'sodium_crypto_secretstream_xchacha20poly1305_init_pull' ) && function_exists( 'inflate_init' ) && function_exists( 'inflate_get_read_len' ) && class_exists( 'mysqli' );
function rr_h( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
?><!doctype html>
<html lang="<?php echo rr_h( $rr_text['lang'] ); ?>" dir="<?php echo rr_h( $rr_text['dir'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Rafoo</title>
<style>
body{margin:0;background:#f8f9fa;color:#09090b;font:15px/1.8 Tahoma,sans-serif}
main{max-width:520px;margin:48px auto;padding:28px;background:#fff;border:1px solid #e4e4e7;border-radius:12px}
h1{margin:0 0 8px;font-size:20px}
p{margin:0 0 16px;color:#52525b}
label{display:block;margin:12px 0 4px;font-weight:700}
input{box-sizing:border-box;width:100%;padding:8px 12px;border:1px solid #e4e4e7;border-radius:8px;font:inherit;direction:ltr}
button{margin-top:20px;padding:10px 18px;border:0;border-radius:8px;background:#09090b;color:#fff;font:inherit;cursor:pointer}
button:disabled{opacity:.5}
#status{margin-top:16px}
progress{width:100%}
:focus-visible{outline:2px solid #09090b;outline-offset:2px}
</style>
</head>
<body>
<main>
<h1><?php echo rr_h( $rr_text['title'] ); ?></h1>
<?php if ( ! $ready ) : ?>
<p><?php echo rr_h( $rr_text['missing'] ); ?></p>
<?php else : ?>
<p><?php echo rr_h( $rr_text['intro'] ); ?></p>
<form id="form">
<label for="host"><?php echo rr_h( $rr_text['host'] ); ?></label><input id="host" name="host" value="localhost" required>
<label for="name"><?php echo rr_h( $rr_text['name'] ); ?></label><input id="name" name="name" required>
<label for="user"><?php echo rr_h( $rr_text['user'] ); ?></label><input id="user" name="user" required autocomplete="off">
<label for="pass"><?php echo rr_h( $rr_text['pass'] ); ?></label><input id="pass" name="pass" type="password" autocomplete="new-password">
<label for="key"><?php echo rr_h( $rr_text['key'] ); ?></label><input id="key" name="key" required autocomplete="off" spellcheck="false">
<button type="submit"><?php echo rr_h( $rr_text['start'] ); ?></button>
</form>
<div id="status" role="status" aria-live="polite"></div>
<script>
(function () {
	var form = document.getElementById('form');
	var status = document.getElementById('status');
	var text = <?php echo json_encode( $rr_text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?>;
	var names = text.steps;
	function failed(code) {
		if (code === 'archive' || code === 'damaged') { return text[code]; }
		return text.error.replace('%s', code);
	}
	function post(data) {
		var body = new URLSearchParams(data);
		return fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function loop() {
		post({ action: 'step' }).then(function (reply) {
			if (!reply.ok) {
				status.textContent = failed(reply.error);
				return;
			}
			if (reply.step === 'done') {
				status.innerHTML = '';
				var link = document.createElement('a');
				link.href = location.pathname;
				link.textContent = text.done;
				status.appendChild(link);
				return;
			}
			var p = reply.progress || [0, 0];
			status.innerHTML = '';
			status.append((names[reply.step] || reply.step) + '…');
			var bar = document.createElement('progress');
			bar.max = p[1] || 1;
			bar.value = p[0] || 0;
			status.appendChild(bar);
			setTimeout(loop, 50);
		}, function () { setTimeout(loop, 3000); });
	}
	form.addEventListener('submit', function (event) {
		event.preventDefault();
		var data = Object.fromEntries(new FormData(form));
		data.action = 'start';
		form.querySelector('button').disabled = true;
		status.textContent = text.checking;
		post(data).then(function (reply) {
			if (!reply.ok) {
				form.querySelector('button').disabled = false;
				status.textContent = reply.error === 'key' ? text.bad_key : reply.error === 'locked' ? text.locked : failed(reply.error);
				return;
			}
			form.hidden = true;
			loop();
		});
	});
})();
</script>
<?php endif; ?>
</main>
</body>
</html>
PHP;
	}
}
