<?php
/**
 * Fresh-process bootstrap / SDK construction probe. Test-only, never shipped.
 * No bucket, command, request, environment reader or production credentials.
 */

declare(strict_types=1);

namespace ClinicCore\Bootstrap {
    // Only isolate hook registration from WordPress; existing real-WP gates
    // exercise the real App. Do not preload a Composer/application autoloader.
    final class App
    {
        public static function boot(): void {}
    }
}

namespace {
    function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
    function plugin_dir_url(string $file): string { return 'https://cpms-smoke.invalid/'; }
    function register_activation_hook(string $file, array $callback): void {}
    function register_deactivation_hook(string $file, array $callback): void {}

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $root = $argv[1];
    $mode = $argv[2];
    define('ABSPATH', $root . '/');
    require $root . '/clinic-practice-management.php';
    check(class_exists(\ClinicCore\Domain\Backup\BackupManifest::class), 'Application autoload failed');

    if ($mode === 'source') {
        check(!is_dir($root . '/vendor'), 'Source fixture unexpectedly contains vendor');
        check(!class_exists(\Aws\S3\S3Client::class), 'SDK leaked in from outside source fixture');
        echo "PASS: source bootstrap without vendor; application fallback works\n";
        exit(0);
    }

    check(class_exists(\Aws\S3\S3Client::class), 'Plugin bootstrap did not load the packaged Composer autoloader');
    check(\Aws\Sdk::VERSION === '3.399.0', 'Unexpected SDK version');
    $calls = 0;
    // Clearly non-production fixtures. Explicit credentials prevent provider-chain
    // discovery (including metadata services). A throwing handler forbids HTTP.
    $client = new \Aws\S3\S3Client([
        'version' => '2006-03-01',
        'endpoint' => 'https://cpms-sdk-smoke.invalid',
        'region' => 'us-east-1',
        'use_path_style_endpoint' => true,
        'signature_version' => 'v4',
        'credentials' => [
            'key' => 'CPMS_SMOKE_ONLY_NOT_PRODUCTION',
            'secret' => 'CPMS_SMOKE_ONLY_NOT_PRODUCTION',
        ],
        'http' => ['verify' => true],
        'csm' => false,
        'http_handler' => static function () use (&$calls): never {
            ++$calls;
            throw new \RuntimeException('Network is forbidden in the release SDK smoke');
        },
    ]);
    check((string) $client->getEndpoint() === 'https://cpms-sdk-smoke.invalid', 'Wrong HTTPS endpoint');
    check($client->getRegion() === 'us-east-1', 'Wrong region');
    check($client->getConfig('use_path_style_endpoint') === true, 'Path-style must be explicit');
    check($client->getConfig('signature_version') === 'v4', 'SigV4 configuration missing');
    // HTTP is a value option, not exposed by getConfig(). Read its stored default
    // without creating/executing an S3 command (official 3.399.0 AwsClient field).
    $http = (new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions'))->getValue($client);
    check($http['verify'] === true, 'TLS verification must stay enabled');
    check($client->getCredentials()->wait()->getAccessKeyId() === 'CPMS_SMOKE_ONLY_NOT_PRODUCTION',
          'Credentials must be the explicit non-production fixture');
    check($calls === 0, 'SDK construction attempted HTTP');
    check(\Composer\InstalledVersions::getVersion('aws/aws-sdk-php') === '3.399.0.0', 'Runtime install metadata missing');
    $lock = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
    $expected = [];
    foreach ($lock['packages'] as $package) {
        $expected[] = $package['name'];
        check(\Composer\InstalledVersions::getPrettyVersion($package['name']) === $package['version'],
              'Installed runtime version differs from committed lock: ' . $package['name']);
    }
    $rootPackage = \Composer\InstalledVersions::getRootPackage();
    // getInstalledPackages() includes virtual PSR "-implementation" provides.
    // Count concrete installed packages, not those non-file aliases.
    $actual = [];
    foreach (\Composer\InstalledVersions::getInstalledPackages() as $name) {
        if ($name !== $rootPackage['name'] && \Composer\InstalledVersions::getInstallPath($name) !== null) {
            $actual[] = $name;
        }
    }
    sort($actual);
    sort($expected);
    check($actual === $expected && $rootPackage['dev'] === false,
          'Runtime metadata contains dev/unlocked packages: ' . json_encode([
              'actual' => $actual, 'expected' => $expected, 'dev' => $rootPackage['dev'],
          ], JSON_THROW_ON_ERROR));
    echo 'PASS: built plugin autoload -> official S3Client 3.399.0, HTTPS, region, path-style, SigV4, TLS verify; '
        . "explicit dummy credentials; HTTP calls = 0; PHP " . PHP_VERSION . "\n";
}
