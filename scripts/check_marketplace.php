<?php
// Local metadata preflight. This script is not shipped inside the module.
namespace Bitrix\Main\Localization {
    class Loc {
        private static $messages = [];
        public static function loadMessages($file) {
            $MESS = [];
            require dirname(__DIR__) . '/lang/ru/install/index.php';
            self::$messages = $MESS;
        }
        public static function getMessage($key) { return self::$messages[$key] ?? null; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('B_PROLOG_INCLUDED', true);
    class CModule {}
    $root = dirname(__DIR__);
    $expected = $argv[1] ?? '';
    if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $expected)) {
        fwrite(STDERR, "Usage: php scripts/check_marketplace.php <exact module code from Marketplace card>\n");
        exit(2);
    }
    require $root . '/install/index.php';
    $module = new pv_abandonedcart();
    $errors = [];
    if ($module->MODULE_ID !== $expected) { $errors[] = 'MODULE_ID does not match the Marketplace card: ' . $module->MODULE_ID; }
    if (get_class($module) !== str_replace('.', '_', $expected)) { $errors[] = 'Installer class does not match the Marketplace card'; }
    foreach (['MODULE_NAME','MODULE_DESCRIPTION','PARTNER_NAME'] as $field) {
        if (!is_string($module->$field) || trim($module->$field) === '') { $errors[] = $field . ' is empty'; }
    }
    $url = parse_url($module->PARTNER_URI);
    if (!filter_var($module->PARTNER_URI, FILTER_VALIDATE_URL) || !in_array($url['scheme'] ?? '', ['https','http'], true)) {
        $errors[] = 'PARTNER_URI must contain the actual developer website URL';
    }
    if (!preg_match('/^\d+\.\d+\.\d+$/D', $module->MODULE_VERSION)) { $errors[] = 'Invalid module version'; }
    if (strtotime($module->MODULE_VERSION_DATE) === false) { $errors[] = 'Invalid module release date'; }
    foreach (['install/index.php','install/version.php','include.php','lang/ru/install/index.php','lang/en/install/index.php'] as $file) {
        if (!is_file($root . '/' . $file)) { $errors[] = 'Missing file: ' . $file; continue; }
        $contents = file_get_contents($root . '/' . $file);
        if (substr($contents, 0, 3) === "\xef\xbb\xbf") { $errors[] = 'UTF-8 BOM: ' . $file; }
        if (!mb_check_encoding($contents, 'UTF-8')) { $errors[] = 'Invalid UTF-8: ' . $file; }
    }
    echo json_encode([
        'module_id'=>$module->MODULE_ID, 'class'=>get_class($module), 'version'=>$module->MODULE_VERSION,
        'partner_name'=>$module->PARTNER_NAME, 'partner_uri'=>$module->PARTNER_URI,
        'errors'=>$errors, 'scope'=>'Local metadata checks only; does not establish compliance with current Marketplace rules.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($errors ? 1 : 0);
}
