<?php

declare(strict_types=1);

use ClinicCore\Infrastructure\Db\CpmsDb;

/** Preserve historical values; never roll back while prescription pages exist. */
return [
    'version' => '2026_09_26_0023',
    'description' => 'Add prescription to handwriting background_template enum',
    'up' => static function ( CpmsDb $db ): void {
        $table = $db->table( 'cpms_handwriting_pages' );
        $column = $db->fetchRow( "SHOW COLUMNS FROM {$table} LIKE 'background_template'" );
        $old = "enum('blank','lined','graph','form')";
        $new = "enum('blank','lined','graph','form','prescription')";
        $type = strtolower( (string) ( $column['Type'] ?? '' ) );
        if ( $type === $new ) {
            return;
        }
        if ( $type !== $old ) {
            throw new RuntimeException( 'Unexpected handwriting background_template schema; no change made.' );
        }
        $db->query( "ALTER TABLE {$table} MODIFY COLUMN `background_template` ENUM('blank','lined','graph','form','prescription') NOT NULL DEFAULT 'lined'" );
    },
    'down' => static function ( CpmsDb $db ): void {
        $table = $db->table( 'cpms_handwriting_pages' );
        $count = (int) $db->fetchValue( "SELECT COUNT(*) FROM {$table} WHERE background_template = 'prescription'" );
        if ( $count > 0 ) {
            throw new RuntimeException( 'Cannot roll back prescription stationery while prescription pages exist.' );
        }
        $db->query( "ALTER TABLE {$table} MODIFY COLUMN `background_template` ENUM('blank','lined','graph','form') NOT NULL DEFAULT 'lined'" );
    },
];
