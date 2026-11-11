<?php

namespace Rafoo\Shield;

defined( 'ABSPATH' ) || exit;

final class Rules {

	const GROUPS = array( 'protocol', 'scanner', 'sensitive', 'lfi', 'rce', 'sqli', 'xss', 'object', 'upload', 'ssrf', 'xxe', 'wordpress', 'patch' );

	const CONTENT = array( 'lfi', 'rce', 'sqli', 'xss', 'ssrf', 'xxe' );

	private static $list = null;

	public static function all() {
		if ( null !== self::$list ) {
			return self::$list;
		}
		$p   = Request::PATH;
		$q   = Request::QUERY;
		$b   = Request::BODY;
		$c   = Request::COOKIE;
		$u   = Request::AGENT;
		$h   = Request::HEADER;
		$n   = Request::NAME;
		$x   = Request::XML;
		$qcn = $q | $c | $n;
		$any = $p | $q | $b | $c | $u | $h | $n | $x;

		$cmd_read  = '~(^|[;|&`\n]|\$\()\s*(cat|tac|nl|head|tail|more|less|strings|base64)\s+[^\s;|&]*(/etc/|/proc/|wp-config|\.\./|\.env)~';
		$cmd_fetch = '~([;|&`]|\$\()\s*(wget|curl|fetch|lwp-download|tftp)\s+(-[a-z-]+\s+)*[\'"]?(https?|ftp|tftp)://~';
		$cmd_short = '~([;|&`]|\$\()\s*(id|whoami|uname(\s+-[a-z]+)?|ifconfig|ipconfig|hostname|pwd|ls(\s+-[a-z]+)?|ps\s+aux|net\s+user)\s*([;|&`#)]|$)~';
		$cmd_shell = '~(/bin/(ba|z|c|k|tc|da)?sh\b|\b(ba)?sh\s+-[ci]\s|\bpython\d?(\.\d+)?\s+-c\s|\bperl\s+-e\s|\bphp\s+-r\s|\bnc(at)?\s+(-[a-z]+\s+)*\d{1,3}(\.\d{1,3}){3}\s+\d{2,5}|\bmkfifo\b|/dev/(tcp|udp)/|\$\{?ifs\}?|\$ifs\$9|\bpowershell(\.exe)?\s+-(enc|e|c|nop|w)\b|\bcmd(\.exe)?\s+/c\s)~';
		$php_code  = '~(<\?php|<\?=\s*\$|\b(eval|assert|system|exec|passthru|shell_exec|popen|proc_open|pcntl_exec|create_function|call_user_func(_array)?|base64_decode|gzinflate|gzuncompress|str_rot13|file_put_contents|fsockopen|move_uploaded_file|phpinfo|ini_set|set_time_limit|preg_replace)\s*\(\s*([\'"$]|[a-z_]{3,}\s*\()|\$_(get|post|request|cookie|server|files|env)\s*\[|\bphp://)~';
		$union     = '~\bunion(\s+(all|distinct))?[\s(]+select\b~';
		$taut      = '~[\'"`)]\s*(or|and|xor|\|\||&&)\s*\(?\s*(([\'"]?)(\w+)\3\s*(=|like|<=>)\s*\3\4\b|-?\d+\s*(<|>|<>|!=|>=|<=)\s*-?\d+|true\b|not\s+false\b)~';
		$num_taut  = '~\b\d+\s*\)?\s+(or|and|xor|\|\||&&)\s+\(?\s*(\d+)\s*=\s*\(?\s*\2\b~';
		$sql_time  = '~\b(sleep\s*\(\s*\d+(\.\d+)?\s*\)|benchmark\s*\(\s*\d+\s*,|pg_sleep\s*\(|waitfor\s+delay\s+[\'"]|dbms_pipe\.receive_message|randomblob\s*\(\s*\d{6,})~';
		$sql_func  = '~(\binformation_schema\b|\bmysql\.(user|db)\b|\bpg_(catalog|shadow|user)\b|\bsys(objects|columns|users)\b|\bload_file\s*\(|\binto\s+(out|dump)file\b|\bxp_cmdshell\b|\bsp_executesql\b|\bextractvalue\s*\(|\bupdatexml\s*\(|\bgeometrycollection\s*\(|@@(version|datadir|hostname|basedir)\b|\butl_http\b|\bsqlite_master\b|\bchar\s*\(\s*\d+\s*(,\s*\d+\s*){3,}\)|\b(concat(_ws)?|group_concat|make_set|substr(ing)?|ascii|ord|hex|unhex|mid|ifnull)\s*\([^)]{0,80}\b(select\b|user\s*\(\s*\)|database\s*\(\s*\)|version\s*\(\s*\)))~';
		$sql_stack = '~;\s*((drop|alter|truncate|create|rename)\s+(table|database|schema|user|procedure)\b|delete\s+from\b|insert\s+into\b|update\s+[\w`.]+\s+set\b|select\s+[\w*,`. ()]+\s+from\b|exec(ute)?\s+(master|xp_|sp_))~';
		$sql_end   = '~[\'"`]\s*\)*\s*(--(\s|$)|#\s*$|;\s*$|;\s*--)~';
		$script    = '~<\s*/?\s*script\b~';
		$handler   = '~<[a-z!/][^>]*?[\s/"\'](on[a-z]{3,24})\s*=~';
		$attr      = '~[\'"]\s*[\s/]\s*on[a-z]{3,24}\s*=\s*[^\s>]~';
		$scheme    = '~(javascript|vbscript|livescript)\s*:\s*[^\s]{0,60}[(`=]~';
		$scheme_b  = '~(href|src|action|formaction|xlink:href|data|srcdoc|background|poster)\s*=\s*[\'"]?\s*(javascript|vbscript|data:text/html)~';
		$tags      = '~<\s*(iframe|frame|frameset|object|embed|applet|svg|math|base|meta|link|form|isindex|style|template|xss|img|video|audio|body|details|marquee|input|textarea|button|keygen)\b~';
		$sinks     = '~(\bdocument\s*\.\s*(cookie|domain|write|location)|\bwindow\s*\.\s*(location|name|open)\b|\.(inner|outer)html\b|\bstring\.fromcharcode\b|\b(eval|alert|prompt|confirm|atob|settimeout|setinterval)\s*\(|\bfetch\s*\(\s*[\'"`]?(https?:)?//|\bconstructor\s*\(|\btop\s*\[|\bimport\s*\(\s*[\'"`])~';
		$traverse  = '~(\.\.[/\\\\]).*(etc/(passwd|shadow|group|hosts)|proc/self|wp-config|win\.ini|boot\.ini|\.htaccess|\.env\b|web\.config)~';
		$targets   = '~(^|[\s"\'=:;/\\\\(])(/etc/(passwd|shadow)\b|/proc/self/(environ|cmdline|fd|maps|mem)|c:\\\\+windows\\\\+(win\.ini|system32))~';
		$wrappers  = '~\b(php://(input|filter|fd|stdin|memory|temp)|phar://|expect://|zip://|glob://|data://text/plain;base64)~';
		$ssti      = '~(\{\{[^}]{0,80}(__class__|__globals__|__builtins__|__import__|__subclasses__|config\.|self\.|request\.|lipsum|cycler|joiner|\d+\s*\*\s*\d+)[^}]{0,80}\}\}|\$\{\s*\d+\s*\*\s*\d+\s*\}|#\{\s*\d+\s*\*\s*\d+\s*\}|<%=?\s*\d+\s*\*\s*\d+\s*%>)~';
		$ognl      = '~(%\{\s*[(#@]|class\.module\.classloader|\(#_memberaccess|@java\.lang\.runtime|java\.lang\.processbuilder|\bt\(java\.lang\.)~';
		$jndi      = '~\$\{[^}]{0,40}(jndi|\$\{\s*(lower|upper|env|sys|date|::-)\b)~';
		$shock     = '~\(\s*\)\s*\{\s*[^}]{0,20};\s*\}\s*;~';
		$object    = '~(^|[;{"=\s])(o|c):\+?\d+:"(?!stdclass")[a-z_\\\\][a-z0-9_\\\\]{0,190}":\d+:\{~';
		$ssrf      = '~\b(https?|gopher|dict|ftp|ldap|tftp|sftp)://(127\.\d+\.\d+\.\d+|localhost\b|0\.0\.0\.0|0x7f|2130706433|169\.254\.169\.254|metadata\.google\.internal|\[::1?\]|\[::ffff:|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)~';
		$oast      = '~\b[a-z0-9-]{4,}\.(oast\.(pro|live|site|online|fun|me)|interact\.sh|burpcollaborator\.net|dnslog\.cn|ceye\.io|requestbin\.net|canarytokens\.com)\b~';
		$xxe       = '~<!(doctype|entity)[^>]{0,400}\b(system|public)\b|<!entity\s+%~';

		self::$list = array(
			array( 's_tool', 'scanner', 10, $u, '~\b(sqlmap|nikto|acunetix|netsparker|nessus|openvas|wpscan|nuclei|masscan|zgrab|dirbuster|gobuster|feroxbuster|ffuf|wfuzz|w3af|havij|arachni|jaeles|zmeu|morfeus|fimap|commix|xsstrike|dalfox|joomscan|whatweb|nmap scripting engine|skipfish|appscan|webinspect|wpsec|droopescan|cmsmap)\b~' ),
			array( 's_oast', 'scanner', 10, $q | $c | $n | $u | $h | $b, $oast ),
			array( 'f_vcs', 'sensitive', 10, $p, '~/\.(git|svn|hg|bzr|env|aws|ssh|docker|vscode|idea|htpasswd|ds_store|npmrc|bash_history|git-credentials|composer)(/|$|\.)~' ),
			array( 'f_config', 'sensitive', 10, $p, '#/(wp-config|config|configuration|settings|database|db|backup|dump|localsettings)(\.(php|inc|json|ya?ml))?([.~]|\.php[_-])(bak|backup|old|orig|save|swp|swo|tmp|dist|txt|zip|tar|gz|sql|7z|rar)?~?$#' ),
			array( 'f_dump', 'sensitive', 6, $p, '~\.(sql|sqlite3?|mdb|bak|old|swp|log)(\.(gz|zip|bz2|7z|tar))?$~' ),
			array( 'f_wpconfig', 'sensitive', 6, $p, '~/wp-config(-sample)?\.php~' ),
			array( 'f_shell', 'sensitive', 10, $p, '~/(c99|r57|wso\d*|alfa\w*|b374k|indoxploit|filesman|marijuana|priv8|sh3ll|webshell|phpspy|haxor|xleet|gel4y|mini_?shell|0byte)\.php~' ),
			array( 'f_adminer', 'sensitive', 6, $p, '~/adminer[\w.-]*\.php$~' ),
			array( 'f_uploads_php', 'sensitive', 10, $p, '~/wp-content/uploads/.*\.(php\d?|phtml|phar|pht|phps)(/|$)~' ),
			array( 'f_install', 'wordpress', 6, $p, '~/wp-admin/(install|setup-config)\.php$~' ),
			array( 'f_phpunit', 'sensitive', 10, $p, '~/phpunit/.*eval-stdin\.php~' ),
			array( 'f_probe', 'sensitive', 6, $p, '~^/(cgi-bin/|boaform|hnap1|actuator(/|$)|owa/|solr/|manager/html|_ignition/|telescope/|containers/json|server-status|jmx-console|invoker/|axis2|druid/|geoserver/|\.well-known/.*\.php|wp-content/plugins/[^/]+/(vendor/)?phpunit/)~' ),
			array( 'l_path', 'lfi', 10, $p, '~(/|\\\\)\.\.(/|\\\\|$)~' ),
			array( 'l_traverse', 'lfi', 10, $qcn | $h, $traverse ),
			array( 'l_traverse_b', 'lfi', 6, $b, $traverse ),
			array( 'l_target', 'lfi', 10, $qcn, $targets ),
			array( 'l_target_b', 'lfi', 4, $b, $targets ),
			array( 'l_deep', 'lfi', 6, $qcn, '~(\.\.[/\\\\]){2,}~' ),
			array( 'l_wrapper', 'lfi', 10, $qcn | $h, $wrappers ),
			array( 'l_wrapper_b', 'lfi', 6, $b, $wrappers ),
			array( 'r_jndi', 'rce', 10, $any, $jndi ),
			array( 'r_shock', 'rce', 10, $u | $h | $q | $c, $shock ),
			array( 'r_ognl', 'rce', 10, $any, $ognl ),
			array( 'r_read', 'rce', 10, $qcn | $h, $cmd_read ),
			array( 'r_read_b', 'rce', 6, $b, $cmd_read ),
			array( 'r_fetch', 'rce', 10, $qcn | $h, $cmd_fetch ),
			array( 'r_fetch_b', 'rce', 4, $b, $cmd_fetch ),
			array( 'r_short', 'rce', 10, $qcn, $cmd_short ),
			array( 'r_short_b', 'rce', 3, $b, $cmd_short ),
			array( 'r_shell', 'rce', 10, $qcn | $h | $u, $cmd_shell ),
			array( 'r_shell_b', 'rce', 6, $b, $cmd_shell ),
			array( 'r_php', 'rce', 10, $qcn | $h | $u, $php_code ),
			array( 'r_php_b', 'rce', 6, $b, $php_code ),
			array( 'r_ssti', 'rce', 8, $qcn, $ssti ),
			array( 'r_ssti_b', 'rce', 3, $b, $ssti ),
			array( 'q_union', 'sqli', 10, $qcn | $h, $union ),
			array( 'q_union_b', 'sqli', 6, $b, $union ),
			array( 'q_taut', 'sqli', 10, $qcn | $h, $taut ),
			array( 'q_taut_b', 'sqli', 6, $b, $taut ),
			array( 'q_num', 'sqli', 10, $qcn, $num_taut ),
			array( 'q_time', 'sqli', 10, $qcn | $h | $u, $sql_time ),
			array( 'q_time_b', 'sqli', 6, $b, $sql_time ),
			array( 'q_func', 'sqli', 10, $qcn | $h | $u, $sql_func ),
			array( 'q_func_b', 'sqli', 6, $b, $sql_func ),
			array( 'q_stack', 'sqli', 10, $qcn, $sql_stack ),
			array( 'q_stack_b', 'sqli', 4, $b, $sql_stack ),
			array( 'q_end', 'sqli', 6, $qcn, $sql_end ),
			array( 'x_script', 'xss', 10, $p | $qcn | $h, $script ),
			array( 'x_script_b', 'xss', 6, $b, $script ),
			array( 'x_handler', 'xss', 10, $p | $qcn | $h, $handler ),
			array( 'x_handler_b', 'xss', 6, $b, $handler ),
			array( 'x_attr', 'xss', 8, $qcn, $attr ),
			array( 'x_scheme', 'xss', 10, $qcn, $scheme ),
			array( 'x_scheme_b', 'xss', 6, $b, $scheme_b ),
			array( 'x_tag', 'xss', 6, $qcn, $tags ),
			array( 'x_tag_b', 'xss', 2, $b, $tags ),
			array( 'x_sink', 'xss', 6, $qcn, $sinks ),
			array( 'x_sink_b', 'xss', 2, $b, $sinks ),
			array( 'x_break', 'xss', 6, $qcn, '~[\'"]\s*>\s*<\s*[a-z/!]~' ),
			array( 'x_path', 'xss', 8, $p, '~[<>"`]~' ),
			array( 'o_object', 'object', 10, $q | $b | $c | $h, $object ),
			array( 's_ssrf', 'ssrf', 6, $q | $n, $ssrf ),
			array( 's_ssrf_b', 'ssrf', 3, $b, $ssrf ),
			array( 's_gopher', 'ssrf', 10, $qcn | $b, '~(\b(gopher|dict|ldap|jar)://|169\.254\.169\.254|metadata\.google\.internal|100\.100\.100\.200)~' ),
			array( 'e_xxe', 'xxe', 10, $x | $b, $xxe ),
		);
		return self::$list;
	}

