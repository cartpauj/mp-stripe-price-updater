<?php
/**
 * Plugin Name: MemberPress Stripe Price Updater
 * Plugin URI: https://yoursite.com/
 * Description: Automate subscription price changes for MemberPress Stripe subscriptions with proper MP integration
 * Version: 2.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MPSP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MPSP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('MPSP_VERSION', '2.0.0');

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
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_mpsp_get_memberships', array($this, 'ajax_get_memberships'));
        add_action('wp_ajax_mpsp_preview_subscriptions', array($this, 'ajax_preview_subscriptions'));
        add_action('wp_ajax_mpsp_save_rule', array($this, 'ajax_save_rule'));
        add_action('wp_ajax_mpsp_delete_rule', array($this, 'ajax_delete_rule'));
        add_action('wp_ajax_mpsp_process_rule_now', array($this, 'ajax_process_rule_now'));
        add_action('wp_ajax_mpsp_get_job_status', array($this, 'ajax_get_job_status'));
        add_action('wp_ajax_mpsp_cancel_job', array($this, 'ajax_cancel_job'));
        add_action('wp_ajax_mpsp_download_csv_report', array($this, 'ajax_download_csv_report'));
        
        // Cron setup
        add_action('mpsp_process_price_updates', array($this, 'process_price_updates'));
        add_action('mpsp_cleanup_logs', array($this, 'cleanup_logs'));
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('mpsp_process_price_updates')) {
            wp_schedule_event(time(), 'every_5_minutes', 'mpsp_process_price_updates');
        }
        
        // Schedule daily log cleanup
        if (!wp_next_scheduled('mpsp_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'mpsp_cleanup_logs');
        }
        
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
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
                <h2>Active Price Update Rules</h2>
                <div class="notice notice-info inline" style="margin: 15px 0; padding: 10px;">
                    <p><strong>Processing Information:</strong> For server safety, the system processes up to 25 subscriptions every 5 minutes per rule. Large batches will be processed automatically over multiple cycles.</p>
                </div>
                <div id="active_rules">
                    <?php $this->display_active_rules(); ?>
                </div>
            </div>
        </div>
        
        <style>
        .mpsp-rule-actions {
            white-space: nowrap;
        }
        .mpsp-rule-actions .button {
            margin-right: 5px;
        }
        #subscription_preview table {
            border-collapse: collapse;
            width: 100%;
        }
        #subscription_preview th,
        #subscription_preview td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        #subscription_preview th {
            background-color: #f2f2f2;
        }
        .mpsp-processing {
            opacity: 0.6;
            pointer-events: none;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            
            // Check for active job on page load
            checkJobStatus();
            
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
            
            // Process rule now buttons
            $(document).on('click', '.process-rule-now', function() {
                var ruleIndex = $(this).data('index');
                processRuleNow(ruleIndex);
            });
            
            // Delete rule buttons
            $(document).on('click', '.delete-rule', function() {
                if (confirm('Are you sure you want to delete this rule?')) {
                    var ruleIndex = $(this).data('index');
                    deleteRule(ruleIndex);
                }
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
                        alert('Rule saved successfully! Price updates will be processed hourly via cron job.');
                        location.reload();
                    } else {
                        alert('Error saving rule: ' + response.data);
                    }
                });
            }
            
            function processRuleNow(ruleIndex) {
                if (!confirm('Process this rule now? This will immediately update all matching subscriptions.')) {
                    return;
                }
                
                // Check if there's already an active job
                checkJobStatus(function(hasActiveJob) {
                    if (hasActiveJob) {
                        alert('Another processing job is currently running. Please wait for it to complete.');
                        return;
                    }
                    
                    var button = $('.process-rule-now[data-index="' + ruleIndex + '"]');
                    button.addClass('mpsp-processing').text('Starting...');
                    
                    $.post(mpsp_ajax.ajax_url, {
                        action: 'mpsp_process_rule_now',
                        rule_index: ruleIndex,
                        nonce: mpsp_ajax.nonce
                    }, function(response) {
                        button.removeClass('mpsp-processing').text('Process Now');
                        
                        if (response.success) {
                            $('#mpsp-job-status').show();
                            startJobStatusPolling();
                        } else {
                            alert('Error starting job: ' + response.data);
                        }
                    });
                });
            }
            
            function deleteRule(ruleIndex) {
                $.post(mpsp_ajax.ajax_url, {
                    action: 'mpsp_delete_rule',
                    rule_index: ruleIndex,
                    nonce: mpsp_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error deleting rule: ' + response.data);
                    }
                });
            }
            
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
        
        $rule = array(
            'id' => uniqid(),
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
            'created_at' => current_time('mysql'),
            'last_processed' => null,
            'processed_count' => 0,
            'enabled' => true
        );
        
        $rules = get_option('mpsp_price_update_rules', array());
        $rules[] = $rule;
        update_option('mpsp_price_update_rules', $rules);
        
        wp_send_json_success();
    }
    
    public function ajax_delete_rule() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $rule_index = intval($_POST['rule_index']);
        $rules = get_option('mpsp_price_update_rules', array());
        
        if (isset($rules[$rule_index])) {
            unset($rules[$rule_index]);
            $rules = array_values($rules); // Re-index array
            update_option('mpsp_price_update_rules', $rules);
        }
        
        wp_send_json_success();
    }
    
    public function ajax_process_rule_now() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Check if there's already an active job
        $current_job = get_option('mpsp_current_job', null);
        if ($current_job && !empty($current_job['active'])) {
            wp_send_json_error('Another processing job is currently running');
        }
        
        $rule_index = intval($_POST['rule_index']);
        $rules = get_option('mpsp_price_update_rules', array());
        
        if (!isset($rules[$rule_index])) {
            wp_send_json_error('Rule not found');
        }
        
        // Start the job
        $this->start_background_job($rules[$rule_index], $rule_index);
        
        wp_send_json_success(array('message' => 'Job started successfully'));
    }
    
    public function ajax_get_job_status() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $job_status = get_option('mpsp_current_job', null);
        
        if (!$job_status || empty($job_status['active'])) {
            wp_send_json_success(array('active' => false));
        } else {
            wp_send_json_success($job_status);
        }
    }
    
    public function ajax_cancel_job() {
        if (!check_ajax_referer('mpsp_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Mark job as cancelled
        $job_status = get_option('mpsp_current_job', array());
        $job_status['active'] = false;
        $job_status['cancelled'] = true;
        $job_status['message'] = 'Job cancelled by user';
        update_option('mpsp_current_job', $job_status);
        
        wp_send_json_success(array('message' => 'Job cancelled successfully'));
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
            
            $report_index = intval($_POST['report_index']);
            $reports = get_option('mpsp_job_reports', array());
            
            if (!isset($reports[$report_index])) {
                wp_die('Report not found');
            }
            
            $report = $reports[$report_index];
            $csv_content = $this->generate_csv_report($report);
            $filename = 'stripe-price-update-report-' . substr($report['job_id'], 0, 8) . '.csv';
            
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
        // Check if there's already an active job
        $current_job = get_option('mpsp_current_job', null);
        if ($current_job && !empty($current_job['active'])) {
            return; // Another job is running
        }
        
        // Check if MemberPress is still available
        if (!class_exists('MeprOptions') || !class_exists('MeprSubscription')) {
            error_log('MemberPress Stripe Price Updater: MemberPress not available during cron execution');
            return;
        }
        
        $rules = get_option('mpsp_price_update_rules', array());
        
        if (empty($rules)) {
            return; // No rules to process
        }
        
        // Find the first enabled rule and process it
        foreach ($rules as $index => $rule) {
            if (!empty($rule['enabled'])) {
                try {
                    $this->start_background_job($rule, $index);
                    break; // Only process one rule at a time
                } catch (Exception $e) {
                    error_log('MemberPress Stripe Price Updater cron error: ' . $e->getMessage());
                }
            }
        }
    }
    
    private function start_background_job($rule, $rule_index) {
        // Get matching subscriptions to determine if there's work to do
        $matching_subscriptions = $this->get_matching_subscriptions($rule);
        $total_subscriptions = count($matching_subscriptions);
        
        // If no subscriptions match, don't start a job or send notifications
        if ($total_subscriptions === 0) {
            return; // Silent return - no job, no notifications
        }
        
        // Generate unique job ID for detailed logging
        $job_id = uniqid('job_', true);
        update_option('mpsp_current_job_id', $job_id);
        
        // Initialize job status
        $job_status = array(
            'active' => true,
            'job_id' => $job_id,
            'rule_name' => $rule['name'],
            'rule_index' => $rule_index,
            'started_at' => current_time('mysql'),
            'message' => 'Initializing job...',
            'processed' => 0,
            'total' => 0,
            'errors' => 0,
            'cancelled' => false
        );
        
        update_option('mpsp_current_job', $job_status);
        
        // Initialize detailed log for this job
        update_option('mpsp_detailed_job_log_' . $job_id, array());
        
        // Safety check: Limit processing to prevent timeouts and server overload
        $max_per_run = apply_filters('mpsp_max_subscriptions_per_run', 25);
        if ($total_subscriptions > $max_per_run) {
            $matching_subscriptions = array_slice($matching_subscriptions, 0, $max_per_run);
            $job_status['message'] = "Processing {$max_per_run} of {$total_subscriptions} matching subscriptions (limited for safety)...";
        } else {
            $job_status['message'] = "Processing {$total_subscriptions} subscriptions...";
        }
        
        $job_status['total'] = count($matching_subscriptions);
        $job_status['total_available'] = $total_subscriptions;
        update_option('mpsp_current_job', $job_status);
        
        // Process in background
        $this->process_job_subscriptions($rule, $rule_index, $matching_subscriptions);
    }
    
    private function process_job_subscriptions($rule, $rule_index, $matching_subscriptions) {
        $job_status = get_option('mpsp_current_job', array());
        $job_id = $job_status['job_id'] ?? uniqid();
        $processed_count = 0;
        $error_count = 0;
        $errors = array();
        
        // Set up timeout protection  
        $start_time = time();
        $max_execution_time = apply_filters('mpsp_max_execution_time', 120); // 2 minutes default
        
        foreach ($matching_subscriptions as $sub_data) {
            // Check if job was cancelled
            $current_job = get_option('mpsp_current_job', array());
            if (!empty($current_job['cancelled'])) {
                break;
            }
            
            // Check execution time to prevent timeouts
            if ((time() - $start_time) > $max_execution_time) {
                $errors[] = "Job stopped due to execution time limit ({$max_execution_time} seconds)";
                $job_status['message'] = 'Job stopped due to time limit - will continue in next 5-minute cycle';
                update_option('mpsp_current_job', $job_status);
                break;
            }
            
            $subscription = $sub_data['subscription'];
            $user = $sub_data['user'];
            
            // Prepare detailed log entry
            $log_entry = array(
                'subscription_id' => $subscription->id,
                'user_email' => $user->user_email,
                'user_name' => $user->display_name,
                'old_price' => $subscription->price,
                'new_price' => $rule['new_price'],
                'stripe_subscription_id' => $subscription->subscr_id,
                'updated_at' => current_time('mysql'),
                'status' => 'pending',
                'error_message' => ''
            );
            
            try {
                // Update progress
                $job_status['processed'] = $processed_count;
                $job_status['message'] = "Processing subscription {$subscription->id}...";
                update_option('mpsp_current_job', $job_status);
                
                // Update in Stripe first
                $stripe_result = $this->update_stripe_subscription($subscription, $rule);
                
                if ($stripe_result['success']) {
                    // Update in MemberPress
                    $mp_result = $this->update_memberpress_subscription($subscription, $rule);
                    
                    if ($mp_result['success']) {
                        $processed_count++;
                        $log_entry['status'] = 'success';
                        $log_entry['api_mode'] = $stripe_result['api_mode'] ?? 'unknown';
                        $log_entry['stripe_price_id'] = $stripe_result['stripe_price_id'] ?? '';
                        $this->log_price_update($subscription->id, $subscription->price, $rule['new_price'], 'success');
                    } else {
                        $error_count++;
                        $log_entry['status'] = 'mp_failed';
                        $log_entry['error_message'] = $mp_result['message'];
                        $log_entry['api_mode'] = $stripe_result['api_mode'] ?? 'unknown';
                        $errors[] = "Failed to update MemberPress subscription {$subscription->id}: {$mp_result['message']}";
                        $this->log_price_update($subscription->id, $subscription->price, $rule['new_price'], 'mp_failed', $mp_result['message']);
                    }
                } else {
                    $error_count++;
                    $log_entry['status'] = 'stripe_failed';
                    $log_entry['error_message'] = $stripe_result['message'];
                    $errors[] = "Failed to update Stripe subscription {$subscription->subscr_id}: {$stripe_result['message']}";
                    $this->log_price_update($subscription->id, $subscription->price, $rule['new_price'], 'stripe_failed', $stripe_result['message']);
                }
            } catch (Exception $e) {
                $error_count++;
                $log_entry['status'] = 'exception';
                $log_entry['error_message'] = $e->getMessage();
                $errors[] = "Exception processing subscription {$subscription->id}: " . $e->getMessage();
                $this->log_price_update($subscription->id, $subscription->price, $rule['new_price'], 'exception', $e->getMessage());
            }
            
            // Add to detailed job log
            $detailed_log = get_option('mpsp_detailed_job_log_' . $job_id, array());
            $detailed_log[] = $log_entry;
            update_option('mpsp_detailed_job_log_' . $job_id, $detailed_log);
            
            // Update job status
            $job_status['processed'] = $processed_count;
            $job_status['errors'] = $error_count;
            update_option('mpsp_current_job', $job_status);
        }
        
        // Complete the job
        $this->complete_job($rule, $rule_index, $processed_count, $error_count, $errors);
    }
    
    private function complete_job($rule, $rule_index, $processed_count, $error_count, $errors) {
        try {
            // Update rule stats
            $rules = get_option('mpsp_price_update_rules', array());
            if (isset($rules[$rule_index])) {
                $rules[$rule_index]['last_processed'] = current_time('mysql');
                $rules[$rule_index]['processed_count'] = ($rules[$rule_index]['processed_count'] ?? 0) + $processed_count;
                update_option('mpsp_price_update_rules', $rules);
            }
            
            // Generate detailed job report
            $job_report = $this->generate_job_report($rule, $processed_count, $error_count);
            
            // Clear job status
            $job_status = get_option('mpsp_current_job', array());
            $was_cancelled = !empty($job_status['cancelled']);
            delete_option('mpsp_current_job');
            
            // Send admin email notification with CSV report
            $this->send_completion_email($rule, $processed_count, $error_count, $errors, $was_cancelled, $job_report);
        } catch (Exception $e) {
            error_log('Error completing job: ' . $e->getMessage());
            // Ensure job status is cleared even if completion fails
            delete_option('mpsp_current_job');
        }
    }
    
    private function generate_job_report($rule, $processed_count, $error_count) {
        // Get the detailed log entries for this job session
        $job_id = get_option('mpsp_current_job_id', uniqid());
        $detailed_log = get_option('mpsp_detailed_job_log_' . $job_id, array());
        
        $report = array(
            'job_id' => $job_id,
            'rule_name' => $rule['name'],
            'started_at' => get_option('mpsp_current_job', array())['started_at'] ?? current_time('mysql'),
            'completed_at' => current_time('mysql'),
            'processed_count' => $processed_count,
            'error_count' => $error_count,
            'detailed_log' => $detailed_log
        );
        
        // Store the report for display on admin page
        $reports = get_option('mpsp_job_reports', array());
        $reports[] = $report;
        
        // Keep only last 15 reports
        if (count($reports) > 15) {
            $reports = array_slice($reports, -15);
        }
        
        update_option('mpsp_job_reports', $reports);
        
        // Clean up the detailed log
        delete_option('mpsp_detailed_job_log_' . $job_id);
        delete_option('mpsp_current_job_id');
        
        return $report;
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
            
            // The MemberPress gateway will handle API key selection automatically
            
            $stripe_sub_id = $subscription->subscr_id;
            
            if (empty($stripe_sub_id) || strpos($stripe_sub_id, 'sub_') !== 0) {
                return array('success' => false, 'message' => 'Invalid Stripe subscription ID');
            }
            
            // Determine API mode using MemberPress's proper test mode detection
            $use_test = $gateway->is_test_mode();
            
            // Get current subscription from Stripe using MemberPress gateway method
            $stripe_sub = $gateway->send_stripe_request("subscriptions/{$stripe_sub_id}", array(), 'get');
            
            if (!$stripe_sub || !empty($stripe_sub['error'])) {
                return array('success' => false, 'message' => 'Failed to retrieve Stripe subscription: ' . ($stripe_sub['error']['message'] ?? 'Unknown error'));
            }
            
            if (empty($stripe_sub['items']['data'][0]['id'])) {
                return array('success' => false, 'message' => 'Subscription item not found');
            }
            
            $item_id = $stripe_sub['items']['data'][0]['id'];
            $current_price = $stripe_sub['items']['data'][0]['price'];
            
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
                    'api_mode' => $use_test ? 'test' : 'live'
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
    
    
    private function log_price_update($subscription_id, $old_price, $new_price, $status, $message = '') {
        $log = get_option('mpsp_price_update_log', array());
        
        $log[] = array(
            'subscription_id' => $subscription_id,
            'old_price' => $old_price,
            'new_price' => $new_price,
            'status' => $status,
            'message' => $message,
            'updated_at' => current_time('mysql')
        );
        
        // No artificial limit - entries will be cleaned up when job reports are cleaned up
        
        update_option('mpsp_price_update_log', $log);
    }
    
    private function display_active_rules() {
        $rules = get_option('mpsp_price_update_rules', array());
        
        if (empty($rules)) {
            echo '<p>No active price update rules.</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Rule Name</th>
                    <th>Gateway</th>
                    <th>Membership</th>
                    <th>Price Filter</th>
                    <th>Billing Filter</th>
                    <th>New Price</th>
                    <th>Created</th>
                    <th>Last Processed</th>
                    <th>Count</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $index => $rule): ?>
                <tr>
                    <td><?php echo esc_html($rule['name']); ?></td>
                    <td><?php echo esc_html($this->get_gateway_label($rule['gateway_id'])); ?></td>
                    <td><?php echo esc_html(get_the_title($rule['membership_id'])); ?></td>
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
                    <td><?php echo $rule['last_processed'] ? esc_html(date('Y-m-d H:i', strtotime($rule['last_processed']))) : 'Never'; ?></td>
                    <td><?php echo intval($rule['processed_count'] ?? 0); ?></td>
                    <td class="mpsp-rule-actions">
                        <button class="button button-small process-rule-now" data-index="<?php echo $index; ?>">Process Now</button>
                        <button class="button button-small delete-rule" data-index="<?php echo $index; ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h3 style="margin-top: 30px;">Recent Processing Log</h3>
        <?php $this->display_processing_log(); ?>
        
        <h3 style="margin-top: 30px;">Job Reports</h3>
        <?php $this->display_job_reports(); ?>
        <?php
    }
    
    private function display_processing_log() {
        $log = get_option('mpsp_price_update_log', array());
        
        if (empty($log)) {
            echo '<p>No processing log entries yet.</p>';
            return;
        }
        
        // Show last 20 entries
        $recent_log = array_slice(array_reverse($log), 0, 20);
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Subscription ID</th>
                    <th>Old Price</th>
                    <th>New Price</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_log as $entry): ?>
                <tr>
                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($entry['updated_at']))); ?></td>
                    <td><?php echo esc_html($entry['subscription_id']); ?></td>
                    <td><?php echo esc_html(MeprAppHelper::format_currency($entry['old_price'])); ?></td>
                    <td><?php echo esc_html(MeprAppHelper::format_currency($entry['new_price'])); ?></td>
                    <td>
                        <span style="color: <?php echo $entry['status'] === 'success' ? 'green' : 'red'; ?>;">
                            <?php echo esc_html(ucfirst($entry['status'])); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($entry['message']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    private function display_job_reports() {
        $reports = get_option('mpsp_job_reports', array());
        
        if (empty($reports)) {
            echo '<p>No job reports available yet.</p>';
            return;
        }
        
        // Show most recent reports first
        $reports_reversed = array_reverse($reports);
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>Rule Name</th>
                    <th>Started</th>
                    <th>Completed</th>
                    <th>Processed</th>
                    <th>Errors</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports_reversed as $display_index => $report): 
                    $actual_index = count($reports) - 1 - $display_index; // Calculate original index
                ?>
                <tr>
                    <td><?php echo esc_html(substr($report['job_id'], 0, 8)); ?>...</td>
                    <td><?php echo esc_html($report['rule_name']); ?></td>
                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($report['started_at']))); ?></td>
                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($report['completed_at']))); ?></td>
                    <td><?php echo intval($report['processed_count']); ?></td>
                    <td style="color: <?php echo $report['error_count'] > 0 ? 'red' : 'green'; ?>;">
                        <?php echo intval($report['error_count']); ?>
                    </td>
                    <td>
                        <button class="button button-small download-csv-report" 
                                data-index="<?php echo $actual_index; ?>"
                                data-job-id="<?php echo esc_attr($report['job_id']); ?>">
                            Download CSV
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            // Download CSV report buttons
            $(document).on('click', '.download-csv-report', function() {
                var reportIndex = $(this).data('index');
                var jobId = $(this).data('job-id');
                
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
                    'name': 'report_index',
                    'value': reportIndex
                })).append($('<input>', {
                    'type': 'hidden',
                    'name': 'nonce',
                    'value': mpsp_ajax.nonce
                }));
                
                $('body').append(form);
                form.submit();
                form.remove();
            });
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
            // Clean up job reports - keep only last 15 entries
            $reports = get_option('mpsp_job_reports', array());
            if (count($reports) > 15) {
                // Get timestamps of reports being removed to clean up corresponding price update log entries
                $reports_to_remove = array_slice($reports, 0, count($reports) - 15);
                $cutoff_times = array();
                
                foreach ($reports_to_remove as $report) {
                    if (isset($report['started_at'])) {
                        $cutoff_times[] = $report['started_at'];
                    }
                    if (isset($report['completed_at'])) {
                        $cutoff_times[] = $report['completed_at'];
                    }
                }
                
                // Find the earliest timestamp from reports being removed
                if (!empty($cutoff_times)) {
                    $earliest_cutoff = min($cutoff_times);
                    
                    // Clean up price update log entries that correspond to removed job reports
                    $price_log = get_option('mpsp_price_update_log', array());
                    $filtered_log = array();
                    
                    foreach ($price_log as $entry) {
                        // Keep entries that are newer than the earliest removed job report
                        if (isset($entry['updated_at']) && $entry['updated_at'] >= $earliest_cutoff) {
                            $filtered_log[] = $entry;
                        }
                    }
                    
                    update_option('mpsp_price_update_log', $filtered_log);
                }
                
                // Keep only the last 15 job reports
                $reports = array_slice($reports, -15);
                update_option('mpsp_job_reports', $reports);
            }
            
            // Clean up orphaned detailed job logs (in case cleanup failed)
            global $wpdb;
            $orphaned_logs = $wpdb->get_results(
                "SELECT option_name FROM {$wpdb->options} 
                 WHERE option_name LIKE 'mpsp_detailed_job_log_%' 
                 LIMIT 50"
            );
            
            foreach ($orphaned_logs as $option) {
                delete_option($option->option_name);
            }
            
            // Clean up any stuck job status older than 1 hour
            $current_job = get_option('mpsp_current_job', null);
            if ($current_job && !empty($current_job['started_at'])) {
                $started_time = strtotime($current_job['started_at']);
                if ($started_time && (time() - $started_time) > 3600) { // 1 hour
                    delete_option('mpsp_current_job');
                    delete_option('mpsp_current_job_id');
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
}

// Initialize the plugin
MP_Stripe_Price_Updater::get_instance();
