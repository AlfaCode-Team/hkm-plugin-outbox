<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * THE KERNEL'S `vendor/autoload.php` IS REQUIRED, not just its `src/`. The
 * integration suite runs the real migration through LetMigrate and points a
 * real `DatabasePort` at the result, using `AlfacodeTeam\Ground\Fakes\
 * MigratedSqlite` — so `AlfaCode\LetMigrate\` and `AlfacodeTeam\Ground\` both
 * have to be autoloadable, and a hand-written map for this plugin's own
 * namespace cannot provide them.
 *
 * Order, most specific first:
 *   1. HKM_KERNEL_PATH          — an explicit checkout
 *   2. vendor/autoload.php      — composer-installed (CI)
 *   3. ../../../PHP             — the conventional workspace layout
 */

$pluginRoot = \dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    // Test support first: `Tests\Plugins\Outbox\` is not matched by the
    // `Plugins\Outbox\` prefix, but listing it second would still be wrong the
    // day someone reorders them — so the order is explicit and commented.
    $map = [
        'Tests\\Plugins\\Outbox\\' => $pluginRoot . '/tests/',
        'Plugins\\Outbox\\'        => $pluginRoot . '/',
    ];

    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, \strlen($prefix));
        $path     = $base . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});

$kernelRoot = getenv('HKM_KERNEL_PATH') ?: null;

if ($kernelRoot === null && is_file($pluginRoot . '/vendor/autoload.php')) {
    require_once $pluginRoot . '/vendor/autoload.php';
    $kernelRoot = false; // composer-installed: everything is already on the path
}

if ($kernelRoot !== false) {
    $kernelRoot ??= \dirname($pluginRoot, 3) . '/PHP';

    if (!is_dir($kernelRoot . '/src/Kernel')) {
        fwrite(STDERR, \sprintf(
            "\nCannot find the kernel. Looked in %s.\nSet HKM_KERNEL_PATH to a checkout, or run"
            . " `composer install`.\n\n",
            $kernelRoot,
        ));
        exit(1);
    }

    // The kernel's own composer autoloader, which carries the path packages:
    // alfacode-team/ground (MigratedSqlite, FrozenClock) and
    // alfacode-team/let-migrate (the migration runner the integration suite
    // needs). Falling back to a bare src/ map would leave the integration suite
    // failing on a missing class rather than on the code under test.
    if (is_file($kernelRoot . '/vendor/autoload.php')) {
        require_once $kernelRoot . '/vendor/autoload.php';
    } else {
        fwrite(STDERR, \sprintf(
            "\nFound the kernel at %s but it has no vendor/autoload.php.\n"
            . "Run `composer install` there — the integration suite needs LetMigrate and Ground.\n\n",
            $kernelRoot,
        ));
        exit(1);
    }

    if (is_file($kernelRoot . '/src/Kernel/Support/helpers.php')) {
        require_once $kernelRoot . '/src/Kernel/Support/helpers.php';
    }
}
