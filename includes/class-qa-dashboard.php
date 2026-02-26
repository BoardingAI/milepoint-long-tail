<?php
// includes/class-qa-dashboard.php

if (!defined('ABSPATH')) exit;

class MP_QA_Dashboard {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_dashboard_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_dashboard_menu() {
        add_submenu_page(
            'edit.php?post_type=milepoint_qa',
            'Q&A Stats',
            'Stats Dashboard',
            'edit_posts',
            'qa-stats-dashboard',
            [$this, 'render_dashboard']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'qa-stats-dashboard') === false) return;
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true);
    }

    public function render_dashboard() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mp_qa_status_log';

        // 1. DATA COLLECTION: Overview Counts
        $counts = wp_count_posts('milepoint_qa');

        // 2. DATA COLLECTION: Filters & Graph
        $days = isset($_GET['timespan']) ? intval($_GET['timespan']) : 7;
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

        $graph_results = $wpdb->get_results($wpdb->prepare("
            SELECT DATE(transition_date) as date, new_status, COUNT(*) as count
            FROM $table_name
            WHERE transition_date >= DATE_SUB(%s, INTERVAL %d DAY)
            AND transition_date <= %s
            GROUP BY DATE(transition_date), new_status
            ORDER BY date ASC
        ", $end_date, $days, $end_date . ' 23:59:59'));

        // Prepare Graph Data
        $labels = [];
        $data_points = ['publish' => [], 'draft' => [], 'trash' => []];
        for ($i = $days; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("$end_date -$i days"));
            $labels[] = date('M d', strtotime($d));
            $data_points['publish'][$d] = 0; $data_points['draft'][$d] = 0; $data_points['trash'][$d] = 0;
        }
        foreach ($graph_results as $row) {
            if (isset($data_points[$row->new_status])) $data_points[$row->new_status][$row->date] = (int)$row->count;
        }

        // 3. DATA COLLECTION: Recent Transactions
        $transactions = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table_name
            ORDER BY transition_date DESC
            LIMIT 15
        "));

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Reader Q&A Analytics</h1>
            <hr class="wp-header-end">

            <div style="display: flex; gap: 20px; margin-top: 20px;">

                <!-- LEFT SIDEBAR: Filters -->
                <div style="width: 260px; flex-shrink: 0;">
                    <div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; border-radius: 4px;">
                        <form method="GET" action="edit.php">
                            <input type="hidden" name="post_type" value="milepoint_qa">
                            <input type="hidden" name="page" value="qa-stats-dashboard">

                            <p style="margin-top:0;"><strong>Filter Reports</strong></p>

                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo $end_date; ?>" style="width: 100%; margin: 5px 0 15px 0;">

                            <label>Time Span</label>
                            <select name="timespan" style="width: 100%; margin: 5px 0 15px 0;">
                                <option value="7" <?php selected($days, 7); ?>>Last 7 days</option>
                                <option value="14" <?php selected($days, 14); ?>>Last 14 days</option>
                                <option value="30" <?php selected($days, 30); ?>>Last 30 days</option>
                            </select>

                            <button type="submit" class="button button-primary" style="width:100%;">Update Dashboard</button>
                        </form>
                    </div>
                </div>

                <!-- MAIN CONTENT AREA -->
                <div style="flex-grow: 1;">

                    <!-- SECTION 1: OVERVIEW CARDS -->
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px;">
                        <div style="background: #fff; border-left: 4px solid #2271b1; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); border-radius: 4px;">
                            <span style="color: #646970; font-weight: 600; text-transform: uppercase; font-size: 11px;">Total Published</span>
                            <div style="font-size: 28px; font-weight: bold; color: #2271b1; margin-top: 5px;"><?php echo number_format($counts->publish); ?></div>
                        </div>
                        <div style="background: #fff; border-left: 4px solid #dba617; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); border-radius: 4px;">
                            <span style="color: #646970; font-weight: 600; text-transform: uppercase; font-size: 11px;">Total Drafts</span>
                            <div style="font-size: 28px; font-weight: bold; color: #dba617; margin-top: 5px;"><?php echo number_format($counts->draft); ?></div>
                        </div>
                        <div style="background: #fff; border-left: 4px solid #d63638; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); border-radius: 4px;">
                            <span style="color: #646970; font-weight: 600; text-transform: uppercase; font-size: 11px;">In Trash</span>
                            <div style="font-size: 28px; font-weight: bold; color: #d63638; margin-top: 5px;"><?php echo number_format($counts->trash); ?></div>
                        </div>
                    </div>

                    <!-- SECTION 2: GRAPH -->
                    <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px; margin-bottom: 25px;">
                        <h3 style="margin-top:0;">Status Transitions over Time</h3>
                        <div style="height: 350px;">
                            <canvas id="qaChart"></canvas>
                        </div>
                    </div>

                    <!-- SECTION 3: TRANSACTIONS TABLE -->
                    <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; overflow: hidden;">
                        <div style="padding: 15px; border-bottom: 1px solid #ccd0d4; background: #f9f9f9;">
                            <h3 style="margin:0;">Recent Activity Log</h3>
                        </div>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="padding-left:15px;">Post Title</th>
                                    <th>Status Change</th>
                                    <th>Date & Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($transactions) : foreach ($transactions as $log) : ?>
                                    <tr>
                                        <td style="padding-left:15px;"><strong><?php echo get_the_title($log->post_id) ?: '<em>(Deleted Post)</em>'; ?></strong></td>
                                        <td>
                                            <span class="status-badge" style="background:#eee; padding:2px 6px; border-radius:3px; font-size:11px;"><?php echo $log->old_status; ?></span>
                                            &rarr;
                                            <span class="status-badge" style="background:#e7f3ff; color:#2271b1; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:600;"><?php echo $log->new_status; ?></span>
                                        </td>
                                        <td style="color: #646970;"><?php echo date('M d, Y @ H:i', strtotime($log->transition_date)); ?></td>
                                        <td><a href="post.php?post=<?php echo $log->post_id; ?>&action=edit" class="button button-small">View Post</a></td>
                                    </tr>
                                <?php endforeach; else : ?>
                                    <tr><td colspan="4" style="padding:20px; text-align:center;">No activity recorded yet. Start editing posts to see logs!</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('qaChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($labels); ?>,
                    datasets: [
                        { label: 'Published', data: <?php echo json_encode(array_values($data_points['publish'])); ?>, borderColor: '#2271b1', backgroundColor: '#2271b1', tension: 0.3, pointRadius: 4 },
                        { label: 'Drafted', data: <?php echo json_encode(array_values($data_points['draft'])); ?>, borderColor: '#dba617', backgroundColor: '#dba617', tension: 0.3, pointRadius: 4 },
                        { label: 'Trashed', data: <?php echo json_encode(array_values($data_points['trash'])); ?>, borderColor: '#d63638', backgroundColor: '#d63638', tension: 0.3, pointRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', align: 'end' } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: '#646970' }, grid: { color: '#f0f0f0' } },
                        x: { grid: { display: false }, ticks: { color: '#646970' } }
                    }
                }
            });
        });
        </script>
        <?php
    }
}