<?php
/**
 * Fresh-process bootstrap / SDK construction probe. Test-only, never shipped.
 * No bucket, command, request, environment (getenv) reader or production
 * credentials. Slice 2B block exercises the constants-backed deployment
 * configuration contract with clearly non-production fixture constants.
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

    // -------------------------------------------------------------------------
    // Phase 15 Slice 2B: constants-backed deployment configuration + keyless
    // no-network S3Client factory on the SAME packaged runtime. Clearly
    // non-production fixtures; zero bucket operations.
    // -------------------------------------------------------------------------
    define('CPMS_S3_ENDPOINT', 'https://cpms-sdk-smoke.invalid');
    define('CPMS_S3_REGION', 'us-east-1');
    define('CPMS_S3_BUCKET', 'cpms_smoke_only_bucket');
    define('CPMS_S3_PREFIX', 'cpms//smoke/prefix/');
    define('CPMS_S3_PATH_STYLE', true);
    define('CPMS_S3_ACCESS_KEY_ID', 'CPMS_SMOKE_ONLY_NOT_PRODUCTION');
    define('CPMS_S3_SECRET_ACCESS_KEY', 'CPMS_SMOKE_ONLY_NOT_PRODUCTION');
    $smoke_key_b64 = base64_encode(random_bytes(32));
    define('CPMS_BACKUP_ENCRYPTION_KEY_B64', $smoke_key_b64);

    $config_2b = \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig::fromDeploymentConstants();
    check($config_2b instanceof \ClinicCore\Infrastructure\Backup\S3BackupDeploymentConfig,
          'Slice 2B: deployment constants did not resolve to a configured object');
    $transport_2b = $config_2b->transportSettings();
    $calls_2b = 0;
    $client_2b = \ClinicCore\Infrastructure\Backup\S3BackupClientFactory::create(
        $transport_2b,
        static function () use (&$calls_2b): never {
            ++$calls_2b;
            throw new \RuntimeException('Network is forbidden in the release SDK smoke');
        }
    );
    check($calls_2b === 0, 'Slice 2B factory attempted HTTP');
    check((string) $client_2b->getEndpoint() === 'https://cpms-sdk-smoke.invalid', 'Slice 2B: wrong endpoint');
    check($client_2b->getRegion() === 'us-east-1', 'Slice 2B: wrong region');
    check($client_2b->getConfig('use_path_style_endpoint') === true, 'Slice 2B: path style not explicit');
    check($client_2b->getConfig('signature_version') === 'v4', 'Slice 2B: SigV4 configuration missing');
    $http_2b = (new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions'))->getValue($client_2b);
    check($http_2b['verify'] === true, 'Slice 2B: TLS verification must stay enabled');
    check($client_2b->getCredentials()->wait()->getAccessKeyId() === 'CPMS_SMOKE_ONLY_NOT_PRODUCTION',
          'Slice 2B: credentials must be the explicit non-production fixture');
    // The backup encryption key must never enter the SDK configuration path.
    $decoded_2b = base64_decode($smoke_key_b64, true);
    $sdk_config_2b = var_export($client_2b->getConfig(), true);
    check(is_string($decoded_2b) && strlen($decoded_2b) === 32, 'Slice 2B: smoke key fixture is 32 bytes');
    check(!str_contains($sdk_config_2b, $smoke_key_b64)
          && !str_contains($sdk_config_2b, (string) $decoded_2b)
          && !str_contains($sdk_config_2b, bin2hex((string) $decoded_2b)),
          'Slice 2B: encryption key material leaked into the SDK configuration');
    // The transport settings view must carry no encryption accessor at all.
    foreach ((new \ReflectionClass(\ClinicCore\Infrastructure\Backup\S3BackupTransportSettings::class))->getMethods() as $method_2b) {
        check(!str_contains(strtolower($method_2b->getName()), 'encrypt'), 'Slice 2B: transport settings exposed an encryption accessor');
    }
    unset($config_2b, $transport_2b, $client_2b, $http_2b, $decoded_2b, $sdk_config_2b, $smoke_key_b64);

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
        . "explicit dummy credentials; HTTP calls = 0; Slice 2B constants->config->keyless factory->client with 0 HTTP calls "
        . "and no key material in the SDK configuration; PHP " . PHP_VERSION . "\n";
}
