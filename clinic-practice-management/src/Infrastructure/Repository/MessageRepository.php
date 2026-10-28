<?php

declare(strict_types=1);

namespace ClinicCore\Infrastructure\Repository;

use ClinicCore\Infrastructure\Db\CpmsDb;

/**
 * Data access for internal staff messaging (migration 0030).
 *
 * Every read is Clinic-scoped by the query's own WHERE; Organization is added
 * when the trusted scope carries it. Messages are INSERT-only: this repository
 * deliberately exposes no update/delete for a message body (retention policy).
 */
final class MessageRepository {

    public function __construct( private readonly CpmsDb $db ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function create_thread( array $row ): int {
        $row += [
            'location_id'     => null,
            'patient_id'      => null,
            'visit_id'        => null,
            'last_message_id' => 0,
            'last_message_at' => null,
            'created_at'      => $this->db->nowUtcSql(),
        ];
        $this->db->insert( 'cpms_message_threads', $row );

        return $this->db->wpdb_last_insert_id();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_thread( int $thread_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_message_threads' ) . ' WHERE id = %d',
            [$thread_id]
        );
    }

    public function add_participant( int $thread_id, int $wp_user_id, string $staff_class ): void {
        $this->db->insert(
            'cpms_message_participants',
            [
				'thread_id'            => $thread_id,
				'wp_user_id'           => $wp_user_id,
				'staff_class'          => $staff_class,
				'last_read_message_id' => 0,
				'created_at'           => $this->db->nowUtcSql(),
			]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function participant( int $thread_id, int $wp_user_id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_message_participants' )
            . ' WHERE thread_id = %d AND wp_user_id = %d',
            [$thread_id, $wp_user_id]
        );
    }

    /**
     * Active members of one Clinic (display list is built by the service).
     *
     * @return list<int>
     */
    public function active_member_ids( int $clinic_id, int $except_wp_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT wp_user_id FROM ' . $this->db->table( 'cpms_clinic_memberships' )
            . " WHERE clinic_id = %d AND status = 'active' AND wp_user_id != %d"
            . ' ORDER BY wp_user_id ASC LIMIT 200',
            [$clinic_id, $except_wp_user_id]
        );

        return array_map( 'intval', array_column( is_array( $rows ) ? $rows : [], 'wp_user_id' ) );
    }

    /**
     * Threads of one actor, newest activity first, bounded.
     *
     * @param list<int>|null $location_ids null = actor may see every Location.
     * @return list<array<string, mixed>>
     */
    public function threads_for_user(
        int $clinic_id,
        ?int $organization_id,
        int $wp_user_id,
        ?array $location_ids,
        int $limit,
        int $offset = 0
    ): array {
        $sql    = 'SELECT t.*, other.wp_user_id AS counterpart_wp_user_id,'
            . ' other.staff_class AS counterpart_staff_class,'
            . ' mine.last_read_message_id AS last_read_message_id'
            . ' FROM ' . $this->db->table( 'cpms_message_threads' ) . ' t'
            . ' INNER JOIN ' . $this->db->table( 'cpms_message_participants' ) . ' mine'
            . ' ON mine.thread_id = t.id AND mine.wp_user_id = %d'
            . ' INNER JOIN ' . $this->db->table( 'cpms_message_participants' ) . ' other'
            . ' ON other.thread_id = t.id AND other.wp_user_id != %d'
            . ' WHERE t.clinic_id = %d'
            . ' AND (SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_message_participants' ) . ' exact_pair'
            . ' WHERE exact_pair.thread_id = t.id) = 2';
        $params = [$wp_user_id, $wp_user_id, $clinic_id];
        if ( $organization_id !== null ) {
            $sql     .= ' AND t.organization_id = %d';
            $params[] = $organization_id;
        }
        if ( $location_ids !== null ) {
            if ( $location_ids === [] ) {
                $sql .= ' AND t.location_id IS NULL';
            } else {
                $sql   .= ' AND (t.location_id IS NULL OR t.location_id IN ('
                    . implode( ',', array_fill( 0, count( $location_ids ), '%d' ) ) . '))';
                $params = array_merge( $params, $location_ids );
            }
        }
        $sql     .= ' ORDER BY t.last_message_id DESC, t.id DESC LIMIT %d OFFSET %d';
        $params[] = max( 1, min( 100, $limit ) );
        $params[] = max( 0, min( 10000000, $offset ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- composed statements use only %d placeholders generated above; no raw request value is interpolated.
        $rows = $this->db->fetchAll( $sql, $params );
        return is_array( $rows ) ? $rows : [];
    }

    public function thread_unread( int $thread_id, int $wp_user_id ): int {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_messages' ) . ' m'
            . ' INNER JOIN ' . $this->db->table( 'cpms_message_participants' ) . ' mine'
            . ' ON mine.thread_id = m.thread_id AND mine.wp_user_id = %d'
            . ' WHERE m.thread_id = %d AND m.sender_wp_user_id != %d AND m.id > mine.last_read_message_id',
            [$wp_user_id, $thread_id, $wp_user_id]
        );
    }

    public function insert_message( int $clinic_id, int $thread_id, int $sender_wp_user_id, string $body ): int {
        $now = $this->db->nowUtcSql();
        $this->db->insert(
            'cpms_messages',
            [
				'clinic_id'         => $clinic_id,
				'thread_id'         => $thread_id,
				'sender_wp_user_id' => $sender_wp_user_id,
				'body'              => $body,
				'created_at'        => $now,
			]
        );
        $message_id = $this->db->wpdb_last_insert_id();

        $this->db->update(
            'cpms_message_threads',
            [
                'last_message_id' => $message_id,
                'last_message_at' => $now,
            ],
            ['id' => $thread_id]
        );

        return $message_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_message( int $id ): ?array {
        return $this->db->fetchRow(
            'SELECT * FROM ' . $this->db->table( 'cpms_messages' ) . ' WHERE id = %d',
            [$id]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function messages_since( int $thread_id, int $since_id, int $limit ): array {
        $rows = $this->db->fetchAll(
            'SELECT id, thread_id, sender_wp_user_id, body, created_at FROM '
            . $this->db->table( 'cpms_messages' )
            . ' WHERE thread_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
            [$thread_id, max( 0, $since_id ), max( 1, min( 100, $limit ) )]
        );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last_message( int $thread_id ): ?array {
        return $this->db->fetchRow(
            'SELECT id, body, created_at FROM ' . $this->db->table( 'cpms_messages' )
            . ' WHERE thread_id = %d ORDER BY id DESC',
            [$thread_id]
        );
    }

    /**
     * Monotonic read cursor: a stale poll can never move it backwards.
     */
    public function mark_read( int $thread_id, int $wp_user_id, int $last_read_message_id ): void {
        $this->db->query(
            'UPDATE ' . $this->db->table( 'cpms_message_participants' )
            . ' SET last_read_message_id = GREATEST(last_read_message_id, %d)'
            . ' WHERE thread_id = %d AND wp_user_id = %d',
            [max( 0, $last_read_message_id ), $thread_id, $wp_user_id]
        );
    }
}
