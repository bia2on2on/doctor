<?php

declare(strict_types=1);

namespace {
    /**
     * Stubs سبک WP — فقط آنچه برای مسیر wrap() این کنترلر لمس می‌شود.
     * تعریف در namespace سراسری انجام می‌شود چون RestBase کلاس‌های WP را بدون
     * qualification صدا می‌زند. اگر کلاس‌های واقعی WP از قبل بارگذاری شده باشند
     * (مثلاً زیر WP Test Suite)، از تعریف مجدد صرف‌نظر می‌شود.
     */
    if ( ! class_exists( 'WP_Error' ) ) {
        /**
         * حداقل WP_Error: همان امضایی که RestBase::error() استفاده می‌کند
         * (code + message + data['status']).
         */
        // Test-scope WP stub, guarded by class_exists (tests are outside the WPCS ruleset).
        class WP_Error
        {
            private string $code;
            private string $message;
            /** @var array<string, mixed> */
            private array $data;

            /** @param array<string, mixed> $data */
            public function __construct( string $code = '', string $message = '', array $data = [] )
            {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = $data;
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            /** @return array<string, mixed> */
            public function get_error_data( $key = '' ): array // WP signature retained.
            {
                return $this->data;
            }
        }
    }

    if ( ! class_exists( 'WP_REST_Response' ) ) {
        /**
         * حداقل WP_REST_Response: داده + status + هدرها (RestBase::success()).
         */
        // Test-scope WP stub, guarded by class_exists (tests are outside the WPCS ruleset).
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            /** @var int */
            public $status;
            /** @var array<string, string> */
            public $headers = [];

            /** @param mixed $data */
            public function __construct( $data = null, int $status = 200 )
            {
                $this->data   = $data;
                $this->status = $status;
            }

            public function header( string $key, string $value ): void
            {
                $this->headers[ $key ] = $value;
            }
        }
    }
}

namespace ClinicCore\Tests\Unit {

    use ClinicCore\Rest\BookingController;
    use PHPUnit\Framework\TestCase;

    /**
     * گارد affected-rows لغو نوبت (BookingService::cancel → updateStatus === 0)
     * عمداً RuntimeException پرتاب می‌کند. این تستْ نقشهٔ HTTP خطا را برای همان
     * مسیر ثابت می‌کند: پاکت داخلی CLINIC_INTERNAL_ERROR با HTTP 500 — بدون نشتِ
     * پیام خامِ داخلی به کلاینت؛ و تضمین می‌کند مسیر موفق wrap() دست‌نخورده است.
     *
     * اجرای واقعیِ خودِ گارد (تراکنش + rollback + نرسیدن به release_booking)
     * نیازمند بستر Integration (WP + MySQL 8/MariaDB 10.11/11.4.13) است؛
     * این تست فقط قرارداد envelope خطا را پوشش می‌دهد.
     */
    final class CancelZeroRowGuardErrorMappingTest extends TestCase
    {
        /** @return array{0: BookingController, 1: \ReflectionMethod} */
        private function controllerAndWrap(): array
        {
            $controller = ( new \ReflectionClass( BookingController::class ) )
                ->newInstanceWithoutConstructor();
            $wrap       = new \ReflectionMethod( BookingController::class, 'wrap' );
            $wrap->setAccessible( true );

            return [ $controller, $wrap ];
        }

        public function testZeroRowGuardRuntimeExceptionMapsToInternalError500(): void
        {
            [ $controller, $wrap ] = $this->controllerAndWrap();

            $result = $wrap->invoke(
                $controller,
                static function (): void {
                    throw new \RuntimeException( 'cancel appointment status update affected zero rows' );
                }
            );

            $this->assertInstanceOf( \WP_Error::class, $result );
            $this->assertSame( 'CLINIC_INTERNAL_ERROR', $result->get_error_code() );

            $data = $result->get_error_data();
            $this->assertIsArray( $data );
            $this->assertSame( 500, $data['status'] );

            // پیام خام داخلی نباید به کلاینت برسد
            $this->assertStringNotContainsString( 'zero rows', $result->get_error_message() );
        }

        public function testWrapSuccessPathStillBuildsData200Envelope(): void
        {
            [ $controller, $wrap ] = $this->controllerAndWrap();

            $result = $wrap->invoke(
                $controller,
                static fn (): array => [ 'appointment_id' => 42, 'status' => 'cancelled' ]
            );

            $this->assertInstanceOf( \WP_REST_Response::class, $result );
            $this->assertSame( 200, $result->status );
            $this->assertSame( [ 'data' => [ 'appointment_id' => 42, 'status' => 'cancelled' ] ], $result->data );
        }
    }
}
