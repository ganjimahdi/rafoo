<?php

namespace Rafoo\Admin;

defined( 'ABSPATH' ) || exit;

final class ShieldStrings {

	public static function all() {
		return array_merge( self::main(), self::details(), self::help() );
	}

	private static function main() {
		return array(
			'tab_shield'                  => __( 'Shield', 'rafoo' ),
			'settings_security_title'     => __( 'Security', 'rafoo' ),
			'shield_card_title'           => __( 'Rafoo Shield', 'rafoo' ),
			'shield_open'                 => __( 'Open the shield', 'rafoo' ),
			'shield_to_fortress'          => __( 'Switch to fortress mode', 'rafoo' ),
			'shield_to_smart'             => __( 'Back to smart mode', 'rafoo' ),
			'shield_mode_label'           => __( 'Shield mode', 'rafoo' ),
			'shield_mode_hint'            => __( 'Smart suits almost every site. Use fortress only during a heavy attack.', 'rafoo' ),
			'shield_mode_off'             => __( 'Off', 'rafoo' ),
			'shield_mode_smart'           => __( 'Smart', 'rafoo' ),
			'shield_mode_strict'          => __( 'Strict', 'rafoo' ),
			'shield_mode_fortress'        => __( 'Fortress', 'rafoo' ),
			'shield_mode_off_help'        => __( 'No request is checked. Attacks reach WordPress and your other plugins.', 'rafoo' ),
			'shield_mode_smart_help'      => __( 'Stops clear attacks, scanners and brute force, and checks a browser only when it sends too many requests. Visitors notice nothing.', 'rafoo' ),
			'shield_mode_strict_help'     => __( 'Lower thresholds, closed sign-in through XML-RPC and stricter limits. For sites that are attacked often.', 'rafoo' ),
			'shield_mode_fortress_help'   => __( 'Every new visitor passes a short browser check once, and attackers are banned for longer. For the hours of a heavy attack.', 'rafoo' ),
			'shield_status_off'           => __( 'The shield is off', 'rafoo' ),
			'shield_status_smart'         => __( 'The shield is guarding the site', 'rafoo' ),
			'shield_status_strict'        => __( 'The shield is on strict guard', 'rafoo' ),
			'shield_status_fortress'      => __( 'Fortress mode is on', 'rafoo' ),
			'shield_where_early'          => __( 'Requests are checked before WordPress loads.', 'rafoo' ),
			'shield_where_first'          => __( 'Requests are checked as soon as plugins load, before any other plugin.', 'rafoo' ),
			'shield_where_plugin'         => __( 'Requests are checked while plugins load. Rafoo moves itself to the front of the plugin list.', 'rafoo' ),
			'shield_paused'               => __( 'Rafoo is paused, so the shield is not checking requests.', 'rafoo' ),
			'shield_stat_today'           => __( 'Attacks stopped today', 'rafoo' ),
			'shield_stat_week'            => __( 'In the last 7 days', 'rafoo' ),
			'shield_stat_challenges'      => __( 'Browser checks in 7 days', 'rafoo' ),
			'shield_stat_logins'          => __( 'Sign-in locks in 7 days', 'rafoo' ),
			'shield_early_title'          => __( 'Protection before WordPress', 'rafoo' ),
			'shield_early_text'           => __( 'Runs the shield before WordPress and every plugin, so attacks never reach their code. Rafoo adds one line to the site’s .htaccess or .user.ini and removes it when turned off.', 'rafoo' ),
			'shield_early_enable'         => __( 'Turn on', 'rafoo' ),
			'shield_early_disable'        => __( 'Turn off', 'rafoo' ),
			'shield_early_active'         => __( 'Active before WordPress', 'rafoo' ),
			'shield_early_broken'         => __( 'Set up, but the shield file did not run', 'rafoo' ),
			'shield_early_conflict'       => __( 'Another tool runs first', 'rafoo' ),
			'shield_early_pending'        => __( 'Waiting for the server', 'rafoo' ),
			'shield_early_unsupported'    => __( 'Not available on this server', 'rafoo' ),
			'shield_early_off'            => __( 'Off', 'rafoo' ),
			/* translators: %d: minutes */
			'shield_early_pending_help'   => __( 'PHP reads the new setting within about %d minutes. Nothing else is needed.', 'rafoo' ),
			/* translators: %s: file name */
			'shield_early_conflict_help'  => __( 'The file %s already runs before WordPress. Rafoo keeps working as the first plugin so both work together.', 'rafoo' ),
			'shield_early_unsupported_help' => __( 'This server does not let a site set this up. The shield still runs as the first plugin.', 'rafoo' ),
			'shield_store_apcu'           => __( 'Counters are kept in memory (APCu), the fastest option.', 'rafoo' ),
			'shield_store_files'          => __( 'Counters are kept in small files in a protected folder. With APCu on the server they would be kept in memory.', 'rafoo' ),
			'shield_limit_note'           => __( 'The shield stops attacks that reach the site. A flood that fills the server’s network line before reaching PHP can only be stopped at the edge: connect ArvanCloud or Cloudflare below.', 'rafoo' ),
		);
	}

