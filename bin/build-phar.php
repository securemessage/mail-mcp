#!/usr/bin/env php
<?php
/**
 * Mail MCP Server — PHAR Builder
 *
 * Builds a self-contained .phar archive for distribution.
 * This builder follows a reusable pattern for Enchilada MCP projects.
 *
 * Usage: php -d phar.readonly=0 bin/build-phar.php
 *
 * @package    MailMCP
 * @author     Daniel Morante
 * @copyright  2026 The Daniel Morante Company, Inc.
 * @license    BSD-2-Clause
 */

if (ini_get('phar.readonly')) {
	echo "Error: phar.readonly must be disabled. Run with:\n";
	echo "  php -d phar.readonly=0 bin/build-phar.php\n";
	exit(1);
}

// ── Configuration ──────────────────────────────────────────────

$baseDir    = dirname(__DIR__);
$pharName   = 'mail-mcp.phar';
$pharPath   = $baseDir . '/' . $pharName;
$slug       = 'mail-mcp';
$envVar     = 'MAIL_MCP_CONFIG';
$entryPoint = 'bin/mail-mcp';

// Directories to include in the PHAR
$includeDirs = ['system', 'classes', 'libraries', 'tools', 'includes'];

// Extra files to include (relative to base)
$extraFiles = [
	'config/instances.json.sample',
	'config/instructions.txt',
];

// ── Build ──────────────────────────────────────────────────────

if (file_exists($pharPath)) {
	unlink($pharPath);
}

echo "Building {$pharName}...\n";

$phar = new Phar($pharPath);
$phar->startBuffering();

// Add source directories
$fileCount = 0;
foreach ($includeDirs as $dir) {
	$fullDir = $baseDir . '/' . $dir;
	if (!is_dir($fullDir)) continue;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($fullDir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		$ext = $file->getExtension();
		if (in_array($ext, ['php', 'inc', 'txt'])) {
			$localPath = $dir . '/' . $iterator->getSubPathname();
			$phar->addFile($file->getPathname(), $localPath);
			$fileCount++;
		}
	}
}

// Add entry point
$phar->addFile($baseDir . '/' . $entryPoint, $entryPoint);
$fileCount++;

// Add extra files
foreach ($extraFiles as $f) {
	$fullPath = $baseDir . '/' . $f;
	if (file_exists($fullPath)) {
		$phar->addFile($fullPath, $f);
		$fileCount++;
	}
}

// ── Discover tool classes at build time ────────────────────────

$toolClasses = [];
foreach (glob($baseDir . '/tools/*.php') as $f) {
	$toolClasses[] = basename($f, '.php');
}

// ── Read app.conf.php to extract version for stamp ─────────────

$appConf = file_get_contents($baseDir . '/system/app.conf.php');
preg_match("/define\('APPLICATION_VERSION',\s*'([^']+)'\)/", $appConf, $vMatch);
$version = $vMatch[1] ?? 'unknown';

// ── Read instructions ──────────────────────────────────────────

$instructionsFile = $baseDir . '/config/instructions.txt';
$instructions = file_exists($instructionsFile)
	? trim(file_get_contents($instructionsFile))
	: '';

// ── Generate Stub ──────────────────────────────────────────────
// The stub replaces the normal Enchilada bootstrap because chdir()
// does not work with phar:// URIs. It sets up constants with phar://
// paths, loads the autoloader, requires tool files, and runs the
// MCP server — mirroring the bin/ entry point logic exactly.

$toolListPhp    = "['" . implode("','", $toolClasses) . "']";
$instructionsPhp = var_export($instructions, true);

$stub = <<<STUB
#!/usr/bin/env php
<?php
/**
 * Mail MCP Server — PHAR Stub (auto-generated)
 * Version: {$version} | Built: %BUILD_DATE%
 */

Phar::mapPhar('{$pharName}');
\$pharRoot = 'phar://{$pharName}/';

// ── Bootstrap (replaces system/bootstrap.inc.php for phar context) ──

// Load app constants (APPLICATION_ROOT will be phar:// path)
require_once \$pharRoot . 'system/app.conf.php';

// Override paths for phar:// context
define('APPLICATION_LIBDIR', \$pharRoot . 'libraries/');
define('APPLICATION_CLASSDIR', \$pharRoot . 'classes/');

// Load autoloader
require_once \$pharRoot . 'system/autoload.inc.php';

// Load include components (tools autoloader, etc.)
foreach (glob(\$pharRoot . 'includes/*.inc.php') as \$incFile) {
    require_once \$incFile;
}

if (defined('APPLICATION_DEBUG') && APPLICATION_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
date_default_timezone_set(defined('APPLICATION_TIMEZONE') ? APPLICATION_TIMEZONE : 'UTC');

// ── Configuration ──

use EnchiladaMCP\McpServer;
use EnchiladaMCP\StdioTransport;
use Mail\InstanceManager;

\$configPath = getenv('{$envVar}') ?: null;
foreach (\$argv ?? [] as \$arg) {
    if (str_starts_with(\$arg, '--config=')) {
        \$configPath = substr(\$arg, 9);
    }
}
if (\$configPath === null) {
    \$candidates = [
        getenv('HOME') . '/.config/{$slug}/instances.json',
        '/usr/local/etc/{$slug}/instances.json',
    ];
    foreach (\$candidates as \$c) {
        if (file_exists(\$c)) { \$configPath = \$c; break; }
    }
}
if (\$configPath === null || !file_exists(\$configPath)) {
    fwrite(STDERR, "[{$slug}] ERROR: No config found.\\n");
    fwrite(STDERR, "  Set {$envVar} env var or use --config=/path/to/instances.json\\n");
    fwrite(STDERR, "  Or place instances.json in ~/.config/{$slug}/\\n");
    exit(1);
}

// ── Run ──

function debug(string \$m): void { fwrite(STDERR, "[{$slug}] " . \$m . "\\n"); }

try {
    \$manager = InstanceManager::fromFile(\$configPath);
} catch (\Exception \$e) {
    fwrite(STDERR, "[{$slug}] ERROR: " . \$e->getMessage() . "\\n");
    exit(1);
}

debug("Loaded " . \$manager->count() . " instance(s) from {\$configPath} (default: " . \$manager->getDefault() . ")");

\$server = new McpServer(APPLICATION_SLUG, APPLICATION_VERSION);
\$server->setInstructions({$instructionsPhp});

\$toolClasses = {$toolListPhp};
foreach (\$toolClasses as \$cls) {
    require_once \$pharRoot . 'tools/' . \$cls . '.php';
    \$server->register(new \$cls(\$manager));
    debug("Registered tools: {\$cls}");
}

debug("MCP server started (stdio transport, PHAR)");
\$transport = new StdioTransport(\$server);
\$transport->setLogger('debug');
\$transport->run();
debug("MCP server stopped");

__HALT_COMPILER();
STUB;

// Stamp build date
$stub = str_replace('%BUILD_DATE%', date('Y-m-d H:i:s T'), $stub);

$phar->setStub($stub);
$phar->stopBuffering();

// Make executable
chmod($pharPath, 0755);

$size = round(filesize($pharPath) / 1024, 1);
echo "Built: {$pharPath} ({$size} KB, {$fileCount} files)\n";
echo "Version: {$version}\n";
echo "Tools: " . implode(', ', $toolClasses) . "\n";
echo "Test:  php {$pharName} --config=/path/to/instances.json\n";
