<?php
namespace TagForge;
if ( ! defined( 'ABSPATH' ) ) exit;
class Helpers {
    public static function get_options() : array {
        $defaults = ['default_modules_csv'=>'','expiry_days'=>3,'debug'=>0,'email_customer'=>0,'email_admin'=>0,'admin_email'=>get_option('admin_email'),'email_subject'=>'Your GTM container is forged and ready','email_body'=>"Hi {customer_name},\n\nYour GTM container has been forged and is ready to import.\n\nModules: {modules}\nGA4: {ga4}\nDownload (expires {expires_date}):\n{download_url}\n\nThanks,\nTagForge",];
        $opts = (array) get_option('tagforge_options', []);
        return wp_parse_args($opts, $defaults);
    }
    public static function debug_enabled() : bool { $opts = self::get_options(); $enabled = defined('TAGFORGE_DEBUG') ? TAGFORGE_DEBUG : !empty($opts['debug']); return (bool) apply_filters('tagforge_debug', $enabled); }
    public static function dbg($order_or_null, string $msg) : void { $line = '[TagForge] ' . $msg; error_log($line); if ($order_or_null instanceof \WC_Order && self::debug_enabled()) $order_or_null->add_order_note($line); }
    public static function uploads_dir() : array { $uploads = wp_get_upload_dir(); $base = trailingslashit($uploads['basedir']) . TAGFORGE_UPLOAD_SUBDIR . '/'; if ( ! file_exists($base) ) wp_mkdir_p($base); return ['basedir' => $base, 'baseurl' => trailingslashit($uploads['baseurl']) . TAGFORGE_UPLOAD_SUBDIR . '/']; }
    public static function download_url(string $absolute_path, int $expires_ts, int $order_id) : string { $data = $absolute_path . '|' . $order_id . '|' . $expires_ts; $token = wp_hash($data, 'tagforge'); set_transient("tagforge_download_{$token}", $absolute_path, max(60, $expires_ts - time())); return add_query_arg(['action'=>'tagforge_download','token'=>$token], admin_url('admin-post.php')); }
    public static function sanitize_label($s) : string { if (is_array($s)) { $s = implode(' ', array_map('wp_strip_all_tags', $s)); } elseif (!is_string($s)) { $s = (string) $s; } $s = wp_strip_all_tags($s); return trim($s); }
    public static function deep_replace(&$node, array $vars) : void { if (is_array($node)) { foreach ($node as &$v) self::deep_replace($v, $vars); } elseif (is_string($node)) { foreach ($vars as $k => $val) { $node = str_replace('{{'.$k.'}}', $val, $node); } } }
    public static function normalize_multivalue($value) : array { if (is_array($value)) { $out=[]; foreach($value as $v){ if (is_array($v)) $v=implode(' ',$v); $v=self::sanitize_label($v); if($v!=='') $out[]=$v; } return $out; } if (is_string($value)) { if (strpos($value, ',') !== false) return array_values(array_filter(array_map('trim', explode(',', $value)))); $value = trim($value); return $value === '' ? [] : [$value]; } if (is_object($value)) $value = (string)$value; $value = trim((string)$value); return $value === '' ? [] : [$value]; }
    public static function looks_like_ga4(string $s) : bool { return (bool) preg_match('/^G-[A-Z0-9]+$/i', $s); }
}
