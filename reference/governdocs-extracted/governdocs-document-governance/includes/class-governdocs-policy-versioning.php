<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_Policy_Versioning {

    /**
     * Old snapshot per post ID for this request.
     *
     * @var array<int, array<string, string>>
     */
    private static array $old = array();

    public function hooks() : void {
        // Capture existing values before the post is updated.
        add_action( 'pre_post_update', array( $this, 'capture_old_snapshot' ), 1, 2 );

        // Append AFTER CMB2 saves fields (prevents our update being overwritten).
        add_action( 'cmb2_save_post_fields', array( $this, 'after_cmb2_save' ), 20, 4 );
    }

    /**
     * Capture snapshot of current fields BEFORE update occurs.
     */
    public function capture_old_snapshot( int $post_id, $data ) : void {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( 'governdocs_policy' !== $post->post_type ) {
            return;
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Only capture once per request.
        if ( isset( self::$old[ $post_id ] ) ) {
            return;
        }

        self::$old[ $post_id ] = array(
            'version'        => (string) get_post_meta( $post_id, 'governdocs_version', true ),
            'effective_date' => (string) get_post_meta( $post_id, 'governdocs_effective_date', true ),
            'approval_date'  => (string) get_post_meta( $post_id, 'governdocs_approval_date', true ),
            'file'           => $this->get_primary_file_value( $post_id ),
        );
    }

    /**
     * Runs after CMB2 saves fields.
     */
    public function after_cmb2_save( $object_id, $updated, $cmb, $args ) : void {
        $post_id = (int) $object_id;

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        if ( 'governdocs_policy' !== $post->post_type ) {
            return;
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $old = self::$old[ $post_id ] ?? null;
        if ( ! is_array( $old ) ) {
            return;
        }

        $new = array(
            'version'        => (string) get_post_meta( $post_id, 'governdocs_version', true ),
            'effective_date' => (string) get_post_meta( $post_id, 'governdocs_effective_date', true ),
            'approval_date'  => (string) get_post_meta( $post_id, 'governdocs_approval_date', true ),
            'file'           => $this->get_primary_file_value( $post_id ),
        );

        $old_version = trim( (string) ( $old['version'] ?? '' ) );
        $old_file    = trim( (string) ( $old['file'] ?? '' ) );

        // First save: nothing to push down.
        if ( '' === $old_version && '' === $old_file ) {
            return;
        }

        // Only append if version OR file changed.
        $changed = ( (string) $new['version'] !== (string) $old['version'] )
            || ( (string) $new['file'] !== (string) $old['file'] );

        if ( ! $changed ) {
            return;
        }

        $versions = get_post_meta( $post_id, 'governdocs_policy_versions', true );
        $versions = is_array( $versions ) ? $versions : array();

        // Avoid duplicates (same version + same file).
        if ( $this->versions_contains( $versions, $old_version, $old_file ) ) {
            return;
        }

        // Add newest previous version to top.
        array_unshift( $versions, array(
            'version'        => $old_version,
            'effective_date' => (string) ( $old['effective_date'] ?? '' ),
            'approval_date'  => (string) ( $old['approval_date'] ?? '' ),
            'file'           => $old_file,
            'notes'          => '',
        ) );

        update_post_meta( $post_id, 'governdocs_policy_versions', $versions );
    }

    private function get_primary_file_value( int $post_id ) : string {
        $primary = get_post_meta( $post_id, 'governdocs_primary_file', true );

        if ( is_array( $primary ) ) {
            if ( ! empty( $primary['url'] ) ) {
                return (string) $primary['url'];
            }
            if ( ! empty( $primary['id'] ) ) {
                return (string) (int) $primary['id'];
            }
            return '';
        }

        return is_string( $primary ) ? $primary : '';
    }

    /**
     * @param array<int, mixed> $versions
     */
    private function versions_contains( array $versions, string $version, string $file ) : bool {
        $version = trim( $version );
        $file    = trim( $file );

        foreach ( $versions as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $v = isset( $row['version'] ) ? trim( (string) $row['version'] ) : '';
            $f = isset( $row['file'] ) ? trim( (string) $row['file'] ) : '';

            if ( $v === $version && $f === $file ) {
                return true;
            }
        }

        return false;
    }
}
