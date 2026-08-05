<?php
/**
 * Copyright since 2026 Mateusz Bartocha
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to contact@bestcoding.net so we can send you a copy immediately.
 *
 * @author    Mateusz Bartocha <contact@bestcoding.net>
 * @copyright Since 2026 Mateusz Bartocha
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

use Bccli\Thumbnails\RunLock;

$path = sys_get_temp_dir() . '/bccli-test-' . getmypid() . '.lock';
@unlink($path);

$lock = new RunLock($path);
bccli_assert($lock->acquire(), 'an uncontended lock is acquired');

// A second PHP process must NOT be able to acquire it while we hold it.
$probe = tempnam(sys_get_temp_dir(), 'bccli-probe');
file_put_contents($probe, '<?php
$fh = fopen(' . var_export($path, true) . ', "c");
echo flock($fh, LOCK_EX | LOCK_NB) ? "ACQUIRED" : "BLOCKED";
');
$result = shell_exec(PHP_BINARY . ' ' . escapeshellarg($probe));
bccli_assert_same('BLOCKED', trim((string) $result), 'a second process is blocked while the lock is held');

$lock->release();
$result = shell_exec(PHP_BINARY . ' ' . escapeshellarg($probe));
bccli_assert_same('ACQUIRED', trim((string) $result), 'the lock is available again after release');

$lock->release();
bccli_assert(true, 'releasing twice does not error');

@unlink($probe);
@unlink($path);