	public static function scan( Request $r, $trusted = 0, array $extra = array() ) {
		$hits  = array();
		$rules = self::all();
		foreach ( $r->args as $arg ) {
			list( $target, $name, $value ) = $arg;
			$body = Request::BODY === $target || Request::XML === $target;
			foreach ( $rules as $rule ) {
				if ( isset( $hits[ $rule[0] ] ) || 0 === ( $rule[3] & $target ) ) {
					continue;
				}
				if ( $trusted >= 1 && $body && in_array( $rule[1], self::CONTENT, true ) ) {
					continue;
				}
				if ( $trusted >= 1 && Request::NAME === $target && in_array( $rule[1], self::CONTENT, true ) ) {
					continue;
				}
				if ( preg_match( $rule[4], $value ) ) {
					$hits[ $rule[0] ] = array(
						'id'    => $rule[0],
						'group' => $rule[1],
						'score' => $rule[2],
						'where' => $name,
					);
				}
			}
		}
		foreach ( self::protocol( $r ) as $hit ) {
			$hits[ $hit['id'] ] = $hit;
		}
		foreach ( self::uploads( $r, $trusted ) as $hit ) {
			$hits[ $hit['id'] ] = $hit;
		}
		foreach ( $extra as $hit ) {
			$hits[ $hit['id'] ] = $hit;
		}
		return self::verdict( $hits );
	}

