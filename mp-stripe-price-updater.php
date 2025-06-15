<?php
/**
 * Plugin Name: MemberPress Stripe Price Updater
 * Plugin URI: https://memberpress.com/
 * Description: Automate subscription price changes for MemberPress Stripe subscriptions.
 * Version: 1.0.12
 * Author: MemberPress
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MPSP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPSP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MPSP_VERSION', '1.0.12');

// Main plugin class
class MP_Stripe_Price_Updater {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null == self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Create database tables on activation and check version
        register_activation_hook(__FILE__, array($this, 'create_database_tables'));
        add_action('admin_init', array($this, 'check_database_version'));
        
        // AJAX handlers
        add_action('wp_ajax_mpsp_get_memberships', array($this, 'ajax_get_memberships'));
        add_action('wp_ajax_mpsp_preview_subscriptions', array($this, 'ajax_preview_subscriptions'));
        add_action('wp_ajax_mpsp_save_rule', array($this, 'ajax_save_rule'));
        add_action('wp_ajax_mpsp_delete_rule', array($this, 'ajax_delete_rule'));
        add_action('wp_ajax_mpsp_enable_rule', array($this, 'ajax_enable_rule'));
        add_action('wp_ajax_mpsp_get_job_status', array($this, 'ajax_get_job_status'));
        add_action('wp_ajax_mpsp_cancel_job', array($this, 'ajax_cancel_job'));
        add_action('wp_ajax_mpsp_download_csv_report', array($this, 'ajax_download_csv_report'));
        add_action('wp_ajax_mpsp_get_job_log', array($this, 'ajax_get_job_log'));
        add_action('wp_ajax_mpsp_get_job_entries', array($this, 'ajax_get_job_entries'));
        add_action('wp_ajax_mpsp_delete_job', array($this, 'ajax_delete_job'));
        add_action('wp_ajax_mpsp_purge_all_data', array($this, 'ajax_purge_all_data'));
        add_action('wp_ajax_mpsp_start_rule_processing', array($this, 'ajax_start_rule_processing'));
        
        // Cron setup
        add_action('mpsp_process_price_updates', array($this, 'process_price_updates'));
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('mpsp_process_price_updates')) {
            wp_schedule_event(time(), 'every_5_minutes', 'mpsp_process_price_updates');
        }
        
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function admin_init() {
        // Check if MemberPress is active
        if (!class_exists('MeprOptions') || !class_exists('MeprSubscription')) {
            add_action('admin_notices', array($this, 'memberpress_missing_notice'));
            return;
        }
    }
    
    public function memberpress_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('MemberPress Stripe Price Updater requires MemberPress to be installed and active.', 'mpsp'); ?></p>
        </div>
        <?php
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'memberpress',
            'Stripe Price Updater',
            'Stripe Price Updater',
            'manage_options',
            'mp-stripe-price-updater',
            array($this, 'admin_page')
        );
    }
    
    public function check_database_version() {
        $current_db_version = get_option('mpsp_db_version', '1.0.0');
        
        if (version_compare($current_db_version, MPSP_VERSION, '<')) {
            $this->create_database_tables();
        }
    }
    
    public function admin_scripts($hook) {
        if ($hook !== 'memberpress_page_mp-stripe-price-updater') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'mpsp_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mpsp_nonce')
        ));
    }
    
    public function admin_page() {
        // Check if MemberPress is active
        if (!class_exists('MeprOptions') || !class_exists('MeprSubscription')) {
            ?>
            <div class="wrap">
                <h1>MemberPress Stripe Price Updater</h1>
                <div class="notice notice-error">
                    <p><?php _e('MemberPress is required for this plugin to function. Please install and activate MemberPress.', 'mpsp'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        
        // Check if there are any Stripe gateways configured
        $options  = MeprOptions::fetch();
        $gateways = array_filter(
            $options->payment_methods(false),
            function ($gateway) {
                return $gateway instanceof MeprStripeGateway;
            }
        );
        if (empty($gateways)) {
            ?>
            <div class="wrap">
                <h1>MemberPress Stripe Price Updater</h1>
                <div class="notice notice-warning">
                    <p><?php _e('No Stripe gateways found. Please configure at least one Stripe gateway in MemberPress before using this plugin.', 'mpsp'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=memberpress-options&tab=payments'); ?>" class="button"><?php _e('Configure Stripe Gateway', 'mpsp'); ?></a></p>
                </div>
            </div>
            <?php
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>MemberPress Stripe Price Updater</h1>
            
            <div id="mpsp-main-form">
                <h2>Create Price Update Rule</h2>
                <form id="mpsp-price-rule-form">
                    <?php wp_nonce_field('mpsp_nonce', 'mpsp_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="mpsp_gateway_id">Select Stripe Gateway</label>
                            </th>
                            <td>
                                <select name="mpsp_gateway_id" id="mpsp_gateway_id" required>
                                    <option value="">-- Select Gateway --</option>
                                    <?php
                                    foreach ($gateways as $gateway) {
                                        if ($gateway->is_test_mode()) {
                                            if (defined('MEMBERPRESS_STRIPE_TESTING') && MEMBERPRESS_STRIPE_TESTING) {
                                                $mode_indicator = ' (Global Test Mode)';
                                            } else {
                                                $mode_indicator = ' (Test Mode)';
                                            }
                                        } else {
                                            $mode_indicator = ' (Live Mode)';
                                        }
                                        echo '<option value="' . esc_attr($gateway->id) . '">' . esc_html(($gateway->label ?: 'Stripe Gateway') . $mode_indicator) . '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description">Choose which Stripe gateway to target for price updates. The API mode (Test/Live) is shown for each gateway.</p>
                            </td>
                        </tr>
                        
                        <tr id="membership_row" style="display:none;">
                            <th scope="row">
                                <label for="mpsp_membership_id">Select Membership</label>
                            </th>
                            <td>
                                <select name="mpsp_membership_id" id="mpsp_membership_id" required>
                                    <option value="">-- Select Membership --</option>
                                </select>
                                <p class="description">Choose which membership to target for price updates.</p>
                            </td>
                        </tr>
                        
                        <tr id="price_filter_row" style="display:none;">
                            <th scope="row">Price Filter</th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="radio" name="mpsp_price_filter_type" value="range" checked> 
                                        Price Range
                                    </label><br>
                                    <label>
                                        <input type="radio" name="mpsp_price_filter_type" value="specific"> 
                                        Specific Price
                                    </label>
                                </fieldset>
                                
                                <div id="price_range_fields" style="margin-top: 15px;">
                                    <label>
                                        Min Price: <?php echo esc_html(MeprOptions::fetch()->currency_symbol); ?><input type="number" step="0.01" name="mpsp_min_price" min="0" style="width: 100px;" />
                                    </label>
                                    <label style="margin-left: 20px;">
                                        Max Price: <?php echo esc_html(MeprOptions::fetch()->currency_symbol); ?><input type="number" step="0.01" name="mpsp_max_price" min="0" style="width: 100px;" />
                                    </label>
                                </div>
                                
                                <div id="specific_price_field" style="display:none; margin-top: 15px;">
                                    <label>
                                        Specific Price: <?php echo esc_html(MeprOptions::fetch()->currency_symbol); ?><input type="number" step="0.01" name="mpsp_specific_price" min="0" style="width: 100px;" />
                                    </label>
                                </div>
                                <p class="description">Filter subscriptions by their current price.</p>
                            </td>
                        </tr>
                        
                        <tr id="billing_terms_row" style="display:none;">
                            <th scope="row">Billing Terms Filter</th>
                            <td>
                                <label>
                                    Period: <input type="number" name="mpsp_billing_period" min="1" style="width: 60px;" />
                                </label>
                                <label style="margin-left: 20px;">
                                    Interval: 
                                    <select name="mpsp_billing_interval" style="width: 100px;">
                                        <option value="">Any</option>
                                        <option value="weeks">Weeks</option>
                                        <option value="months">Months</option>
                                        <option value="years">Years</option>
                                    </select>
                                </label>
                                <p class="description">Filter by billing cycle (leave period blank to match any period).</p>
                            </td>
                        </tr>
                        
                        <tr id="new_price_row" style="display:none;">
                            <th scope="row">
                                <label for="mpsp_new_price">New Price</label>
                            </th>
                            <td>
                                <label>
                                    New Price: <?php echo esc_html(MeprOptions::fetch()->currency_symbol); ?><input type="number" step="0.01" name="mpsp_new_price" id="mpsp_new_price" min="0" required style="width: 100px;" />
                                </label>
                                <p class="description">The new price to set for matching subscriptions.</p>
                            </td>
                        </tr>
                        
                        <tr id="rule_name_row" style="display:none;">
                            <th scope="row">
                                <label for="mpsp_rule_name">Rule Name</label>
                            </th>
                            <td>
                                <input type="text" name="mpsp_rule_name" id="mpsp_rule_name" class="regular-text" required />
                                <p class="description">A name to identify this rule.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="subscription_preview" style="display:none; margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;">
                        <h3>Matching Subscriptions Preview</h3>
                        <div id="preview_content"></div>
                    </div>
                    
                    <p class="submit">
                        <button type="button" id="preview_button" class="button" style="display:none;">Preview Affected Subscriptions</button>
                        <button type="button" id="save_rule_button" class="button button-primary" style="display:none; margin-left: 10px;">Save Price Update Rule</button>
                    </p>
                </form>
            </div>
            
            <hr style="margin: 40px 0;">
            
            <div id="mpsp-job-status" style="display:none; margin: 30px 0; padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
                <h3>Processing Job Status</h3>
                <div id="job-status-content">
                    <p id="job-message">Checking job status...</p>
                    <div id="job-progress" style="width: 100%; background-color: #f0f0f0; border-radius: 4px; margin: 10px 0;">
                        <div id="job-progress-bar" style="width: 0%; height: 20px; background-color: #007cba; border-radius: 4px; transition: width 0.3s;"></div>
                    </div>
                    <p id="job-details" style="font-size: 12px; color: #666;"></p>
                    <button type="button" id="cancel-job-button" class="button" style="margin-top: 10px;">Cancel Job</button>
                </div>
            </div>

            <div id="mpsp-rules-section">
                <h2>Price Update Rules</h2>
                <div class="notice notice-info inline" style="margin: 15px 0; padding: 10px;">
                    <p><strong>Processing Information:</strong> For server safety, the system processes up to 25 subscriptions every 5 minutes per rule. Large batches will be processed automatically over multiple cycles.</p>
                </div>
                <div id="active_rules">
                    <?php $this->display_active_rules(); ?>
                </div>
            </div>
        </div>
        
        <style>
        /* Base Table Container */
        .mpsp-table-container {
            overflow-x: auto;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-radius: 4px;
            background: white;
            border: 1px solid #ddd;
        }

        .mpsp-table-container .wp-list-table {
            margin: 0;
            border: none;
            font-size: 13px;
        }

        /* Rules Table - 9 columns */
        .mpsp-rules-table {
            min-width: 1200px;
        }
        .mpsp-rules-table th:nth-child(1) { width: 18%; min-width: 150px; } /* Rule Name */
        .mpsp-rules-table th:nth-child(2) { width: 10%; min-width: 100px; } /* Status */
        .mpsp-rules-table th:nth-child(3) { width: 12%; min-width: 120px; } /* Gateway */
        .mpsp-rules-table th:nth-child(4) { width: 15%; min-width: 140px; } /* Membership */
        .mpsp-rules-table th:nth-child(5) { width: 12%; min-width: 110px; } /* Price Filter */
        .mpsp-rules-table th:nth-child(6) { width: 10%; min-width: 100px; } /* Billing Filter */
        .mpsp-rules-table th:nth-child(7) { width: 8%; min-width: 80px; } /* New Price */
        .mpsp-rules-table th:nth-child(8) { width: 8%; min-width: 90px; } /* Created */
        .mpsp-rules-table th:nth-child(9) { width: 7%; min-width: 60px; } /* Jobs */
        .mpsp-rules-table th:nth-child(10) { width: 15%; min-width: 140px; } /* Actions */

        /* Jobs Table - 9 columns */
        .mpsp-reports-table {
            min-width: 1100px;
        }
        .mpsp-reports-table th:nth-child(1) { width: 8%; min-width: 80px; } /* Job ID */
        .mpsp-reports-table th:nth-child(2) { width: 20%; min-width: 160px; } /* Rule Name */
        .mpsp-reports-table th:nth-child(3) { width: 10%; min-width: 90px; } /* Status */
        .mpsp-reports-table th:nth-child(4) { width: 15%; min-width: 130px; } /* Started */
        .mpsp-reports-table th:nth-child(5) { width: 15%; min-width: 130px; } /* Completed */
        .mpsp-reports-table th:nth-child(6) { width: 8%; min-width: 70px; } /* Processed */
        .mpsp-reports-table th:nth-child(7) { width: 8%; min-width: 70px; } /* Success */
        .mpsp-reports-table th:nth-child(8) { width: 8%; min-width: 70px; } /* Errors */
        .mpsp-reports-table th:nth-child(9) { width: 18%; min-width: 150px; } /* Actions */

        /* Log Table - 8 columns */
        .mpsp-log-table {
            min-width: 1100px;
        }
        .mpsp-log-table th:nth-child(1) { width: 15%; min-width: 140px; } /* Date */
        .mpsp-log-table th:nth-child(2) { width: 10%; min-width: 100px; } /* Subscription ID */
        .mpsp-log-table th:nth-child(3) { width: 15%; min-width: 120px; } /* User */
        .mpsp-log-table th:nth-child(4) { width: 8%; min-width: 80px; } /* Old Price */
        .mpsp-log-table th:nth-child(5) { width: 8%; min-width: 80px; } /* New Price */
        .mpsp-log-table th:nth-child(6) { width: 8%; min-width: 70px; } /* Status */
        .mpsp-log-table th:nth-child(7) { width: 8%; min-width: 70px; } /* API Mode */
        .mpsp-log-table th:nth-child(8) { width: 28%; min-width: 200px; } /* Message */

        /* Text Content Management */
        .mpsp-table-container td {
            word-wrap: break-word;
            vertical-align: top;
            padding: 12px 8px;
        }

        .mpsp-table-container th {
            padding: 12px 8px;
            font-weight: 600;
            background: #f9f9f9;
            border-bottom: 2px solid #ddd;
        }

        .mpsp-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            max-width: 100%;
        }

        /* Action Buttons */
        .mpsp-rule-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .mpsp-rule-actions .button {
            font-size: 11px;
            height: 24px;
            line-height: 22px;
            padding: 0 8px;
            margin: 0;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Status Indicators */
        .mpsp-status-active {
            color: #46b450;
            font-weight: 600;
        }

        .mpsp-status-disabled {
            color: #dc3232;
            font-weight: 600;
        }

        .mpsp-status-success {
            color: #46b450;
            font-weight: 600;
        }

        .mpsp-status-error {
            color: #dc3232;
            font-weight: 600;
        }

        /* Mobile Responsiveness */
        @media screen and (max-width: 1200px) {
            .mpsp-table-container {
                font-size: 12px;
            }
            
            .mpsp-table-container th,
            .mpsp-table-container td {
                padding: 8px 6px;
            }
            
            .mpsp-rule-actions .button {
                font-size: 10px;
                height: 22px;
                line-height: 20px;
                padding: 0 6px;
            }
        }

        @media screen and (max-width: 768px) {
            .mpsp-table-container {
                font-size: 11px;
                border-radius: 0;
                margin: 10px 0;
            }
            
            .mpsp-table-container th,
            .mpsp-table-container td {
                padding: 6px 4px;
            }
            
            .mpsp-truncate {
                max-width: 80px;
            }
            
            .mpsp-rule-actions {
                flex-direction: column;
                gap: 2px;
            }
            
            .mpsp-rule-actions .button {
                font-size: 9px;
                height: 20px;
                line-height: 18px;
                padding: 0 4px;
                width: 100%;
                text-align: center;
            }
        }

        /* Table hover effects */
        .mpsp-table-container .wp-list-table tbody tr:hover {
            background-color: #f9f9f9;
        }
        
        /* Ensure tables scroll smoothly */
        .mpsp-table-container {
            -webkit-overflow-scrolling: touch;
        }

        /* Preview Table */
        #subscription_preview table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
        }

        #subscription_preview th,
        #subscription_preview td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        #subscription_preview th {
            background-color: #f2f2f2;
            font-weight: 600;
        }

        .mpsp-processing {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Mobile Responsive Design */
        @media screen and (max-width: 1024px) {
            .mpsp-table-container .wp-list-table {
                min-width: 900px;
            }
            
            .mpsp-rule-actions {
                flex-direction: column;
                gap: 3px;
            }
            
            .mpsp-rule-actions .button {
                width: 100%;
                text-align: center;
            }
        }

        @media screen and (max-width: 768px) {
            .mpsp-table-container {
                margin: 10px -20px;
                border-radius: 0;
            }
            
            .mpsp-table-container .wp-list-table {
                min-width: 1000px;
            }
            
            .mpsp-table-container th,
            .mpsp-table-container td {
                padding: 6px 4px;
                font-size: 12px;
            }
            
            .mpsp-truncate {
                max-width: 100px;
            }
        }

        /* Table Loading State */
        .mpsp-table-loading {
            position: relative;
        }

        .mpsp-table-loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 10;
        }

        /* Improved Form Table */
        .form-table th {
            width: 200px;
            font-weight: 600;
        }

        .form-table td {
            padding: 15px 10px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            
            // Check for active job on page load
            checkJobStatus();
            
            // Check if we should start processing a newly saved rule
            var ruleIdToProcess = sessionStorage.getItem('mpsp_start_processing_rule_id');
            if (ruleIdToProcess) {
                sessionStorage.removeItem('mpsp_start_processing_rule_id');
                startRuleProcessing(ruleIdToProcess);
            }
            
            // Set up polling for job status if there's an active job
            var jobStatusInterval;
            
            // Gateway selection handler
            $('#mpsp_gateway_id').on('change', function() {
                var gatewayId = $(this).val();
                if (gatewayId) {
                    $('#membership_row').show();
                    loadMemberships(gatewayId);
                } else {
                    hideAllRows();
                }
            });
            
            // Price filter type change
            $('input[name="mpsp_price_filter_type"]').on('change', function() {
                if ($(this).val() === 'range') {
                    $('#price_range_fields').show();
                    $('#specific_price_field').hide();
                } else {
                    $('#price_range_fields').hide();
                    $('#specific_price_field').show();
                }
            });
            
            // Membership selection handler
            $('#mpsp_membership_id').on('change', function() {
                if ($(this).val()) {
                    $('#price_filter_row, #billing_terms_row, #new_price_row, #rule_name_row, #preview_button, #save_rule_button').show();
                } else {
                    $('#price_filter_row, #billing_terms_row, #new_price_row, #rule_name_row, #preview_button, #save_rule_button, #subscription_preview').hide();
                }
            });
            
            // Preview button
            $('#preview_button').on('click', function() {
                previewSubscriptions();
            });
            
            // Save rule button
            $('#save_rule_button').on('click', function() {
                saveUpdateRule();
            });
            
            // Delete rule buttons
            $(document).on('click', '.delete-rule', function() {
                var ruleId = $(this).data('rule-id');
                deleteRule(ruleId);
            });
            
            // Enable rule buttons
            $(document).on('click', '.enable-rule', function() {
                var ruleId = $(this).data('rule-id');
                enableRule(ruleId);
            });
            
            
            // Delete job buttons
            $(document).on('click', '.delete-job', function() {
                var jobId = $(this).data('job-id');
                deleteJob(jobId);
            });
            
            // View job entries buttons
            $(document).on('click', '.view-job-entries', function() {
                var jobId = $(this).data('job-id');
                showJobEntriesModal(jobId);
            });
            
            // Purge all data button
            $(document).on('click', '#purge-all-data', function() {
                purgeAllData();
            });
            
            // Cancel job button
            $('#cancel-job-button').on('click', function() {
                if (confirm('Are you sure you want to cancel the current processing job?')) {
                    cancelJob();
                }
            });
            
            function hideAllRows() {
                $('#membership_row, #price_filter_row, #billing_terms_row, #new_price_row, #rule_name_row, #preview_button, #save_rule_button, #subscription_preview').hide();
            }
            
            function loadMemberships(gatewayId) {
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_get_memberships',
                    gateway_id: gatewayId,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        $('#mpsp_membership_id').html(response.data);
                    } else {
                        alert('Error loading memberships: ' + response.data);
                    }
                });
            }
            
            function previewSubscriptions() {
                var formData = getFormData();
                if (!validateFormData(formData)) {
                    return;
                }
                
                $('#subscription_preview').show();
                $('#preview_content').html('<p>Loading matching subscriptions...</p>');
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_preview_subscriptions',
                    form_data: formData,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        $('#preview_content').html(response.data);
                    } else {
                        $('#preview_content').html('<p style="color: red;">Error: ' + response.data + '</p>');
                    }
                });
            }
            
            function saveUpdateRule() {
                var formData = getFormData();
                if (!validateFormData(formData, true)) {
                    return;
                }
                
                $('#save_rule_button').addClass('mpsp-processing').text('Saving...');
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_save_rule',
                    rule_data: formData,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    $('#save_rule_button').removeClass('mpsp-processing').text('Save Price Update Rule');
                    
                    if (response.success) {
                        alert('Rule saved successfully! Processing will start immediately.');
                        
                        // Store rule ID to trigger processing after page reload
                        if (response.data && response.data.rule_id) {
                            sessionStorage.setItem('mpsp_start_processing_rule_id', response.data.rule_id);
                        }
                        
                        location.reload();
                    } else {
                        alert('Error saving rule: ' + response.data);
                    }
                });
            }
            
            
            function deleteRule(ruleId) {
                if (!confirm('Are you sure you want to delete this rule? This will also delete all associated jobs and entries.')) {
                    return;
                }
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_delete_rule',
                    rule_id: ruleId,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        alert('Rule deleted successfully.');
                        location.reload();
                    } else {
                        alert('Error deleting rule: ' + response.data);
                    }
                });
            }
            
            function enableRule(ruleId) {
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_enable_rule',
                    rule_id: ruleId,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        // Show job status bar immediately and start monitoring
                        $('#mpsp-job-status').show();
                        $('#job-message').text('Rule enabled - waiting for cron processing...');
                        $('#job-progress-bar').css('width', '0%');
                        $('#job-details').text('Job will start within 5 minutes...');
                        
                        // Start polling for job status immediately
                        startJobStatusPolling();
                        location.reload(); // Refresh to show updated rule status
                    } else {
                        alert('Error enabling rule: ' + response.data);
                    }
                });
            }
            
            function deleteJob(jobId) {
                if (!confirm('Are you sure you want to delete this job? This will delete all associated log entries.')) {
                    return;
                }
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_delete_job',
                    job_id: jobId,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Error deleting job: ' + response.data);
                    }
                });
            }
            
            function showJobEntriesModal(jobId) {
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_get_job_entries',
                    job_id: jobId,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        var entries = response.data;
                        var modalHtml = '<div id="job-entries-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000; display: flex; align-items: center; justify-content: center;">' +
                            '<div style="background: white; padding: 20px; border-radius: 5px; max-width: 95%; max-height: 90%; overflow: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">' +
                            '<h3>Job Entries - Job ID: ' + jobId + '</h3>' +
                            '<div class="mpsp-table-container" style="max-height: 70vh; overflow-y: auto;">' +
                            '<table class="wp-list-table widefat fixed striped mpsp-log-table">' +
                            '<thead><tr><th>Date</th><th>Subscription ID</th><th>User</th><th>Old Price</th><th>New Price</th><th>Status</th><th>API Mode</th><th>Message</th></tr></thead>' +
                            '<tbody>';
                        
                        if (entries.length === 0) {
                            modalHtml += '<tr><td colspan="8" style="text-align: center; padding: 20px;">No entries found for this job.</td></tr>';
                        } else {
                            entries.forEach(function(entry) {
                                var statusClass = entry.status === 'success' ? 'mpsp-status-success' : 'mpsp-status-error';
                                modalHtml += '<tr>' +
                                    '<td>' + entry.processed_at + '</td>' +
                                    '<td>' + entry.subscription_id + '</td>' +
                                    '<td>' + entry.user_display_name + '</td>' +
                                    '<td>' + entry.old_price + '</td>' +
                                    '<td>' + entry.new_price + '</td>' +
                                    '<td><span class="' + statusClass + '">' + entry.status.charAt(0).toUpperCase() + entry.status.slice(1) + '</span></td>' +
                                    '<td>' + entry.api_mode.toUpperCase() + '</td>' +
                                    '<td><div class="mpsp-truncate" title="' + (entry.error_message || 'Success') + '">' + (entry.error_message || 'Success') + '</div></td>' +
                                    '</tr>';
                            });
                        }
                        
                        modalHtml += '</tbody></table></div>' +
                            '<div style="margin-top: 20px; text-align: right;">' +
                            '<button type="button" onclick="closeJobEntriesModal()" class="button button-primary">Close</button>' +
                            '</div></div></div>';
                        
                        $('body').append(modalHtml);
                    } else {
                        alert('Error loading job entries: ' + response.data);
                    }
                });
            }
            
            function closeJobEntriesModal() {
                $('#job-entries-modal').remove();
            }
            
            function purgeAllData() {
                if (!confirm('WARNING: This will delete ALL rules, jobs, and entries permanently. This action cannot be undone!\n\nAre you sure you want to proceed?')) {
                    return;
                }
                
                if (!confirm('FINAL WARNING: You are about to permanently delete all data. Type YES in the next prompt to confirm.')) {
                    return;
                }
                
                var confirmation = prompt('Type "DELETE ALL DATA" to confirm (case sensitive):');
                if (confirmation !== 'DELETE ALL DATA') {
                    alert('Confirmation text did not match. Data purge cancelled.');
                    return;
                }
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_purge_all_data',
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert('Error purging data: ' + response.data);
                    }
                });
            }
            
            // Close modal when clicking outside
            $(document).on('click', '#job-entries-modal', function(e) {
                if (e.target === this) {
                    closeJobEntriesModal();
                }
            });
            
            function getFormData() {
                return {
                    gateway_id: $('#mpsp_gateway_id').val(),
                    membership_id: $('#mpsp_membership_id').val(),
                    price_filter_type: $('input[name="mpsp_price_filter_type"]:checked').val(),
                    min_price: $('input[name="mpsp_min_price"]').val(),
                    max_price: $('input[name="mpsp_max_price"]').val(),
                    specific_price: $('input[name="mpsp_specific_price"]').val(),
                    billing_period: $('input[name="mpsp_billing_period"]').val(),
                    billing_interval: $('select[name="mpsp_billing_interval"]').val(),
                    new_price: $('input[name="mpsp_new_price"]').val(),
                    rule_name: $('input[name="mpsp_rule_name"]').val()
                };
            }
            
            function validateFormData(data, requireName = false) {
                if (!data.gateway_id) {
                    alert('Please select a gateway.');
                    return false;
                }
                if (!data.membership_id) {
                    alert('Please select a membership.');
                    return false;
                }
                if (!data.new_price || parseFloat(data.new_price) <= 0) {
                    alert('Please enter a valid new price.');
                    return false;
                }
                if (requireName && !data.rule_name) {
                    alert('Please enter a rule name.');
                    return false;
                }
                
                if (data.price_filter_type === 'range') {
                    if (data.min_price && data.max_price && parseFloat(data.min_price) > parseFloat(data.max_price)) {
                        alert('Min price cannot be greater than max price.');
                        return false;
                    }
                } else if (data.price_filter_type === 'specific') {
                    if (!data.specific_price || parseFloat(data.specific_price) <= 0) {
                        alert('Please enter a valid specific price.');
                        return false;
                    }
                }
                
                return true;
            }
            
            function checkJobStatus(callback) {
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_get_job_status',
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success && response.data.active) {
                        $('#mpsp-job-status').show();
                        updateJobDisplay(response.data);
                        if (!jobStatusInterval) {
                            startJobStatusPolling();
                        }
                        if (callback) callback(true);
                    } else {
                        $('#mpsp-job-status').hide();
                        if (jobStatusInterval) {
                            clearInterval(jobStatusInterval);
                            jobStatusInterval = null;
                        }
                        if (callback) callback(false);
                    }
                }).fail(function() {
                    if (callback) callback(false);
                });
            }
            
            function startJobStatusPolling() {
                if (jobStatusInterval) {
                    clearInterval(jobStatusInterval);
                }
                
                jobStatusInterval = setInterval(function() {
                    checkJobStatus(function(hasActiveJob) {
                        if (!hasActiveJob) {
                            clearInterval(jobStatusInterval);
                            jobStatusInterval = null;
                            location.reload(); // Refresh to show updated logs
                        }
                    });
                }, 2000); // Poll every 2 seconds
            }
            
            function updateJobDisplay(jobData) {
                $('#job-message').text(jobData.message || 'Processing...');
                
                var progress = 0;
                if (jobData.total > 0) {
                    progress = (jobData.processed / jobData.total) * 100;
                }
                $('#job-progress-bar').css('width', progress + '%');
                
                var details = 'Processed: ' + (jobData.processed || 0) + ' / ' + (jobData.total || 0);
                if (jobData.errors && jobData.errors > 0) {
                    details += ' (Errors: ' + jobData.errors + ')';
                }
                if (jobData.started_at) {
                    details += ' | Started: ' + jobData.started_at;
                }
                $('#job-details').text(details);
            }
            
            function startRuleProcessing(ruleId) {
                // Show job status bar immediately
                $('#mpsp-job-status').show();
                $('#job-message').text('Starting processing...');
                $('#job-progress-bar').css('width', '0%');
                $('#job-details').text('Initializing job...');
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_start_rule_processing',
                    rule_id: ruleId,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        // Start monitoring job progress
                        startJobStatusPolling();
                    } else {
                        $('#mpsp-job-status').hide();
                        alert('Error starting processing: ' + response.data);
                    }
                }).fail(function() {
                    $('#mpsp-job-status').hide();
                    alert('Failed to start processing. Please try again.');
                });
            }
            
            function cancelJob() {
                $('#cancel-job-button').addClass('mpsp-processing').text('Cancelling...');
                
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_cancel_job',
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    $('#cancel-job-button').removeClass('mpsp-processing').text('Cancel Job');
                    
                    if (response.success) {
                        $('#mpsp-job-status').hide();
                        if (jobStatusInterval) {
                            clearInterval(jobStatusInterval);
                            jobStatusInterval = null;
                        }
                        alert('Job cancelled successfully.');
                        location.reload();
                    } else {
                        alert('Error cancelling job: ' + response.data);
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    public function ajax_get_memberships() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $gateway_id = sanitize_text_field($_POST['gateway_id']);
        $memberships = $this->get_memberships_with_active_subscriptions($gateway_id);
        
        $html = '<option value="">-- Select Membership --</option>';
        foreach ($memberships as $membership) {
            $html .= '<option value="' . esc_attr($membership->ID) . '">' . esc_html($membership->post_title) . '</option>';
        }
        
        wp_send_json_success($html);
    }
    
    public function ajax_preview_subscriptions() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $form_data = $_POST['form_data'];
        $matching_subscriptions = $this->get_matching_subscriptions($form_data);
        
        if (empty($matching_subscriptions)) {
            wp_send_json_success('<p>No subscriptions match the specified criteria.</p>');
        }
        
        $html = '<p><strong>Found ' . count($matching_subscriptions) . ' matching subscription(s):</strong></p>';
        $html .= '<table>';
        $html .= '<thead><tr><th>User</th><th>Email</th><th>Current Price</th><th>Billing Cycle</th><th>Stripe ID</th><th>Status</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ($matching_subscriptions as $sub_data) {
            $sub = $sub_data['subscription'];
            $user = $sub_data['user'];
            
            $html .= '<tr>';
            $html .= '<td>' . esc_html($user->display_name) . '</td>';
            $html .= '<td>' . esc_html($user->user_email) . '</td>';
            $html .= '<td>' . esc_html(MeprAppHelper::format_currency($sub->price)) . '</td>';
            $html .= '<td>' . esc_html($sub->period . ' ' . $sub->period_type) . '</td>';
            $html .= '<td>' . esc_html($sub->subscr_id) . '</td>';
            $html .= '<td>' . esc_html(ucfirst($sub->status)) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '<p><strong>New price will be: ' . esc_html(MeprAppHelper::format_currency($form_data['new_price'])) . '</strong></p>';
        
        wp_send_json_success($html);
    }
    
    public function ajax_save_rule() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $rule_data = $_POST['rule_data'];
        
        // Validate rule data
        if (empty($rule_data['gateway_id']) || empty($rule_data['membership_id']) || 
            empty($rule_data['new_price']) || empty($rule_data['rule_name'])) {
            wp_send_json_error('Missing required fields');
        }
        
        global $wpdb;
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'mpsp_rules',
            array(
                'name' => sanitize_text_field($rule_data['rule_name']),
                'gateway_id' => sanitize_text_field($rule_data['gateway_id']),
                'membership_id' => intval($rule_data['membership_id']),
                'price_filter_type' => sanitize_text_field($rule_data['price_filter_type']),
                'min_price' => !empty($rule_data['min_price']) ? floatval($rule_data['min_price']) : null,
                'max_price' => !empty($rule_data['max_price']) ? floatval($rule_data['max_price']) : null,
                'specific_price' => !empty($rule_data['specific_price']) ? floatval($rule_data['specific_price']) : null,
                'billing_period' => !empty($rule_data['billing_period']) ? intval($rule_data['billing_period']) : null,
                'billing_interval' => !empty($rule_data['billing_interval']) ? sanitize_text_field($rule_data['billing_interval']) : null,
                'new_price' => floatval($rule_data['new_price']),
                'enabled' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s', '%f', '%f', '%f', '%d', '%s', '%f', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to save rule');
        }
        
        // Get the newly created rule ID and immediately start processing
        $rule_id = $wpdb->insert_id;
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mpsp_rules WHERE id = %d", 
            $rule_id
        ), ARRAY_A);
        
        wp_send_json_success(array('rule_id' => $rule_id));
    }
    
    public function ajax_delete_rule() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $rule_id = intval($_POST['rule_id']);
        
        if (empty($rule_id)) {
            wp_send_json_error('Rule ID is required');
        }
        
        // Check if rule exists
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mpsp_rules WHERE id = %d",
            $rule_id
        ));
        
        if (!$rule) {
            wp_send_json_error('Rule not found');
        }
        
        // Manual cascade deletion since foreign keys aren't reliable in WordPress
        
        // Get job IDs for this rule
        $job_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mpsp_jobs WHERE rule_id = %d",
            $rule_id
        ));
        
        // Delete entries for these jobs
        if (!empty($job_ids)) {
            $job_ids_placeholder = implode(',', array_fill(0, count($job_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}mpsp_entries WHERE job_id IN ($job_ids_placeholder)",
                ...$job_ids
            ));
        }
        
        // Delete jobs for this rule
        $wpdb->delete(
            $wpdb->prefix . 'mpsp_jobs',
            array('rule_id' => $rule_id),
            array('%d')
        );
        
        // Delete the rule
        $result = $wpdb->delete(
            $wpdb->prefix . 'mpsp_rules',
            array('id' => $rule_id),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to delete rule');
        }
        
        wp_send_json_success(array(
            'message' => 'Rule deleted successfully. All associated jobs and entries have been removed.'
        ));
    }
    
    public function ajax_enable_rule() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $rule_id = intval($_POST['rule_id']);
        
        if (empty($rule_id)) {
            wp_send_json_error('Rule ID is required');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'mpsp_rules',
            array(
                'enabled' => 1,
                'auto_disabled_at' => null,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $rule_id),
            array('%d', '%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Failed to enable rule');
        }
        
        wp_send_json_success();
    }
    
    
    public function ajax_get_job_status() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $current_job = $wpdb->get_row(
            "SELECT j.*, r.name as rule_name FROM {$wpdb->prefix}mpsp_jobs j LEFT JOIN {$wpdb->prefix}mpsp_rules r ON j.rule_id = r.id WHERE j.current_job = 1 LIMIT 1",
            ARRAY_A
        );
        
        if (!$current_job) {
            wp_send_json_success(array('active' => false));
        } else {
            // Calculate progress percentage
            $progress = $current_job['total_subscriptions'] > 0 ? 
                round(($current_job['processed_count'] / $current_job['total_subscriptions']) * 100, 1) : 0;
                
            wp_send_json_success(array(
                'active' => true,
                'rule_name' => $current_job['rule_name'],
                'total' => $current_job['total_subscriptions'],
                'processed' => $current_job['processed_count'],
                'errors' => $current_job['error_count'],
                'progress' => $progress,
                'message' => "Processing subscription {$current_job['processed_count']} of {$current_job['total_subscriptions']}"
            ));
        }
    }
    
    public function ajax_cancel_job() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        // Mark current job as cancelled in database
        $updated = $wpdb->update(
            $wpdb->prefix . 'mpsp_jobs',
            array(
                'status' => 'cancelled',
                'completed_at' => current_time('mysql'),
                'current_job' => 0
            ),
            array('current_job' => 1),
            array('%s', '%s', '%d'),
            array('%d')
        );
        
        if ($updated) {
            wp_send_json_success(array('message' => 'Job cancelled successfully'));
        } else {
            wp_send_json_error('No active job found to cancel');
        }
    }
    
    public function ajax_download_csv_report() {
        // Check if this is a POST request with the proper parameters
        if (isset($_POST['action']) && $_POST['action'] === 'mpsp_download_csv_report') {
            if (!wp_verify_nonce($_POST['nonce'], 'mpsp_nonce')) {
                wp_die('Invalid nonce');
            }
            
            if (!current_user_can('manage_options')) {
                wp_die('Insufficient permissions');
            }
            
            global $wpdb;
            $job_id = intval($_POST['job_id']);
            
            if (empty($job_id)) {
                wp_die('Job ID is required');
            }
            
            // Get job from database
            $job = $wpdb->get_row($wpdb->prepare(
                "SELECT j.*, r.name as rule_name FROM {$wpdb->prefix}mpsp_jobs j 
                 LEFT JOIN {$wpdb->prefix}mpsp_rules r ON j.rule_id = r.id 
                 WHERE j.id = %d",
                $job_id
            ), ARRAY_A);
            
            if (!$job) {
                wp_die('Job not found');
            }
            
            // Generate CSV report from database data
            $report = $this->generate_job_report($job);
            $csv_content = $this->generate_csv_report($report);
            $filename = 'stripe-price-update-report-job-' . $job_id . '.csv';
            
            // Set headers for CSV download
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $csv_content;
            exit;
        }
        
        wp_send_json_error('Invalid request');
    }
    
    public function ajax_get_job_log() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $job_id = intval($_POST['job_id']);
        
        if (empty($job_id)) {
            wp_send_json_error('Job ID is required');
        }
        
        // Get entries for this job from the database
        $entries = $wpdb->get_results($wpdb->prepare("
            SELECT e.*, u.display_name as user_display_name, u.user_email
            FROM {$wpdb->prefix}mpsp_entries e
            LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
            WHERE e.job_id = %d
            ORDER BY e.processed_at DESC
        ", $job_id), ARRAY_A);
        
        // Format entries for display
        $job_log_entries = array();
        foreach ($entries as $entry) {
            $job_log_entries[] = array(
                'updated_at' => date('Y-m-d H:i:s', strtotime($entry['processed_at'])),
                'subscription_id' => $entry['subscription_id'],
                'old_price' => MeprAppHelper::format_currency($entry['old_price']),
                'new_price' => MeprAppHelper::format_currency($entry['new_price']),
                'status' => $entry['status'],
                'message' => $entry['error_message'] ?: 'Success'
            );
        }
        
        wp_send_json_success($job_log_entries);
    }
    
    public function ajax_get_job_entries() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $job_id = intval($_POST['job_id']);
        
        if (empty($job_id)) {
            wp_send_json_error('Job ID is required');
        }
        
        // Get entries for this job with user information
        $entries = $wpdb->get_results($wpdb->prepare("
            SELECT e.*, u.display_name as user_display_name, u.user_email
            FROM {$wpdb->prefix}mpsp_entries e
            LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
            WHERE e.job_id = %d
            ORDER BY e.processed_at DESC
        ", $job_id), ARRAY_A);
        
        // Format entries for display
        $formatted_entries = array();
        foreach ($entries as $entry) {
            $formatted_entries[] = array(
                'processed_at' => date('Y-m-d H:i:s', strtotime($entry['processed_at'])),
                'subscription_id' => $entry['subscription_id'],
                'user_display_name' => $entry['user_display_name'] . ' (' . $entry['user_email'] . ')',
                'old_price' => MeprAppHelper::format_currency($entry['old_price']),
                'new_price' => MeprAppHelper::format_currency($entry['new_price']),
                'status' => $entry['status'],
                'api_mode' => $entry['api_mode'],
                'error_message' => $entry['error_message']
            );
        }
        
        wp_send_json_success($formatted_entries);
    }
    
    public function ajax_delete_job() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        $job_id = intval($_POST['job_id']);
        
        if (empty($job_id)) {
            wp_send_json_error('Job ID is required');
        }
        
        // Delete entries first
        $entries_deleted = $wpdb->delete(
            $wpdb->prefix . 'mpsp_entries',
            array('job_id' => $job_id),
            array('%d')
        );
        
        // Delete the job
        $job_deleted = $wpdb->delete(
            $wpdb->prefix . 'mpsp_jobs',
            array('id' => $job_id),
            array('%d')
        );
        
        if ($job_deleted !== false) {
            wp_send_json_success(array(
                'message' => sprintf('Job deleted successfully. Removed %d entries.', $entries_deleted),
                'entries_deleted' => $entries_deleted
            ));
        } else {
            wp_send_json_error('Failed to delete job');
        }
    }
    
    public function ajax_purge_all_data() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        // Get counts before deletion
        $entries_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mpsp_entries");
        $jobs_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mpsp_jobs");
        $rules_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mpsp_rules");
        
        // Delete all data (in proper order due to foreign keys)
        $wpdb->query("DELETE FROM {$wpdb->prefix}mpsp_entries");
        $wpdb->query("DELETE FROM {$wpdb->prefix}mpsp_jobs");
        $wpdb->query("DELETE FROM {$wpdb->prefix}mpsp_rules");
        
        wp_send_json_success(array(
            'message' => sprintf('All data purged successfully. Removed %d rules, %d jobs, and %d entries.', 
                $rules_count, $jobs_count, $entries_count),
            'rules_deleted' => $rules_count,
            'jobs_deleted' => $jobs_count,
            'entries_deleted' => $entries_count
        ));
    }
    
    public function ajax_start_rule_processing() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $rule_id = intval($_POST['rule_id']);
        if (empty($rule_id)) {
            wp_send_json_error('Rule ID is required');
        }
        
        global $wpdb;
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mpsp_rules WHERE id = %d AND enabled = 1", 
            $rule_id
        ), ARRAY_A);
        
        if (!$rule) {
            wp_send_json_error('Rule not found or disabled');
        }
        
        // Get matching subscriptions for this rule
        $matching_subscriptions = $this->get_matching_subscriptions($rule);
        if (count($matching_subscriptions) > 0) {
            // Create a job first
            global $wpdb;
            $total_subscriptions = count($matching_subscriptions);
            
            $job_id = $wpdb->insert(
                $wpdb->prefix . 'mpsp_jobs',
                array(
                    'rule_id' => $rule['id'],
                    'status' => 'running',
                    'total_subscriptions' => $total_subscriptions,
                    'processed_count' => 0,
                    'success_count' => 0,
                    'error_count' => 0,
                    'started_at' => current_time('mysql'),
                    'last_activity_at' => current_time('mysql'),
                    'current_job' => 1
                ),
                array('%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d')
            );
            
            if ($job_id) {
                $job_id = $wpdb->insert_id;
                
                // Process just the first subscription immediately and synchronously
                $this->process_single_subscription_immediately($job_id, $rule, $matching_subscriptions[0]);
                
                wp_send_json_success(array('message' => 'Processing started'));
            } else {
                wp_send_json_error('Failed to create job');
            }
        } else {
            wp_send_json_error('No matching subscriptions found for this rule');
        }
    }
    
    private function process_single_subscription_immediately($job_id, $rule, $subscription_data) {
        global $wpdb;
        
        $subscription = $subscription_data['subscription'];
        $user = $subscription_data['user'];
        $status = 'error';
        $error_message = null;
        
        try {
            // Update in Stripe first
            $stripe_result = $this->update_stripe_subscription($subscription, $rule);
            
            if ($stripe_result['success']) {
                // Update in MemberPress
                $mp_result = $this->update_memberpress_subscription($subscription, $rule);
                
                if ($mp_result['success']) {
                    $status = 'success';
                    $error_message = null;
                } else {
                    $status = 'error';
                    $error_message = 'MemberPress update failed: ' . $mp_result['message'];
                }
            } else {
                $status = 'error';
                $error_message = 'Stripe update failed: ' . $stripe_result['message'];
            }
            
            // Create entry in database
            $wpdb->insert(
                $wpdb->prefix . 'mpsp_entries',
                array(
                    'job_id' => $job_id,
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->ID,
                    'old_price' => $subscription->price,
                    'new_price' => $rule['new_price'],
                    'status' => $status,
                    'stripe_price_id' => $stripe_result['stripe_price_id'] ?? null,
                    'api_mode' => $stripe_result['api_mode'] ?? 'unknown',
                    'error_message' => $error_message,
                    'processed_at' => current_time('mysql')
                ),
                array('%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s')
            );
            
            // Update job progress
            $wpdb->update(
                $wpdb->prefix . 'mpsp_jobs',
                array(
                    'last_activity_at' => current_time('mysql'),
                    'processed_count' => 1,
                    'success_count' => $status === 'success' ? 1 : 0,
                    'error_count' => $status === 'error' ? 1 : 0
                ),
                array('id' => $job_id),
                array('%s', '%d', '%d', '%d'),
                array('%d')
            );
            
        } catch (Exception $e) {
            // Create error entry
            $wpdb->insert(
                $wpdb->prefix . 'mpsp_entries',
                array(
                    'job_id' => $job_id,
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->ID,
                    'old_price' => $subscription->price,
                    'new_price' => $rule['new_price'],
                    'status' => 'error',
                    'stripe_price_id' => null,
                    'api_mode' => 'unknown',
                    'error_message' => 'Exception: ' . $e->getMessage(),
                    'processed_at' => current_time('mysql')
                ),
                array('%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s')
            );
            
            // Update job progress with error
            $wpdb->update(
                $wpdb->prefix . 'mpsp_jobs',
                array(
                    'last_activity_at' => current_time('mysql'),
                    'processed_count' => 1,
                    'success_count' => 0,
                    'error_count' => 1
                ),
                array('id' => $job_id),
                array('%s', '%d', '%d', '%d'),
                array('%d')
            );
        }
    }
    
    private function get_memberships_with_active_subscriptions($gateway_id) {
        global $wpdb;
        
        // Create the gateway string that MemberPress uses
        $gateway_string = $gateway_id;
        
        $query = "
            SELECT DISTINCT p.ID, p.post_title
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}mepr_subscriptions s ON p.ID = s.product_id
            WHERE p.post_type = 'memberpressproduct'
            AND p.post_status = 'publish'
            AND s.gateway = %s
            AND s.status = 'active'
            ORDER BY p.post_title
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $gateway_string));
    }
    
    private function get_matching_subscriptions($criteria) {
        global $wpdb;
        
        $gateway_string = $criteria['gateway_id'];
        
        $query = "
            SELECT s.*, u.display_name, u.user_email
            FROM {$wpdb->prefix}mepr_subscriptions s
            INNER JOIN {$wpdb->users} u ON s.user_id = u.ID
            WHERE s.gateway = %s
            AND s.product_id = %d
            AND s.status = 'active'
        ";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $gateway_string, $criteria['membership_id']));
        $matching = array();
        
        foreach ($results as $row) {
            $subscription = new MeprSubscription($row->id);
            
            // Skip subscriptions that already have the target price
            if (isset($criteria['new_price']) && abs($subscription->price - floatval($criteria['new_price'])) < 0.01) {
                continue;
            }
            
            // Price filter
            if (!$this->matches_price_criteria($subscription->price, $criteria)) {
                continue;
            }
            
            // Billing terms filter
            if (!$this->matches_billing_terms($subscription, $criteria)) {
                continue;
            }
            
            $matching[] = array(
                'subscription' => $subscription,
                'user' => (object)array(
                    'display_name' => $row->display_name,
                    'user_email' => $row->user_email
                )
            );
        }
        
        return $matching;
    }
    
    private function matches_price_criteria($current_price, $criteria) {
        if ($criteria['price_filter_type'] === 'specific') {
            if (!empty($criteria['specific_price'])) {
                return abs($current_price - floatval($criteria['specific_price'])) < 0.01;
            }
        } else if ($criteria['price_filter_type'] === 'range') {
            $min_ok = empty($criteria['min_price']) || $current_price >= floatval($criteria['min_price']);
            $max_ok = empty($criteria['max_price']) || $current_price <= floatval($criteria['max_price']);
            return $min_ok && $max_ok;
        }
        
        return true; // No price filter
    }
    
    private function matches_billing_terms($subscription, $criteria) {
        if (!empty($criteria['billing_period']) && $subscription->period != intval($criteria['billing_period'])) {
            return false;
        }
        
        if (!empty($criteria['billing_interval']) && $subscription->period_type != $criteria['billing_interval']) {
            return false;
        }
        
        return true;
    }
    
    public function process_price_updates() {
        global $wpdb;
        
        // Check if there's already an active job
        $current_job = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}mpsp_jobs WHERE current_job = 1 AND status = 'running'"
        );
        if ($current_job) {
            return; // Another job is running
        }
        
        // Check if MemberPress is still available
        if (!class_exists('MeprOptions') || !class_exists('MeprSubscription')) {
            error_log('MemberPress Stripe Price Updater: MemberPress not available during cron execution');
            return;
        }
        
        // Get enabled rules
        $rules = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mpsp_rules WHERE enabled = 1 ORDER BY updated_at ASC",
            ARRAY_A
        );
        
        if (empty($rules)) {
            // No enabled rules to process - this is normal when all rules are completed
            return;
        }
        
        // Check if there's already a running job that we should continue
        $existing_job = $wpdb->get_row(
            "SELECT j.*, r.name as rule_name FROM {$wpdb->prefix}mpsp_jobs j 
             INNER JOIN {$wpdb->prefix}mpsp_rules r ON j.rule_id = r.id 
             WHERE j.status = 'running' AND r.enabled = 1 
             ORDER BY j.started_at ASC LIMIT 1",
            ARRAY_A
        );
        
        if ($existing_job) {
            // Continue the existing job
            $this->continue_background_job($existing_job);
            return;
        }
        
        // No running job exists, check for rules that need new jobs
        foreach ($rules as $rule) {
            try {
                $matching_subscriptions = $this->get_matching_subscriptions($rule);
                if (count($matching_subscriptions) > 0) {
                    $this->start_new_job($rule, $matching_subscriptions);
                    break; // Only start one new job at a time
                } else {
                    // Auto-disable rules that have no more work to do
                    $this->check_and_auto_disable_rule($rule);
                }
            } catch (Exception $e) {
                error_log('MemberPress Stripe Price Updater cron error: ' . $e->getMessage());
            }
        }
    }
    
    private function start_new_job($rule, $all_matching_subscriptions, $max_to_process = null) {
        global $wpdb;
        
        $total_subscriptions = count($all_matching_subscriptions);
        
        // If no subscriptions match, don't start a job
        if ($total_subscriptions === 0) {
            return;
        }
        
        // Safety check: Limit processing to prevent timeouts and server overload
        if ($max_to_process === null) {
            $max_per_run = apply_filters('mpsp_max_subscriptions_per_run', 25);
        } else {
            $max_per_run = $max_to_process;
        }
        $subscriptions_to_process = array_slice($all_matching_subscriptions, 0, $max_per_run);
        
        // Create job in database with TOTAL subscription count, not just the batch
        $job_id = $wpdb->insert(
            $wpdb->prefix . 'mpsp_jobs',
            array(
                'rule_id' => $rule['id'],
                'status' => 'running',
                'total_subscriptions' => $total_subscriptions, // Full count, not batch
                'processed_count' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'started_at' => current_time('mysql'),
                'last_activity_at' => current_time('mysql'),
                'current_job' => 1
            ),
            array('%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d')
        );
        
        if ($job_id === false) {
            error_log('Failed to create job in database');
            return;
        }
        
        $job_id = $wpdb->insert_id;
        
        // Process first batch of subscriptions
        $this->process_job_subscriptions($job_id, $rule, $subscriptions_to_process);
    }
    
    private function continue_background_job($existing_job) {
        global $wpdb;
        
        // Get the rule for this job
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mpsp_rules WHERE id = %d",
            $existing_job['rule_id']
        ), ARRAY_A);
        
        if (!$rule) {
            error_log('MPSP: Rule not found for job ' . $existing_job['id']);
            return;
        }
        
        // Get all matching subscriptions for this rule
        $all_matching_subscriptions = $this->get_matching_subscriptions($rule);
        
        // Get subscription IDs that have already been processed in this job
        $processed_subscription_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT subscription_id FROM {$wpdb->prefix}mpsp_entries WHERE job_id = %d",
            $existing_job['id']
        ));
        
        // Filter out already processed subscriptions
        $remaining_subscriptions = array();
        foreach ($all_matching_subscriptions as $sub_data) {
            if (!in_array($sub_data['subscription']->id, $processed_subscription_ids)) {
                $remaining_subscriptions[] = $sub_data;
            }
        }
        
        // Check if job is complete
        if (empty($remaining_subscriptions)) {
            // Job is complete - all subscriptions have been processed
            $this->complete_job($existing_job['id'], $rule);
            
            // Auto-disable the rule since it has no more subscriptions to process
            $this->check_and_auto_disable_rule($rule);
            return;
        }
        
        // Process next batch
        $max_per_run = apply_filters('mpsp_max_subscriptions_per_run', 25);
        $subscriptions_to_process = array_slice($remaining_subscriptions, 0, $max_per_run);
        
        $this->process_job_subscriptions($existing_job['id'], $rule, $subscriptions_to_process);
    }
    
    private function check_and_auto_disable_rule($rule) {
        global $wpdb;
        
        // Only disable if this rule has processed subscriptions before
        $processed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mpsp_jobs WHERE rule_id = %d AND status = 'completed' AND processed_count > 0",
            $rule['id']
        ));
        
        if ($processed_count > 0) {
            // This rule has done work before but now has no matching subscriptions
            // Auto-disable it to prevent unnecessary processing
            $wpdb->update(
                $wpdb->prefix . 'mpsp_rules',
                array(
                    'enabled' => 0,
                    'auto_disabled_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $rule['id']),
                array('%d', '%s', '%s'),
                array('%d')
            );
            
            error_log(sprintf(
                'MPSP: Auto-disabled rule "%s" (ID: %d) - no more subscriptions to process',
                $rule['name'],
                $rule['id']
            ));
        }
    }
    
    private function process_job_subscriptions($job_id, $rule, $matching_subscriptions) {
        global $wpdb;
        
        $success_count = 0;
        $error_count = 0;
        
        // Set up timeout protection  
        $start_time = time();
        $max_execution_time = apply_filters('mpsp_max_execution_time', 120); // 2 minutes default
        
        foreach ($matching_subscriptions as $sub_data) {
            // Check if job was cancelled
            $current_job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mpsp_jobs WHERE id = %d AND status = 'cancelled'",
                $job_id
            ));
            if ($current_job) {
                break;
            }
            
            // Check execution time to prevent timeouts
            if ((time() - $start_time) > $max_execution_time) {
                // Update job status to indicate time limit reached
                $wpdb->update(
                    $wpdb->prefix . 'mpsp_jobs',
                    array(
                        'last_activity_at' => current_time('mysql'),
                        'processed_count' => $success_count + $error_count,
                        'success_count' => $success_count,
                        'error_count' => $error_count
                    ),
                    array('id' => $job_id),
                    array('%s', '%d', '%d', '%d'),
                    array('%d')
                );
                break;
            }
            
            $subscription = $sub_data['subscription'];
            $user = $sub_data['user'];
            
            try {
                // Update in Stripe first
                $stripe_result = $this->update_stripe_subscription($subscription, $rule);
                
                if ($stripe_result['success']) {
                    // Update in MemberPress
                    $mp_result = $this->update_memberpress_subscription($subscription, $rule);
                    
                    if ($mp_result['success']) {
                        $success_count++;
                        $status = 'success';
                        $error_message = null;
                    } else {
                        $error_count++;
                        $status = 'error';
                        $error_message = 'MemberPress update failed: ' . $mp_result['message'];
                    }
                } else {
                    $error_count++;
                    $status = 'error';
                    $error_message = 'Stripe update failed: ' . $stripe_result['message'];
                }
                
                // Create entry in database
                $wpdb->insert(
                    $wpdb->prefix . 'mpsp_entries',
                    array(
                        'job_id' => $job_id,
                        'subscription_id' => $subscription->id,
                        'user_id' => $user->ID,
                        'old_price' => $subscription->price,
                        'new_price' => $rule['new_price'],
                        'status' => $status,
                        'stripe_price_id' => $stripe_result['stripe_price_id'] ?? null,
                        'api_mode' => $stripe_result['api_mode'] ?? 'unknown',
                        'error_message' => $error_message,
                        'processed_at' => current_time('mysql')
                    ),
                    array('%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s')
                );
                
            } catch (Exception $e) {
                $error_count++;
                
                // Create error entry
                $wpdb->insert(
                    $wpdb->prefix . 'mpsp_entries',
                    array(
                        'job_id' => $job_id,
                        'subscription_id' => $subscription->id,
                        'user_id' => $user->ID,
                        'old_price' => $subscription->price,
                        'new_price' => $rule['new_price'],
                        'status' => 'error',
                        'stripe_price_id' => null,
                        'api_mode' => 'unknown',
                        'error_message' => 'Exception: ' . $e->getMessage(),
                        'processed_at' => current_time('mysql')
                    ),
                    array('%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s')
                );
            }
            
            // Update job progress
            $wpdb->update(
                $wpdb->prefix . 'mpsp_jobs',
                array(
                    'last_activity_at' => current_time('mysql'),
                    'processed_count' => $success_count + $error_count,
                    'success_count' => $success_count,
                    'error_count' => $error_count
                ),
                array('id' => $job_id),
                array('%s', '%d', '%d', '%d'),
                array('%d')
            );
        }
        
        // Complete the job
        $this->complete_job($job_id, $rule);
        
        // Auto-disable the rule since it has no more subscriptions to process
        $this->check_and_auto_disable_rule($rule);
    }
    
    private function complete_job($job_id, $rule) {
        try {
            global $wpdb;
            
            // Get the current job data
            $current_job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mpsp_jobs WHERE id = %d",
                $job_id
            ), ARRAY_A);
            
            if (!$current_job) {
                error_log('MPSP: Job not found for completion: ' . $job_id);
                return;
            }
            
            // Update rule stats in database
            if (!empty($rule['id'])) {
                $wpdb->update(
                    $wpdb->prefix . 'mpsp_rules',
                    array(
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $rule['id']),
                    array('%s'),
                    array('%d')
                );
            }
            
            $was_cancelled = ($current_job['status'] === 'cancelled');
            $final_status = $was_cancelled ? 'cancelled' : 'completed';
            
            // Update job completion in database
            $wpdb->update(
                $wpdb->prefix . 'mpsp_jobs',
                array(
                    'status' => $final_status,
                    'completed_at' => current_time('mysql'),
                    'current_job' => 0
                ),
                array('id' => $current_job['id']),
                array('%s', '%s', '%d'),
                array('%d')
            );
            
            // Generate job report and send admin email notification
            $job_report = $this->generate_job_report($current_job);
            $this->send_completion_email($rule, $current_job['processed_count'], $current_job['error_count'], array(), $was_cancelled, $job_report);
        } catch (Exception $e) {
            error_log('Error completing job: ' . $e->getMessage());
            // Ensure job status is cleared even if completion fails
            global $wpdb;
            $wpdb->query("UPDATE {$wpdb->prefix}mpsp_jobs SET current_job = 0 WHERE current_job = 1");
        }
    }
    
    private function generate_job_report($job_data) {
        global $wpdb;
        
        // Get detailed entries for this job from the database
        $entries = $wpdb->get_results($wpdb->prepare("
            SELECT e.*, u.display_name as user_display_name, u.user_email
            FROM {$wpdb->prefix}mpsp_entries e
            LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
            WHERE e.job_id = %d
            ORDER BY e.processed_at ASC
        ", $job_data['id']), ARRAY_A);
        
        // Format entries for CSV generation
        $detailed_log = array();
        foreach ($entries as $entry) {
            $detailed_log[] = array(
                'subscription_id' => $entry['subscription_id'],
                'user_email' => $entry['user_email'],
                'user_name' => $entry['user_display_name'],
                'old_price' => $entry['old_price'],
                'new_price' => $entry['new_price'],
                'status' => $entry['status'],
                'error_message' => $entry['error_message'],
                'stripe_subscription_id' => '', // This would need to be added to entries table if needed
                'api_mode' => $entry['api_mode'],
                'stripe_price_id' => $entry['stripe_price_id'],
                'updated_at' => $entry['processed_at']
            );
        }
        
        return array(
            'job_id' => $job_data['id'],
            'rule_name' => $job_data['rule_name'] ?? 'Unknown Rule',
            'rule_id' => $job_data['rule_id'],
            'started_at' => $job_data['started_at'],
            'completed_at' => $job_data['completed_at'] ?? current_time('mysql'),
            'processed_count' => $job_data['processed_count'],
            'error_count' => $job_data['error_count'],
            'detailed_log' => $detailed_log
        );
    }
    
    private function generate_csv_report($report) {
        try {
            $csv_content = "Subscription ID,User Email,User Name,Old Price,New Price,Status,Error Message,Stripe Subscription ID,API Mode,Stripe Price ID,Updated At\n";
            
            if (!empty($report['detailed_log']) && is_array($report['detailed_log'])) {
                foreach ($report['detailed_log'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    
                    $csv_content .= sprintf(
                        "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                        $this->escape_csv($entry['subscription_id'] ?? ''),
                        $this->escape_csv($entry['user_email'] ?? ''),
                        $this->escape_csv($entry['user_name'] ?? ''),
                        $this->escape_csv($entry['old_price'] ?? ''),
                        $this->escape_csv($entry['new_price'] ?? ''),
                        $this->escape_csv($entry['status'] ?? ''),
                        $this->escape_csv($entry['error_message'] ?? ''),
                        $this->escape_csv($entry['stripe_subscription_id'] ?? ''),
                        $this->escape_csv($entry['api_mode'] ?? ''),
                        $this->escape_csv($entry['stripe_price_id'] ?? ''),
                        $this->escape_csv($entry['updated_at'] ?? '')
                    );
                }
            }
            
            return $csv_content;
        } catch (Exception $e) {
            error_log('CSV generation error: ' . $e->getMessage());
            return "Subscription ID,User Email,User Name,Old Price,New Price,Status,Error Message,Stripe Subscription ID,API Mode,Stripe Price ID,Updated At\nError,,,,,CSV generation failed," . $e->getMessage() . ",,,\n";
        }
    }
    
    private function escape_csv($value) {
        try {
            // Convert to string and handle null values
            $value = (string) $value;
            $value = str_replace('"', '""', $value);
            if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
                return '"' . $value . '"';
            }
            return $value;
        } catch (Exception $e) {
            error_log('CSV escape error: ' . $e->getMessage());
            return '""'; // Return empty quoted string on error
        }
    }
    
    private function send_completion_email($rule, $processed_count, $error_count, $errors, $was_cancelled, $job_report) {
        $subject = $was_cancelled ? 
            'MemberPress Stripe Price Update Job Cancelled' : 
            'MemberPress Stripe Price Update Job Completed';
        
        $status = $was_cancelled ? 'CANCELLED' : 'COMPLETED';
        
        $message = "Price Update Job {$status}\n\n";
        $message .= "Rule: {$rule['name']}\n";
        $message .= "Started: {$job_report['started_at']}\n";
        $message .= "Completed: {$job_report['completed_at']}\n\n";
        $message .= "Results:\n";
        $message .= "- Successfully processed: {$processed_count} subscriptions\n";
        $message .= "- Errors: {$error_count}\n\n";
        
        if (!empty($errors)) {
            $message .= "Error Summary:\n";
            foreach (array_slice($errors, 0, 5) as $error) { // Limit to first 5 errors in email
                $message .= "- {$error}\n";
            }
            if (count($errors) > 5) {
                $message .= "... and " . (count($errors) - 5) . " more errors. See attached CSV report for full details.\n";
            }
            $message .= "\n";
        }
        
        $message .= "A detailed CSV report is attached with information about each subscription processed.\n\n";
        $message .= "You can view detailed reports in the MemberPress Stripe Price Updater plugin admin page.\n";
        
        // Generate CSV attachment
        $csv_content = $this->generate_csv_report($job_report);
        $csv_filename = 'stripe-price-update-report-' . date('Y-m-d-H-i-s') . '.csv';
        
        $attachments = array();
        $temp_file = '';
        
        try {
            // Create temporary file for attachment
            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['basedir']) && is_writable($upload_dir['basedir'])) {
                $temp_file = $upload_dir['basedir'] . '/' . $csv_filename;
                $file_created = file_put_contents($temp_file, $csv_content);
                
                if ($file_created !== false && file_exists($temp_file)) {
                    $attachments = array($temp_file);
                }
            }
        } catch (Exception $e) {
            error_log('Failed to create CSV attachment: ' . $e->getMessage());
            // Continue without attachment
        }
        
        // Prepare email headers
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8'
        );
        
        // Add note about attachment availability
        if (empty($attachments)) {
            $message .= "\nNote: CSV report attachment could not be created due to file system constraints. Reports are available in the plugin admin page.\n";
        }
        
        // Send email with or without attachment
        try {
            if (class_exists('MeprUtils') && method_exists('MeprUtils', 'wp_mail_to_admin')) {
                // Since MeprUtils may not support attachments, fall back to wp_mail with admin emails
                $mepr_options = MeprOptions::fetch();
                $admin_emails = array_map('trim', explode(',', $mepr_options->admin_email_addresses));
                
                foreach ($admin_emails as $email) {
                    if (is_email($email)) {
                        wp_mail($email, $subject, $message, $headers, $attachments);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Failed to send completion email: ' . $e->getMessage());
        }
        
        // Clean up temporary file
        if (!empty($temp_file) && file_exists($temp_file)) {
            try {
                unlink($temp_file);
            } catch (Exception $e) {
                error_log('Failed to clean up temporary file: ' . $e->getMessage());
            }
        }
    }

    private function update_stripe_subscription($subscription, $rule) {
        try {
            $options = MeprOptions::fetch();
            $gateway = $options->payment_method($rule['gateway_id']);

            if (!$gateway instanceof MeprStripeGateway) {
                return array('success' => false, 'message' => 'Gateway not found');
            }

            $stripe_sub_id = $subscription->subscr_id;
            
            if (empty($stripe_sub_id) || strpos($stripe_sub_id, 'sub_') !== 0) {
                return array('success' => false, 'message' => 'Invalid Stripe subscription ID');
            }
            
            // Get current subscription from Stripe using MemberPress gateway method
            $stripe_sub = $gateway->send_stripe_request("subscriptions/{$stripe_sub_id}", array(), 'get');
            
            if (!$stripe_sub || !empty($stripe_sub['error'])) {
                return array('success' => false, 'message' => 'Failed to retrieve Stripe subscription: ' . ($stripe_sub['error']['message'] ?? 'Unknown error'));
            }
            
            if (empty($stripe_sub['items']['data'][0]['id'])) {
                return array('success' => false, 'message' => 'Subscription item not found');
            }
            
            $item_id = $stripe_sub['items']['data'][0]['id'];
            
            // Check currency compatibility before proceeding
            $stripe_currency = strtoupper($stripe_sub['currency'] ?? '');
            $memberpress_currency = strtoupper($options->currency_code ?? '');
            
            if (!empty($stripe_currency) && !empty($memberpress_currency) && $stripe_currency !== $memberpress_currency) {
                return array(
                    'success' => false, 
                    'message' => "Currency mismatch: Stripe subscription uses {$stripe_currency} but MemberPress is configured for {$memberpress_currency}. Cannot update subscription with different currency."
                );
            }
            
            // Get or create Stripe price using MemberPress gateway method
            try {
                // Get the product associated with this subscription
                $product = new MeprProduct($subscription->product_id);
                
                // Use MemberPress method to get or create a price/plan ID
                $plan_id = $gateway->get_stripe_plan_id($subscription, $product, $rule['new_price']);
                
                if (empty($plan_id)) {
                    return array('success' => false, 'message' => 'Failed to get or create Stripe price/plan');
                }
                
                $new_price_id = $plan_id;
            } catch (Exception $e) {
                return array('success' => false, 'message' => 'Error creating Stripe price: ' . $e->getMessage());
            }
            
            // Update subscription with new price
            // According to 2024 Stripe API, we must specify the subscription item ID to replace
            // to prevent creating duplicate subscription items
            $update_data = array(
                'items' => array(
                    array(
                        'id' => $item_id,
                        'price' => $new_price_id
                    )
                ),
                'proration_behavior' => 'none', // Disable prorations for price updates
                'expand' => array('latest_invoice') // Include latest invoice in response for verification
            );
            
            $updated_sub = $gateway->send_stripe_request("subscriptions/{$stripe_sub_id}", $update_data, 'post');
            
            if (!$updated_sub || !empty($updated_sub['error'])) {
                return array('success' => false, 'message' => 'Failed to update subscription: ' . ($updated_sub['error']['message'] ?? 'Unknown error'));
            }
            
            // Verify the update was successful by checking the new price ID
            if (isset($updated_sub['items']['data'][0]['price']['id']) && 
                $updated_sub['items']['data'][0]['price']['id'] === $new_price_id) {
                return array(
                    'success' => true, 
                    'stripe_price_id' => $new_price_id,
                    'subscription_status' => $updated_sub['status'] ?? 'unknown',
                    'api_mode' => $gateway->is_test_mode() ? 'test' : 'live'
                );
            } else {
                return array('success' => false, 'message' => 'Subscription update verification failed - price not updated correctly');
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Stripe API exception: ' . $e->getMessage());
        }
    }
    
    private function update_memberpress_subscription($subscription, $rule) {
        global $wpdb;
        
        try {
            // Direct database update to avoid triggering emails, webhooks, and other events
            // Only update the subscription - existing transactions should remain unchanged
            $updated = $wpdb->update(
                $wpdb->prefix . 'mepr_subscriptions',
                array(
                    'price' => $rule['new_price'],
                    'total' => $rule['new_price']
                ),
                array('id' => $subscription->id),
                array('%f', '%f'),
                array('%d')
            );
            
            if ($updated === false) {
                return array('success' => false, 'message' => 'Failed to update subscription in database');
            }
            
            return array('success' => true);
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    
    private function display_active_rules() {
        global $wpdb;
        
        $rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mpsp_rules ORDER BY created_at DESC", ARRAY_A);
        
        if (empty($rules)) {
            echo '<p>No price update rules found.</p>';
            return;
        }
        
        ?>
        <div style="margin-bottom: 20px;">
            <button type="button" id="purge-all-data" class="button" style="background: #dc3232; color: white; border-color: #dc3232;">
                Purge All Data
            </button>
            <small style="margin-left: 10px; color: #666;">This will delete ALL rules, jobs, and entries permanently.</small>
        </div>
        
        <div class="mpsp-table-container">
            <table class="wp-list-table widefat fixed striped mpsp-rules-table">
                <thead>
                    <tr>
                        <th>Rule Name</th>
                        <th>Status</th>
                        <th>Gateway</th>
                        <th>Membership</th>
                        <th>Price Filter</th>
                        <th>Billing Filter</th>
                        <th>New Price</th>
                        <th>Created</th>
                        <th>Jobs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><div class="mpsp-truncate" title="<?php echo esc_attr($rule['name']); ?>"><?php echo esc_html($rule['name']); ?></div></td>
                        <td>
                            <?php if (empty($rule['enabled'])): ?>
                                <span class="mpsp-status-disabled">Disabled</span>
                                <?php if (!empty($rule['auto_disabled_at'])): ?>
                                    <br><small style="color: #666; font-style: italic;">
                                        Auto-disabled: <?php echo esc_html(date('M j, Y H:i', strtotime($rule['auto_disabled_at']))); ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="mpsp-status-active">Active</span>
                            <?php endif; ?>
                        </td>
                        <td><div class="mpsp-truncate" title="<?php echo esc_attr($this->get_gateway_label($rule['gateway_id'])); ?>"><?php echo esc_html($this->get_gateway_label($rule['gateway_id'])); ?></div></td>
                        <td><div class="mpsp-truncate" title="<?php echo esc_attr(get_the_title($rule['membership_id'])); ?>"><?php echo esc_html(get_the_title($rule['membership_id'])); ?></div></td>
                        <td>
                            <?php
                            if ($rule['price_filter_type'] === 'specific') {
                                echo esc_html(MeprAppHelper::format_currency($rule['specific_price']));
                            } else {
                                $min = !empty($rule['min_price']) ? MeprAppHelper::format_currency($rule['min_price']) : 'Any';
                                $max = !empty($rule['max_price']) ? MeprAppHelper::format_currency($rule['max_price']) : 'Any';
                                echo esc_html($min . ' - ' . $max);
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $period = !empty($rule['billing_period']) ? $rule['billing_period'] : 'Any';
                            $interval = !empty($rule['billing_interval']) ? $rule['billing_interval'] : 'Any';
                            echo esc_html($period . ' ' . $interval);
                            ?>
                        </td>
                        <td><?php echo esc_html(MeprAppHelper::format_currency($rule['new_price'])); ?></td>
                        <td><?php echo esc_html(date('Y-m-d', strtotime($rule['created_at']))); ?></td>
                        <td>
                            <?php
                            $job_count = $wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}mpsp_jobs WHERE rule_id = %d",
                                $rule['id']
                            ));
                            echo intval($job_count);
                            ?>
                        </td>
                        <td class="mpsp-rule-actions">
                            <?php if (empty($rule['enabled'])): ?>
                                <button class="button button-small enable-rule" data-rule-id="<?php echo $rule['id']; ?>">Enable</button>
                            <?php endif; ?>
                            <button class="button button-small delete-rule" data-rule-id="<?php echo $rule['id']; ?>">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <h3 style="margin-top: 30px;">Jobs</h3>
        <?php $this->display_jobs(); ?>
        <?php
    }
    
    private function display_jobs() {
        global $wpdb;
        
        $jobs = $wpdb->get_results("
            SELECT j.*, r.name as rule_name 
            FROM {$wpdb->prefix}mpsp_jobs j 
            LEFT JOIN {$wpdb->prefix}mpsp_rules r ON j.rule_id = r.id 
            ORDER BY j.started_at DESC
        ", ARRAY_A);
        
        if (empty($jobs)) {
            echo '<p>No jobs found.</p>';
            return;
        }
        
        ?>
        <div class="mpsp-table-container">
            <table class="wp-list-table widefat fixed striped mpsp-reports-table">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Rule Name</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Completed</th>
                        <th>Processed</th>
                        <th>Success</th>
                        <th>Errors</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><span title="Job ID: <?php echo esc_attr($job['id']); ?>"><?php echo esc_html($job['id']); ?></span></td>
                        <td><div class="mpsp-truncate" title="<?php echo esc_attr($job['rule_name'] ?? 'Unknown'); ?>"><?php echo esc_html($job['rule_name'] ?? 'Unknown'); ?></div></td>
                        <td>
                            <?php
                            $status_class = '';
                            switch ($job['status']) {
                                case 'completed':
                                    $status_class = 'mpsp-status-success';
                                    break;
                                case 'running':
                                    $status_class = 'mpsp-status-active';
                                    break;
                                case 'cancelled':
                                case 'failed':
                                    $status_class = 'mpsp-status-error';
                                    break;
                            }
                            ?>
                            <span class="<?php echo $status_class; ?>"><?php echo esc_html(ucfirst($job['status'])); ?></span>
                        </td>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($job['started_at']))); ?></td>
                        <td><?php echo $job['completed_at'] ? esc_html(date('Y-m-d H:i:s', strtotime($job['completed_at']))) : '-'; ?></td>
                        <td><?php echo intval($job['processed_count']); ?></td>
                        <td><?php echo intval($job['success_count']); ?></td>
                        <td>
                            <span class="<?php echo $job['error_count'] > 0 ? 'mpsp-status-error' : 'mpsp-status-success'; ?>">
                                <?php echo intval($job['error_count']); ?>
                            </span>
                        </td>
                        <td class="mpsp-rule-actions">
                            <button class="button button-small view-job-entries" 
                                    data-job-id="<?php echo esc_attr($job['id']); ?>">
                                View Log
                            </button>
                            <?php if ($job['status'] === 'completed' && $job['processed_count'] > 0): ?>
                            <button class="button button-small download-csv-report" 
                                    data-job-id="<?php echo esc_attr($job['id']); ?>">
                                Download CSV
                            </button>
                            <?php endif; ?>
                            <button class="button button-small delete-job" 
                                    data-job-id="<?php echo esc_attr($job['id']); ?>">
                                Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Download CSV report buttons
            $(document).on('click', '.download-csv-report', function() {
                var jobId = $(this).data('job-id');
                
                if (!jobId) {
                    alert('Job ID not found');
                    return;
                }
                
                // Create a temporary form to submit the download request
                var form = $('<form>', {
                    'method': 'POST',
                    'action': mpsp_ajax.ajax_url,
                    'target': '_blank'
                }).append($('<input>', {
                    'type': 'hidden',
                    'name': 'action',
                    'value': 'mpsp_download_csv_report'
                })).append($('<input>', {
                    'type': 'hidden',
                    'name': 'job_id',
                    'value': jobId
                })).append($('<input>', {
                    'type': 'hidden',
                    'name': 'nonce',
                    'value': mpsp_ajax.nonce
                }));
                
                $('body').append(form);
                form.submit();
                form.remove();
            });
            
            // View job log buttons
            $(document).on('click', '.view-job-log', function() {
                var jobId = $(this).data('job-id');
                showJobLogModal(jobId);
            });
        });
        
        function showJobLogModal(jobId) {
            $.post(mpsp_ajax.ajax_url, {
                action: 'mpsp_get_job_log',
                job_id: jobId,
                nonce: mpsp_ajax.nonce
            }, function(response) {
                if (response.success) {
                    var logEntries = response.data;
                    var modalHtml = '<div id="job-log-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000; display: flex; align-items: center; justify-content: center;">' +
                        '<div style="background: white; padding: 20px; border-radius: 5px; max-width: 90%; max-height: 90%; overflow: auto; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">' +
                        '<h3>Job Log History - ' + jobId.substring(0, 8) + '...</h3>' +
                        '<div class="mpsp-table-container" style="max-height: 60vh; overflow-y: auto;">' +
                        '<table class="wp-list-table widefat fixed striped mpsp-log-table">' +
                        '<thead><tr><th>Date</th><th>Subscription ID</th><th>Old Price</th><th>New Price</th><th>Status</th><th>Message</th></tr></thead>' +
                        '<tbody>';
                    
                    if (logEntries.length === 0) {
                        modalHtml += '<tr><td colspan="6" style="text-align: center; padding: 20px;">No log entries found for this job.</td></tr>';
                    } else {
                        logEntries.forEach(function(entry) {
                            var statusClass = entry.status === 'success' ? 'mpsp-status-success' : 'mpsp-status-error';
                            modalHtml += '<tr>' +
                                '<td>' + entry.updated_at + '</td>' +
                                '<td>' + entry.subscription_id + '</td>' +
                                '<td>' + entry.old_price + '</td>' +
                                '<td>' + entry.new_price + '</td>' +
                                '<td><span class="' + statusClass + '">' + entry.status.charAt(0).toUpperCase() + entry.status.slice(1) + '</span></td>' +
                                '<td><div class="mpsp-truncate" title="' + entry.message + '">' + entry.message + '</div></td>' +
                                '</tr>';
                        });
                    }
                    
                    modalHtml += '</tbody></table></div>' +
                        '<div style="margin-top: 20px; text-align: right;">' +
                        '<button type="button" onclick="closeJobLogModal()" class="button button-primary">Close</button>' +
                        '</div></div></div>';
                    
                    $('body').append(modalHtml);
                } else {
                    alert('Error loading job log: ' + response.data);
                }
            });
        }
        
        function closeJobLogModal() {
            $('#job-log-modal').remove();
        }
        
        // Close modal when clicking outside
        $(document).on('click', '#job-log-modal', function(e) {
            if (e.target === this) {
                closeJobLogModal();
            }
        });
        </script>
        <?php
    }
    
    private function get_gateway_label($gateway_id) {
        $options = MeprOptions::fetch();
        $pm = $options->payment_method($gateway_id);

        if ($pm instanceof MeprStripeGateway) {
            $label = $pm->label ?: 'Stripe Gateway';
        } else {
            $label = 'Unknown Gateway';
        }

        return $label;
    }
    
    public function add_cron_intervals($schedules) {
        $schedules['every_5_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display' => __('Every 5 Minutes', 'mpsp')
        );
        return $schedules;
    }
    
    public function cleanup_logs() {
        try {
            global $wpdb;
            
            // Clean up old jobs - keep only last 50 jobs to maintain performance
            $old_jobs = $wpdb->get_results(
                "SELECT id FROM {$wpdb->prefix}mpsp_jobs 
                 ORDER BY started_at DESC 
                 LIMIT 50, 999999", // Skip first 50, get the rest
                ARRAY_A
            );
            
            if (!empty($old_jobs)) {
                $job_ids_to_delete = array_column($old_jobs, 'id');
                $job_ids_placeholders = implode(',', array_fill(0, count($job_ids_to_delete), '%d'));
                
                // Delete entries first (foreign key constraint)
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}mpsp_entries 
                     WHERE job_id IN ($job_ids_placeholders)",
                    ...$job_ids_to_delete
                ));
                
                // Then delete the jobs
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}mpsp_jobs 
                     WHERE id IN ($job_ids_placeholders)",
                    ...$job_ids_to_delete
                ));
            }
            
            // Clean up orphaned detailed job logs from old options system (legacy cleanup)
            $orphaned_logs = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} 
                 WHERE option_name LIKE 'mpsp_detailed_job_log_%' 
                 LIMIT 50"
            );
            
            foreach ($orphaned_logs as $option) {
                delete_option($option->option_name);
            }
            
            // Clean up legacy options that are no longer used
            $legacy_options = array(
                'mpsp_job_reports',
                'mpsp_price_update_log'
            );
            
            foreach ($legacy_options as $option_name) {
                if (get_option($option_name, false) !== false) {
                    delete_option($option_name);
                }
            }
            
            // Clean up stuck job status with intelligent timeout calculation
            global $wpdb;
            $current_job = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}mpsp_jobs WHERE current_job = 1 AND status = 'running'"
            );
            if ($current_job && !empty($current_job->started_at)) {
                $started_time = strtotime($current_job->started_at);
                if ($started_time) {
                    // Calculate expected job duration based on total subscriptions
                    $total_subscriptions = intval($current_job->total_subscriptions ?? 0);
                    $max_per_cycle = 25;
                    $cycle_duration = 5 * 60; // 5 minutes in seconds
                    
                    // Estimate cycles needed: total subscriptions / max per cycle (rounded up)
                    $estimated_cycles = ceil($total_subscriptions / $max_per_cycle);
                    // Double the estimation due to WordPress cron unpredictability
                    $estimated_duration = $estimated_cycles * $cycle_duration * 2;
                    
                    // Add buffer time: 50% extra + minimum 2 hours for safety
                    $buffer_time = max($estimated_duration * 0.5, 7200); // minimum 2 hours buffer
                    $timeout_duration = $estimated_duration + $buffer_time;
                    
                    // Minimum timeout of 4 hours, maximum of 1 week for sanity
                    $timeout_duration = max(14400, min($timeout_duration, 604800)); // 4 hours - 1 week
                    
                    $elapsed_time = time() - $started_time;
                    if ($elapsed_time > $timeout_duration) {
                        // Log the cleanup for debugging
                        error_log(sprintf(
                            'MPSP: Cleaning up stuck job after %d hours. Job had %d total subscriptions, estimated %d cycles, timeout was %d hours.',
                            round($elapsed_time / 3600, 1),
                            $total_subscriptions,
                            $estimated_cycles,
                            round($timeout_duration / 3600, 1)
                        ));
                        
                        // Mark the stuck job as failed in database
                        $wpdb->update(
                            $wpdb->prefix . 'mpsp_jobs',
                            array(
                                'status' => 'failed',
                                'completed_at' => current_time('mysql'),
                                'current_job' => 0
                            ),
                            array('id' => $current_job->id),
                            array('%s', '%s', '%d'),
                            array('%d')
                        );
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log('MPSP log cleanup error: ' . $e->getMessage());
        }
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('mpsp_process_price_updates');
        wp_clear_scheduled_hook('mpsp_cleanup_logs');
    }
    
    public function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Rules table
        $rules_table = $wpdb->prefix . 'mpsp_rules';
        $rules_sql = "CREATE TABLE $rules_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            gateway_id varchar(100) NOT NULL,
            membership_id int(11) NOT NULL,
            price_filter_type varchar(20) NOT NULL,
            min_price decimal(10,2) NULL,
            max_price decimal(10,2) NULL,
            specific_price decimal(10,2) NULL,
            billing_period int(11) NULL,
            billing_interval varchar(20) NULL,
            new_price decimal(10,2) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            auto_disabled_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY gateway_id (gateway_id),
            KEY membership_id (membership_id),
            KEY enabled (enabled)
        ) $charset_collate;";
        
        // Jobs table
        $jobs_table = $wpdb->prefix . 'mpsp_jobs';
        $jobs_sql = "CREATE TABLE $jobs_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            rule_id int(11) NOT NULL,
            status enum('running','completed','cancelled','failed') NOT NULL DEFAULT 'running',
            total_subscriptions int(11) NOT NULL DEFAULT 0,
            processed_count int(11) NOT NULL DEFAULT 0,
            success_count int(11) NOT NULL DEFAULT 0,
            error_count int(11) NOT NULL DEFAULT 0,
            started_at datetime NOT NULL,
            completed_at datetime NULL,
            last_activity_at datetime NOT NULL,
            current_job tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY rule_id (rule_id),
            KEY status (status),
            KEY started_at (started_at),
            KEY current_job (current_job)
        ) $charset_collate;";
        
        // Entries table
        $entries_table = $wpdb->prefix . 'mpsp_entries';
        $entries_sql = "CREATE TABLE $entries_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            job_id int(11) NOT NULL,
            subscription_id int(11) NOT NULL,
            user_id int(11) NOT NULL,
            old_price decimal(10,2) NOT NULL,
            new_price decimal(10,2) NOT NULL,
            status enum('success','error') NOT NULL,
            stripe_price_id varchar(100) NULL,
            api_mode enum('test','live') NOT NULL,
            error_message text NULL,
            processed_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY subscription_id (subscription_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY processed_at (processed_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($rules_sql);
        dbDelta($jobs_sql);
        dbDelta($entries_sql);
        
        // Migrate existing data from options table
        $this->migrate_existing_data();
        
        // Update database version to match plugin version
        update_option('mpsp_db_version', MPSP_VERSION);
    }
    
    private function migrate_existing_data() {
        global $wpdb;
        
        // Check if migration already done
        if (get_option('mpsp_data_migrated', false)) {
            return;
        }
        
        // Migrate rules from options to rules table
        $old_rules = get_option('mpsp_price_update_rules', array());
        if (!empty($old_rules)) {
            $rules_table = $wpdb->prefix . 'mpsp_rules';
            foreach ($old_rules as $rule) {
                $wpdb->insert(
                    $rules_table,
                    array(
                        'name' => $rule['name'],
                        'gateway_id' => $rule['gateway_id'],
                        'membership_id' => $rule['membership_id'],
                        'price_filter_type' => $rule['price_filter_type'],
                        'min_price' => $rule['min_price'],
                        'max_price' => $rule['max_price'],
                        'specific_price' => $rule['specific_price'],
                        'billing_period' => $rule['billing_period'],
                        'billing_interval' => $rule['billing_interval'],
                        'new_price' => $rule['new_price'],
                        'enabled' => !empty($rule['enabled']) ? 1 : 0,
                        'created_at' => $rule['created_at'] ?? current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%d', '%s', '%f', '%f', '%f', '%d', '%s', '%f', '%d', '%s', '%s')
                );
            }
        }
        
        // Mark migration as complete
        update_option('mpsp_data_migrated', true);
        
    }
}

// Initialize the plugin
MP_Stripe_Price_Updater::get_instance();
