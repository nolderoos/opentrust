<?php
namespace GovernDocs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovernDocs_CMB2 {

    /** @var \GovernDocs\CMB2\Policy\GovernDocs_CMB2_Policy|null */
    private $policy = null;

    /** @var \GovernDocs\CMB2\Meeting\GovernDocs_CMB2_Meeting|null */
    private $meeting = null;

    /** @var \GovernDocs\CMB2\Report\GovernDocs_CMB2_Report|null */
    private $report = null;

    public function hooks() : void {
        $this->boot_cmb2();

        // Load modules.
        $this->load_modules();

        // Register module hooks.
        if ( $this->policy ) {
            $this->policy->hooks();
        }

        if ( $this->meeting ) {
            $this->meeting->hooks();
        }

        if ( $this->report ) {
            $this->report->hooks();
        }
    }

    private function load_modules() : void {
        $policy_file    = GOVERNDOCS_DIR . 'includes/cmb2/class-governdocs-cmb2-policy.php';
        $meeting_file   = GOVERNDOCS_DIR . 'includes/cmb2/class-governdocs-cmb2-meeting.php';
        $report_file    = GOVERNDOCS_DIR . 'includes/cmb2/class-governdocs-cmb2-report.php';

        if ( file_exists( $policy_file ) ) {
            require_once $policy_file;

            // Class lives in GovernDocs\CMB2\Policy namespace.
            $this->policy = new \GovernDocs\CMB2\Policy\GovernDocs_CMB2_Policy();
        }

        if ( file_exists( $meeting_file ) ) {
            require_once $meeting_file;

            // Class lives in GovernDocs\CMB2\Meeting namespace.
            $this->meeting = new \GovernDocs\CMB2\Meeting\GovernDocs_CMB2_Meeting();
        }

        if ( file_exists( $report_file ) ) {
            require_once $report_file;

            // Class lives in GovernDocs\CMB2\Report namespace.
            $this->report = new \GovernDocs\CMB2\Report\GovernDocs_CMB2_Report();
        }
    }

    private function boot_cmb2() : void {
        if ( function_exists( 'new_cmb2_box' ) ) {
            return;
        }

        $init = GOVERNDOCS_DIR . 'vendor/cmb2/init.php';
        if ( file_exists( $init ) ) {
            require_once $init;
        }
    }
}