	public static function verdict( array $hits ) {
		$score = 0;
		$top   = null;
		foreach ( $hits as $hit ) {
			$score += $hit['score'];
			if ( null === $top || $hit['score'] > $top['score'] ) {
				$top = $hit;
			}
		}
		return array(
			'score' => $score,
			'hits'  => array_values( $hits ),
			'rule'  => $top ? $top['id'] : '',
			'group' => $top ? $top['group'] : '',
			'where' => $top ? $top['where'] : '',
		);
	}

	private static function protocol( Request $r ) {
		$out = array();
		if ( ! in_array( $r->method, array( 'GET', 'POST', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$out[] = self::hit( 'p_method', 'protocol', 10, 'method' );
		}
		if ( ! empty( $r->flags['null'] ) ) {
			$out[] = self::hit( 'p_null', 'protocol', 8, 'uri' );
		}
		if ( ! empty( $r->flags['crlf'] ) ) {
			$out[] = self::hit( 'p_crlf_uri', 'protocol', 10, 'uri' );
		}
		if ( ! empty( $r->flags['long'] ) ) {
			$out[] = self::hit( 'p_long', 'protocol', 4, 'uri' );
		}
		return $out;
	}

	private static function uploads( Request $r, $trusted ) {
		$out = array();
		foreach ( $r->files as $file ) {
			$name = strtolower( $file['name'] );
			if ( $trusted < 2 && preg_match( '~\.(php\d?|phtml|phar|pht|phps|shtml|htaccess|user\.ini|asp|aspx|jsp|cgi|pl|py|sh|exe|dll)(\.|$)|^\.ht|^\.user\.ini$~', $name ) ) {
				$out[] = self::hit( 'u_ext', 'upload', 10, $file['field'] );
				continue;
			}
			if ( $trusted >= 2 || '' === $file['tmp'] || ! is_file( $file['tmp'] ) || ! preg_match( '~\.(jpe?g|png|gif|webp|bmp|ico|svg|pdf|txt|csv|mp4|mp3|avif|heic)$~', $name ) ) {
				continue;
			}
			$head = (string) @file_get_contents( $file['tmp'], false, null, 0, 16384 );
			$low  = strtolower( $head );
			if ( false !== strpos( $low, '<?php' ) || false !== strpos( $low, '__halt_compiler' ) ) {
				$out[] = self::hit( 'u_php', 'upload', 10, $file['field'] );
			} elseif ( preg_match( '~\.svg$~', $name ) && preg_match( '~<\s*script\b|\son[a-z]{3,24}\s*=|javascript\s*:|<\s*foreignobject\b~', $low ) ) {
				$out[] = self::hit( 'u_svg', 'upload', 10, $file['field'] );
			}
		}
		return $out;
	}

	public static function hit( $id, $group, $score, $where ) {
		return array(
			'id'    => $id,
			'group' => $group,
			'score' => (int) $score,
			'where' => (string) $where,
		);
	}
}