	private static function details() {
		return array(
			'shield_groups_title'         => __( 'Attack types', 'rafoo' ),
			'shield_ips_title'            => __( 'Most active addresses', 'rafoo' ),
			'shield_paths_title'          => __( 'Most targeted paths', 'rafoo' ),
			'shield_none_week'            => __( 'Nothing in the last 7 days.', 'rafoo' ),
			'shield_events_title'         => __( 'Recent events', 'rafoo' ),
			'shield_events_text'          => __( 'If a real visitor was stopped, allow their address. The reference code is the one shown on the stop page.', 'rafoo' ),
			'shield_events_empty'         => __( 'No attack has been stopped yet.', 'rafoo' ),
			'shield_ref'                  => __( 'Ref.', 'rafoo' ),
			'shield_allow'                => __( 'Allow', 'rafoo' ),
			'shield_allowed'              => __( 'Allowed', 'rafoo' ),
			'shield_block'                => __( 'Block', 'rafoo' ),
			'shield_release'              => __( 'Unblock', 'rafoo' ),
			'shield_action_block'         => __( 'Stopped', 'rafoo' ),
			'shield_action_limit'         => __( 'Too many requests', 'rafoo' ),
			'shield_action_locked'        => __( 'Sign-in locked', 'rafoo' ),
			'shield_action_lock'          => __( 'Sign-in lock started', 'rafoo' ),
			'shield_action_challenge'     => __( 'Browser check', 'rafoo' ),
			'shield_group_sqli'           => __( 'SQL injection', 'rafoo' ),
			'shield_group_xss'            => __( 'Cross-site scripting', 'rafoo' ),
			'shield_group_lfi'            => __( 'File reading', 'rafoo' ),
			'shield_group_rce'            => __( 'Command execution', 'rafoo' ),
			'shield_group_object'         => __( 'Object injection', 'rafoo' ),
			'shield_group_upload'         => __( 'Dangerous upload', 'rafoo' ),
			'shield_group_ssrf'           => __( 'Server-side request forgery', 'rafoo' ),
			'shield_group_xxe'            => __( 'XML entity attack', 'rafoo' ),
			'shield_group_scanner'        => __( 'Vulnerability scanner', 'rafoo' ),
			'shield_group_sensitive'      => __( 'Sensitive file', 'rafoo' ),
			'shield_group_protocol'       => __( 'Malformed request', 'rafoo' ),
			'shield_group_wordpress'      => __( 'WordPress probing', 'rafoo' ),
			'shield_group_patch'          => __( 'Virtual patch', 'rafoo' ),
			'shield_group_login'          => __( 'Sign-in attempts', 'rafoo' ),
			'shield_group_flood'          => __( 'Request flood', 'rafoo' ),
			'shield_group_reputation'     => __( 'Repeat attacker', 'rafoo' ),
			'shield_group_network'        => __( 'Community list', 'rafoo' ),
			'shield_group_list'           => __( 'Your block list', 'rafoo' ),
			'shield_group_challenge'      => __( 'Browser check', 'rafoo' ),
			'shield_lists_title'          => __( 'Addresses', 'rafoo' ),
			/* translators: %s: the visitor's own IP address */
			'shield_lists_text'           => __( 'One IP address or range per line, for example 203.0.113.5 or 203.0.113.0/24. Your address now: %s', 'rafoo' ),
			'shield_allow_label'          => __( 'Always allow', 'rafoo' ),
			'shield_deny_label'           => __( 'Always block', 'rafoo' ),
			'shield_proxies_label'        => __( 'Your own proxies', 'rafoo' ),
			'shield_network_title'        => __( 'Community defense', 'rafoo' ),
			'shield_network_text'         => __( 'When several independent sites see the same attacker, rafoo.ir sends a signed list to every member, so the attacker is stopped here before trying.', 'rafoo' ),
			'shield_network_label'        => __( 'Community defense', 'rafoo' ),
			'shield_network_help'         => __( 'Shares the addresses this site stopped, with the attack type. Visitors who were not stopped, signed-in users and private addresses are never sent.', 'rafoo' ),
			'shield_network_joined'       => __( 'Member', 'rafoo' ),
			/* translators: 1: listed addresses, 2: addresses this site reported */
			'shield_network_stats'        => __( '%1$d addresses on the list, %2$d reported by this site', 'rafoo' ),
			'shield_network_waiting'      => __( 'Joining rafoo.ir. This takes a few minutes.', 'rafoo' ),
			'shield_network_sync'         => __( 'Update now', 'rafoo' ),
			'shield_patches_active'       => __( 'Virtual patches active on this site', 'rafoo' ),
			'shield_patches_none'         => __( 'No installed plugin needs a virtual patch right now.', 'rafoo' ),
			'shield_edge_title'           => __( 'Blocking at the CDN edge', 'rafoo' ),
			'shield_edge_text'            => __( 'Sends long blocks to your CDN so attackers are stopped before they reach your server. At most 200 addresses; they are removed when they expire.', 'rafoo' ),
			'shield_edge_provider'        => __( 'CDN', 'rafoo' ),
			'shield_edge_arvan'           => __( 'ArvanCloud', 'rafoo' ),
			'shield_edge_cloudflare'      => __( 'Cloudflare', 'rafoo' ),
			'shield_edge_domain'          => __( 'Domain in the CDN panel', 'rafoo' ),
			'shield_edge_reuse'           => __( 'Use the key saved for Geo DNS', 'rafoo' ),
			'shield_edge_reuse_help'      => __( 'The key needs permission to change firewall rules too.', 'rafoo' ),
			'shield_edge_key_arvan'       => __( 'ArvanCloud API key', 'rafoo' ),
			'shield_edge_key_arvan_help'  => __( 'From the ArvanCloud panel, Profile, API keys. It needs access to CDN firewall rules.', 'rafoo' ),
			'shield_edge_key_cloudflare'  => __( 'Cloudflare API token', 'rafoo' ),
			'shield_edge_key_cloudflare_help' => __( 'Create a token with Zone read and Firewall Services edit permission for this domain.', 'rafoo' ),
			'shield_edge_connect'         => __( 'Connect', 'rafoo' ),
			'shield_edge_disconnect'      => __( 'Disconnect', 'rafoo' ),
			'shield_edge_push'            => __( 'Send now', 'rafoo' ),
			/* translators: %d: number of IP addresses */
			'shield_edge_count'           => __( '%d addresses at the edge', 'rafoo' ),
			'event_shield_mode'           => __( 'Shield mode changed', 'rafoo' ),
			'event_shield_prepend_on'     => __( 'Shield protection before WordPress turned on', 'rafoo' ),
			'event_shield_prepend_off'    => __( 'Shield protection before WordPress turned off', 'rafoo' ),
			'event_shield_network_joined' => __( 'Joined community defense', 'rafoo' ),
			'event_shield_edge_on'        => __( 'CDN edge blocking connected', 'rafoo' ),
			'event_shield_edge_off'       => __( 'CDN edge blocking disconnected', 'rafoo' ),
		);
	}

