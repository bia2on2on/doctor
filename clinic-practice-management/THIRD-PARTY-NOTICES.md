# Third-party runtime notices

CPMS is proprietary. The following separately licensed runtime components are
included in the release ZIP; their licenses do not change CPMS's own license.
Versions below correspond to the repository's committed Composer lock.

| Component | Locked version | License | Included license text |
| --- | --- | --- | --- |
| aws/aws-crt-php | v1.2.7 | Apache-2.0 | vendor/aws/aws-crt-php/LICENSE |
| aws/aws-sdk-php | 3.399.0 | Apache-2.0 | vendor/aws/aws-sdk-php/LICENSE |
| guzzlehttp/guzzle | 8.2.0 | MIT | vendor/guzzlehttp/guzzle/LICENSE |
| guzzlehttp/promises | 3.0.2 | MIT | vendor/guzzlehttp/promises/LICENSE |
| guzzlehttp/psr7 | 3.1.0 | MIT | vendor/guzzlehttp/psr7/LICENSE |
| mtdowling/jmespath.php | 2.9.2 | MIT | vendor/mtdowling/jmespath.php/LICENSE |
| psr/http-client | 1.0.3 | MIT | vendor/psr/http-client/LICENSE |
| psr/http-factory | 1.1.0 | MIT | vendor/psr/http-factory/LICENSE |
| psr/http-message | 2.0 | MIT | vendor/psr/http-message/LICENSE |
| symfony/filesystem | v6.4.45 | MIT | vendor/symfony/filesystem/LICENSE |
| symfony/polyfill-ctype | v1.37.0 | MIT | vendor/symfony/polyfill-ctype/LICENSE |
| symfony/polyfill-mbstring | v1.43.0 | MIT | vendor/symfony/polyfill-mbstring/LICENSE |
| symfony/polyfill-php80 | v1.43.0 | MIT | vendor/symfony/polyfill-php80/LICENSE |
| symfony/polyfill-php82 | v1.43.0 | MIT | vendor/symfony/polyfill-php82/LICENSE |

Upstream copyright notices and complete license texts are retained at these
paths, including Apache-2.0's terms and MIT's copyright/permission notices.
The upstream AWS notices are also included as `vendor/aws/aws-sdk-php/NOTICE`
and `vendor/aws/aws-crt-php/NOTICE`. The SDK's `THIRD-PARTY-LICENSES` is retained.
Composer-generated runtime loader code is MIT-licensed; its license is included
at `vendor/composer/LICENSE`.

Upstream runtime code is not edited. Only development/build tools, tests,
documentation and temporary package-manager metadata are omitted from the ZIP.
The full SDK runtime/service models remain included. Composer's project manifests
are repository/build inputs, not runtime requirements, and are not shipped.

This dependency foundation does not implement S3 backup, send S3 requests, or
provide remote backup protection. No credentials are included in the release.