	private static function help() {
		return array(
			'help_card_settings_security' => __( 'The Rafoo Shield firewall. The full view, lists and events are in the Shield tab.', 'rafoo' ),
			'help_card_shield'            => __( 'How many attacks the shield stopped. Fortress mode is for the hours of a heavy attack.', 'rafoo' ),
			'help_shield_mode'            => __( 'Smart is tuned so real visitors are never stopped. Strict lowers every threshold. Fortress asks each new visitor for a few-second browser check once every hour.', 'rafoo' ),
			'help_shield_early'           => __( 'Without this, the shield runs when Rafoo loads, which is already before every other plugin. Before WordPress also protects WordPress core files and saves server work under attack.', 'rafoo' ),
			'help_shield_groups'          => __( 'What the stopped requests tried to do.', 'rafoo' ),
			'help_shield_ips'             => __( 'Addresses with the most stopped requests in 7 days.', 'rafoo' ),
			'help_shield_paths'           => __( 'Addresses on your site that attackers targeted most.', 'rafoo' ),
			'help_shield_events'          => __( 'Stops, locks and browser checks. Repeated stops from one address are counted but listed once a minute.', 'rafoo' ),
			'help_shield_lists'           => __( 'Your own exceptions. They apply before every rule and to every mode.', 'rafoo' ),
			'help_shield_allow'           => __( 'These addresses are never checked, for example your office or a payment service.', 'rafoo' ),
			'help_shield_deny'            => __( 'These addresses are always stopped. Your own address cannot be added.', 'rafoo' ),
			'help_shield_proxies'         => __( 'If the site sits behind your own proxy or load balancer, add its address so the visitor’s real address is read. ArvanCloud and Cloudflare are recognized automatically.', 'rafoo' ),
			'help_shield_network'         => __( 'An address is listed only when at least three sites on two different networks report it. Iranian addresses get a browser check instead of a block, because many visitors share one address on mobile networks.', 'rafoo' ),
			'help_shield_network_toggle'  => __( 'Uses the same signing key as the cooperative network. Turning it off deletes the list and stops reports.', 'rafoo' ),
			'help_shield_edge'            => __( 'The key is stored encrypted. Only blocks of an hour or more are sent.', 'rafoo' ),
		);
	}
}
