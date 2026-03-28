<?php
/**
 * Plugin Name: WP一键备份还原
 * Description: 高性能备份 + 稳定还原，支持大文件，实时进度，自动域名替换，确保数据完整性。
 * Version: 1.0.0
 * Author: BG Tech
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Backup_Restore_Active {

    const BACKUP_DIR = 'wpbkres';
    const BACKUP_EXT = '.bgbk';
    const FILE_BATCH = 500;
    const DB_BATCH_ROWS = 10000;
    const DEFAULT_RESTORE_BATCH = 500;
    const STATE_UPDATE_INTERVAL = 1;

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_requests' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // 备份 AJAX
        add_action( 'wp_ajax_wp_backup_start', array( $this, 'ajax_backup_start' ) );
        add_action( 'wp_ajax_wp_backup_status', array( $this, 'ajax_backup_status' ) );
        add_action( 'wp_ajax_wp_stop_backup', array( $this, 'ajax_stop_backup' ) );

        // 还原 AJAX
        add_action( 'wp_ajax_wp_restore_start', array( $this, 'ajax_restore_start' ) );
        add_action( 'wp_ajax_wp_restore_step', array( $this, 'ajax_restore_step' ) );
        add_action( 'wp_ajax_wp_restore_status', array( $this, 'ajax_restore_status' ) );
        add_action( 'wp_ajax_wp_stop_restore', array( $this, 'ajax_stop_restore' ) );

        // 备份信息
        add_action( 'wp_ajax_wp_get_backup_info', array( $this, 'ajax_get_backup_info' ) );

        // 后台任务钩子（备份）
        add_action( 'wp_backup_init', array( $this, 'do_backup_init' ) );
        add_action( 'wp_backup_files', array( $this, 'do_backup_files' ) );
        add_action( 'wp_backup_database', array( $this, 'do_backup_database' ) );
        add_action( 'wp_backup_finalize', array( $this, 'do_backup_finalize' ) );
    }

    public function activate_plugin() {
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/' . self::BACKUP_DIR;
        if ( ! file_exists( $backup_path ) ) {
            wp_mkdir_p( $backup_path );
        }
        $htaccess = $backup_path . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n" );
        }
        $index = $backup_path . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // silence is golden' );
        }
    }

    private function log( $msg ) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/' . self::BACKUP_DIR . '/restore.log';
        file_put_contents( $log_file, date( 'Y-m-d H:i:s' ) . " $msg\n", FILE_APPEND );
    }

    public function enqueue_scripts( $hook ) {
        if ( $hook !== 'tools_page_wp-backup-restore' ) return;
        wp_enqueue_script( 'jquery' );
        wp_localize_script( 'jquery', 'wp_ajax', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce_backup_start'   => wp_create_nonce( 'wp_backup_start' ),
            'nonce_backup_stop'    => wp_create_nonce( 'wp_stop_backup' ),
            'nonce_restore_start'  => wp_create_nonce( 'wp_restore_start' ),
            'nonce_restore_stop'   => wp_create_nonce( 'wp_stop_restore' ),
            'nonce_info'           => wp_create_nonce( 'wp_get_backup_info' ),
        ) );
    }

    public function add_admin_menu() {
        add_management_page(
            'WP一键备份还原',
            'WP备份还原',
            'manage_options',
            'wp-backup-restore',
            array( $this, 'admin_page' )
        );
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '权限不足' );
        $file_batch = get_option( 'wp_backup_file_batch', self::FILE_BATCH );
        $db_batch = get_option( 'wp_backup_db_batch', self::DB_BATCH_ROWS );
        $restore_batch = get_option( 'wp_restore_batch', self::DEFAULT_RESTORE_BATCH );
        ?>
        <div class="wrap">
            <h1>WP一键备份还原</h1>
            <p>高性能备份 + 稳定还原，支持大文件，实时进度，自动域名替换，确保数据完整性。</p>
            <table class="form-table">
                 …
<th>每批文件数（备份）</th><td><input type="number" id="file_batch" value="<?php echo esc_attr( $file_batch ); ?>" min="10" max="2000" step="10"> 建议 100~500</td></tr>
                 …
<th>每批数据库行数（备份）</th><td><input type="number" id="db_batch" value="<?php echo esc_attr( $db_batch ); ?>" min="1000" max="50000" step="1000"> 建议 5000~20000</td></tr>
                 …
<th>还原每批条目数</th><td><input type="number" id="restore_batch" value="<?php echo esc_attr( $restore_batch ); ?>" min="1" max="5000" step="100"> 建议 500~2000</td></tr>
             </table>
            <p><button id="wp-start-backup" class="button button-primary">开始备份</button>
               <button id="wp-start-restore" class="button button-primary" style="margin-left:10px;">开始还原</button></p>
            <hr>
            <h2>现有备份文件</h2>
            <select id="backup_file_list" style="width:300px;">
                <option value="">-- 请选择 --</option>
                <?php foreach ( $this->get_backup_list() as $file ) : ?>
                    <option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( basename( $file ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <div id="domain-info" style="margin: 10px 0;"></div>
        </div>

        <div id="wp-modal" class="bg-modal-overlay" style="display:none;">
            <div class="bg-modal-content">
                <h3 id="wp-modal-title">正在备份...</h3>
                <div class="bg-progress-bar"><div id="wp-progress-fill" class="bg-progress-fill">0%</div></div>
                <div id="wp-status-text" class="bg-status-text">准备中...</div>
                <button id="wp-modal-stop" class="bg-stop-button">停止任务</button>
            </div>
        </div>

        <style>
            .bg-modal-overlay {
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.6); z-index: 99999;
                display: flex; align-items: center; justify-content: center;
            }
            .bg-modal-content {
                background: #fff; padding: 20px 30px; border-radius: 8px;
                min-width: 300px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            }
            .bg-modal-content h3 { margin-top: 0; color: #0073aa; }
            .bg-progress-bar { background: #f1f1f1; height: 20px; width: 100%; border-radius: 10px; overflow: hidden; margin: 15px 0; }
            .bg-progress-fill { background: #0073aa; width: 0%; height: 100%; transition: width 0.3s; color: white; line-height: 20px; text-align: center; font-size: 12px; }
            .bg-status-text { font-size: 14px; margin: 10px 0; }
            .bg-stop-button { background: #dc3232; color: #fff; border: none; padding: 5px 15px; border-radius: 3px; cursor: pointer; margin-top: 10px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var modal = $('#wp-modal');
            var modalTitle = $('#wp-modal-title');
            var progressFill = $('#wp-progress-fill');
            var statusText = $('#wp-status-text');
            var stopBtn = $('#wp-modal-stop');
            var backupInterval = null;
            var restoreInterval = null;
            var currentTaskId = null;
            var stepRetryCount = 0;
            var maxStepRetries = 5;

            function showModal(title, message, percent) {
                modalTitle.text(title);
                statusText.text(message);
                if (percent !== undefined) progressFill.css('width', percent+'%').text(percent+'%');
                modal.show();
            }

            function hideModal() {
                modal.hide();
                if (backupInterval) clearInterval(backupInterval);
                if (restoreInterval) clearInterval(restoreInterval);
            }

            function saveBatchSettings() {
                var fileBatch = parseInt($('#file_batch').val()) || 500;
                var dbBatch = parseInt($('#db_batch').val()) || 10000;
                var restoreBatch = parseInt($('#restore_batch').val()) || 500;
                $.post(ajaxurl, {
                    action: 'wp_save_batch_settings',
                    file_batch: fileBatch,
                    db_batch: dbBatch,
                    restore_batch: restoreBatch,
                    nonce: '<?php echo wp_create_nonce( 'wp_save_batch_settings' ); ?>'
                });
            }

            function startBackup() {
                saveBatchSettings();
                var fileBatch = parseInt($('#file_batch').val()) || 500;
                var dbBatch = parseInt($('#db_batch').val()) || 10000;
                showModal('正在备份', '正在启动...', 0);
                $.post(wp_ajax.ajaxurl, {
                    action: 'wp_backup_start',
                    nonce: wp_ajax.nonce_backup_start,
                    file_batch: fileBatch,
                    db_batch: dbBatch
                })
                .done(function(response) {
                    if (response.success) {
                        currentTaskId = response.data.task_id;
                        backupInterval = setInterval(function() {
                            $.post(wp_ajax.ajaxurl, { action: 'wp_backup_status' }, function(status) {
                                if (status.success) {
                                    progressFill.css('width', status.data.percent+'%').text(status.data.percent+'%');
                                    statusText.text(status.data.message);
                                    if (status.data.done) {
                                        clearInterval(backupInterval);
                                        var filename = status.data.filename || '未知文件名';
                                        statusText.text('备份完成！文件：' + filename);
                                        setTimeout(function() { hideModal(); location.reload(); }, 3000);
                                    } else if (status.data.error) {
                                        clearInterval(backupInterval);
                                        statusText.text('备份出错：' + status.data.message);
                                        stopBtn.show();
                                    }
                                } else {
                                    statusText.text('状态错误：' + (status.data ? status.data.message : '未知'));
                                }
                            });
                        }, 2000);
                    } else {
                        statusText.text('启动失败：' + (response.data ? response.data.message : '未知'));
                        stopBtn.show();
                    }
                })
                .fail(function() {
                    statusText.text('网络错误，启动失败');
                    stopBtn.show();
                });
            }

            function startRestore() {
                saveBatchSettings();
                var file = $('#backup_file_list').val();
                if (!file) {
                    statusText.text('请先选择一个备份文件');
                    stopBtn.show();
                    return;
                }
                var restoreBatch = parseInt($('#restore_batch').val()) || 500;
                showModal('正在还原', '正在启动...', 0);
                $.post(wp_ajax.ajaxurl, {
                    action: 'wp_restore_start',
                    backup_file: file,
                    restore_batch: restoreBatch,
                    nonce: wp_ajax.nonce_restore_start
                }, function(response) {
                    if (response.success) {
                        currentTaskId = response.data.task_id;
                        restoreInterval = setInterval(function() {
                            $.ajax({
                                url: wp_ajax.ajaxurl,
                                method: 'POST',
                                data: {
                                    action: 'wp_restore_status',
                                    task_id: currentTaskId,
                                    nonce: wp_ajax.nonce_restore_start
                                },
                                timeout: 60000,
                                success: function(status) {
                                    if (status.success) {
                                        progressFill.css('width', status.data.percent+'%').text(status.data.percent+'%');
                                        statusText.text(status.data.message);
                                        if (status.data.done) {
                                            clearInterval(restoreInterval);
                                            statusText.text('还原成功！');
                                            setTimeout(function() { hideModal(); location.reload(); }, 3000);
                                        } else if (status.data.error) {
                                            clearInterval(restoreInterval);
                                            statusText.text('还原出错：' + status.data.message);
                                            stopBtn.show();
                                        } else {
                                            doRestoreStep();
                                        }
                                    } else {
                                        statusText.text('状态获取失败：' + (status.data ? status.data.message : '未知'));
                                    }
                                },
                                error: function(xhr, status, error) {
                                    console.log('状态请求失败', status);
                                }
                            });
                        }, 3000);
                    } else {
                        statusText.text('启动还原失败：' + (response.data ? response.data.message : '未知'));
                        stopBtn.show();
                    }
                }).fail(function() {
                    statusText.text('网络错误，启动失败');
                    stopBtn.show();
                });
            }

            function doRestoreStep() {
                $.ajax({
                    url: wp_ajax.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'wp_restore_step',
                        task_id: currentTaskId,
                        nonce: wp_ajax.nonce_restore_start
                    },
                    timeout: 120000,
                    success: function(response) {
                        stepRetryCount = 0;
                    },
                    error: function(xhr, status, error) {
                        console.log('步骤请求失败', status);
                        stepRetryCount++;
                        if (stepRetryCount <= maxStepRetries) {
                            setTimeout(doRestoreStep, 5000);
                        } else {
                            statusText.text('步骤重试失败，还原可能中断');
                        }
                    }
                });
            }

            function stopTask() {
                if (currentTaskId) {
                    if (backupInterval) {
                        $.post(wp_ajax.ajaxurl, { action: 'wp_stop_backup', nonce: wp_ajax.nonce_backup_stop }, function() {
                            hideModal();
                            location.reload();
                        });
                    } else if (restoreInterval) {
                        $.post(wp_ajax.ajaxurl, { action: 'wp_stop_restore', task_id: currentTaskId, nonce: wp_ajax.nonce_restore_stop }, function() {
                            hideModal();
                            location.reload();
                        });
                    }
                } else {
                    hideModal();
                }
            }

            $('#wp-start-backup').click(startBackup);
            $('#wp-start-restore').click(startRestore);
            $('#wp-modal-stop').click(stopTask);

            $('#backup_file_list').change(function() {
                var file = $(this).val();
                if (!file) {
                    $('#domain-info').html('');
                    return;
                }
                $.post(wp_ajax.ajaxurl, { action: 'wp_get_backup_info', backup_file: file, nonce: wp_ajax.nonce_info }, function(response) {
                    if (response.success && response.data.old_domain) {
                        var currentDomain = '<?php echo esc_js( home_url() ); ?>';
                        var oldDomain = response.data.old_domain;
                        var infoHtml = '<p>备份中的原域名：<strong>' + oldDomain + '</strong></p>';
                        if (oldDomain !== currentDomain) {
                            infoHtml += '<p style="color: #dc3232;">⚠️ 检测到域名不一致，还原时将自动替换为当前域名：<strong>' + currentDomain + '</strong></p>';
                        } else {
                            infoHtml += '<p>域名与当前站点一致，无需替换。</p>';
                        }
                        $('#domain-info').html(infoHtml);
                    } else if (response.data.message) {
                        $('#domain-info').html('<p>' + response.data.message + '</p>');
                    } else {
                        $('#domain-info').html('<p>无法获取备份信息。</p>');
                    }
                }).fail(function() {
                    $('#domain-info').html('<p>获取备份信息失败。</p>');
                });
            });
        });
        </script>
        <?php
    }

    private function get_backup_list() {
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/' . self::BACKUP_DIR;
        return glob( $backup_dir . '/*' . self::BACKUP_EXT ) ?: array();
    }

    public function handle_requests() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( isset( $_POST['action'] ) && $_POST['action'] === 'wp_save_batch_settings' && check_ajax_referer( 'wp_save_batch_settings', 'nonce', false ) ) {
            if ( isset( $_POST['file_batch'] ) ) update_option( 'wp_backup_file_batch', intval( $_POST['file_batch'] ) );
            if ( isset( $_POST['db_batch'] ) ) update_option( 'wp_backup_db_batch', intval( $_POST['db_batch'] ) );
            if ( isset( $_POST['restore_batch'] ) ) update_option( 'wp_restore_batch', intval( $_POST['restore_batch'] ) );
            wp_send_json_success();
        }
    }

    // ==================== 备份任务 ====================
    public function ajax_backup_start() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        if ( ! check_ajax_referer( 'wp_backup_start', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
        $file_batch = isset( $_POST['file_batch'] ) ? intval( $_POST['file_batch'] ) : get_option( 'wp_backup_file_batch', self::FILE_BATCH );
        $db_batch = isset( $_POST['db_batch'] ) ? intval( $_POST['db_batch'] ) : get_option( 'wp_backup_db_batch', self::DB_BATCH_ROWS );
        $task_id = uniqid( 'backup_', true );
        set_transient( 'wp_current_backup_task', $task_id, HOUR_IN_SECONDS );
        $state = array(
            'status' => 'init',
            'percent' => 0,
            'message' => '正在初始化...',
            'task_id' => $task_id,
            'file_batch' => $file_batch,
            'db_batch' => $db_batch,
        );
        update_option( 'wp_backup_state_' . $task_id, $state );
        wp_schedule_single_event( time(), 'wp_backup_init', array( $task_id ) );
        wp_send_json_success( array( 'task_id' => $task_id ) );
    }

    public function ajax_backup_status() {
        $task_id = get_transient( 'wp_current_backup_task' );
        if ( ! $task_id ) {
            $last_result = get_option( 'wp_last_backup_result' );
            if ( $last_result ) {
                wp_send_json_success( array(
                    'percent' => 100,
                    'message' => '备份完成！',
                    'done' => true,
                    'filename' => $last_result,
                ) );
            }
            wp_send_json_success( array( 'percent' => 0, 'message' => '无进行中的备份', 'done' => true ) );
        }
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( ! $state ) {
            wp_send_json_success( array( 'percent' => 0, 'message' => '备份已完成', 'done' => true ) );
        }
        wp_send_json_success( array(
            'percent' => $state['percent'],
            'message' => $state['message'],
            'done' => $state['status'] === 'done',
            'error' => $state['status'] === 'error',
            'filename' => isset( $state['final_name'] ) ? $state['final_name'] : '',
        ) );
    }

    public function ajax_stop_backup() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        if ( ! check_ajax_referer( 'wp_stop_backup', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
        $task_id = get_transient( 'wp_current_backup_task' );
        if ( $task_id ) {
            $this->clean_backup_state( $task_id );
            delete_transient( 'wp_current_backup_task' );
        }
        wp_send_json_success();
    }

    public function do_backup_init( $task_id ) {
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( ! $state || $state['status'] !== 'init' ) return;

        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/' . self::BACKUP_DIR;
        $backup_name = 'backup_' . date( 'Ymd_His' ) . self::BACKUP_EXT;
        $temp_dir = $backup_dir . '/temp_backup_' . $task_id;
        if ( ! is_dir( $temp_dir ) && ! wp_mkdir_p( $temp_dir ) ) {
            $this->mark_backup_error( $task_id, '无法创建临时目录' );
            return;
        }
        $temp_zip = $temp_dir . '/' . $backup_name . '.part';

		$exclude_plugins = array( plugin_dir_path( __FILE__ ) );
		$file_list = $this->get_file_list( ABSPATH, $backup_dir, $exclude_plugins );
        $total_files = count( $file_list );
        $list_file = $temp_dir . '/filelist.txt';
        file_put_contents( $list_file, implode( "\n", $file_list ) );
        $site_info = array( 'siteurl' => home_url(), 'home' => home_url() );
        $info_file = $temp_dir . '/siteinfo.json';
        file_put_contents( $info_file, json_encode( $site_info ) );

        $state['status'] = 'files';
        $state['percent'] = 0;
        $state['message'] = sprintf( '已扫描到 %d 个文件，开始打包...', $total_files );
        $state['backup_name'] = $backup_name;
        $state['temp_zip'] = $temp_zip;
        $state['temp_dir'] = $temp_dir;
        $state['total_files'] = $total_files;
        $state['processed_files'] = 0;
        $state['list_file'] = $list_file;
        $state['info_file'] = $info_file;
        $state['db_sql_file'] = $temp_dir . '/database.sql';
        $state['db_state'] = null;
        update_option( 'wp_backup_state_' . $task_id, $state );
        wp_schedule_single_event( time(), 'wp_backup_files', array( $task_id ) );
    }

    public function do_backup_files( $task_id ) {
        set_time_limit( 0 );
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( ! $state || $state['status'] !== 'files' ) return;

        $zip = new ZipArchive();
        if ( $zip->open( $state['temp_zip'], ZipArchive::CREATE ) !== true ) {
            $this->mark_backup_error( $task_id, '无法创建备份文件' );
            return;
        }
        $list_file = $state['list_file'];
        $total = $state['total_files'];
        $processed = $state['processed_files'];
        $batch = $state['file_batch'];
        $start = $processed;
        $end = min( $start + $batch, $total );

        $handle = fopen( $list_file, 'r' );
        if ( $handle ) {
            $line_num = 0;
            while ( ( $line = fgets( $handle ) ) !== false ) {
                $line = trim( $line );
                if ( $line_num >= $start && $line_num < $end ) {
                    if ( is_file( $line ) && is_readable( $line ) ) {
                        $relative = str_replace( ABSPATH, '', $line );
                        $zip->addFile( $line, $relative );
                    }
                }
                $line_num++;
                if ( $line_num >= $end ) break;
            }
            fclose( $handle );
        }
        $zip->close();

        $new_processed = $end;
        $state['processed_files'] = $new_processed;
        $percent = round( $new_processed / $total * 100 );
        $state['percent'] = $percent;
        $state['message'] = sprintf( '正在打包文件 (%d/%d)', $new_processed, $total );
        update_option( 'wp_backup_state_' . $task_id, $state );

        if ( $new_processed >= $total ) {
            $state['status'] = 'database';
            $state['message'] = '文件打包完成，开始导出数据库...';
            $state['db_state'] = $this->init_db_export_state();
            update_option( 'wp_backup_state_' . $task_id, $state );
            wp_schedule_single_event( time(), 'wp_backup_database', array( $task_id ) );
        } else {
            wp_schedule_single_event( time(), 'wp_backup_files', array( $task_id ) );
        }
    }

    private function init_db_export_state() {
        global $wpdb;
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        $table_list = array();
        $total_rows = 0;
        foreach ( $tables as $table ) {
            $table_name = $table[0];
            $row_count = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );
            $table_list[] = array( 'name' => $table_name, 'rows' => intval( $row_count ) );
            $total_rows += intval( $row_count );
        }
        return array(
            'tables' => $table_list,
            'offset' => 0,
            'total_rows' => $total_rows,
            'table_index' => 0,
            'processed_rows' => 0,
        );
    }

    public function do_backup_database( $task_id ) {
        set_time_limit( 0 );
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( ! $state || $state['status'] !== 'database' ) return;

        $db_state = &$state['db_state'];
        $sql_file = $state['db_sql_file'];
        $batch_rows = $state['db_batch'];

        if ( ! file_exists( $sql_file ) ) {
            $header = "-- WordPress Database Backup\n-- Date: " . date( 'Y-m-d H:i:s' ) . "\n";
            $header .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\n\n";
            file_put_contents( $sql_file, $header );
        }

        $tables = $db_state['tables'];
        $table_idx = $db_state['table_index'];
        $total_rows_all = $db_state['total_rows'];

        if ( $table_idx >= count( $tables ) ) {
            file_put_contents( $sql_file, "SET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND );
            $state['status'] = 'finalize';
            $state['percent'] = 95;
            $state['message'] = '数据库导出完成，正在生成最终备份包...';
            update_option( 'wp_backup_state_' . $task_id, $state );
            wp_schedule_single_event( time(), 'wp_backup_finalize', array( $task_id ) );
            return;
        }

        $current_table = $tables[ $table_idx ]['name'];
        $table_rows_total = $tables[ $table_idx ]['rows'];
        $offset = $db_state['offset'];

        if ( $offset == 0 ) {
            $create_sql = $this->get_table_create_sql( $current_table );
            file_put_contents( $sql_file, "\nDROP TABLE IF EXISTS `$current_table`;\n$create_sql;\n\n", FILE_APPEND );
        }

        $exported = $this->export_table_chunked( $current_table, $offset, $batch_rows, $sql_file );
        if ( $exported === false ) {
            $this->mark_backup_error( $task_id, "导出表 {$current_table} 失败" );
            return;
        }

        $new_offset = $offset + $exported;
        $db_state['offset'] = $new_offset;
        $db_state['processed_rows'] += $exported;

        if ( $new_offset >= $table_rows_total ) {
            $db_state['table_index']++;
            $db_state['offset'] = 0;
        }

        $processed_rows = $db_state['processed_rows'];
        $percent = $total_rows_all ? round( $processed_rows / $total_rows_all * 100 ) : 0;
        $percent = min( $percent, 100 );
        $state['percent'] = $percent;
        $state['message'] = sprintf( '正在导出数据库 (%d/%d 行)', $processed_rows, $total_rows_all );
        update_option( 'wp_backup_state_' . $task_id, $state );

        wp_schedule_single_event( time(), 'wp_backup_database', array( $task_id ) );
    }

    private function export_table_chunked( $table_name, $offset, $limit, $sql_file ) {
        global $wpdb;
        $mysqli = $wpdb->dbh;
        if ( ! $mysqli instanceof mysqli ) {
            return $this->export_table_chunked_fallback( $table_name, $offset, $limit, $sql_file );
        }
        $query = "SELECT * FROM `$table_name` LIMIT $offset, $limit";
        $result = $mysqli->query( $query, MYSQLI_USE_RESULT );
        if ( ! $result ) return false;
        $fields = $result->fetch_fields();
        $field_names = array_map( function( $field ) { return "`$field->name`"; }, $fields );
        $insert_prefix = "INSERT INTO `$table_name` (" . implode( ', ', $field_names ) . ") VALUES\n";
        $rows_data = array();
        $count = 0;
        while ( $row = $result->fetch_row() ) {
            $escaped = array();
            foreach ( $row as $value ) {
                if ( $value === null ) {
                    $escaped[] = 'NULL';
                } else {
                    $escaped[] = "'" . $mysqli->real_escape_string( $value ) . "'";
                }
            }
            $rows_data[] = "(" . implode( ', ', $escaped ) . ")";
            $count++;
            if ( count( $rows_data ) >= 500 ) {
                $sql = $insert_prefix . implode( ",\n", $rows_data ) . ";\n";
                file_put_contents( $sql_file, $sql, FILE_APPEND );
                $rows_data = array();
            }
        }
        if ( ! empty( $rows_data ) ) {
            $sql = $insert_prefix . implode( ",\n", $rows_data ) . ";\n";
            file_put_contents( $sql_file, $sql, FILE_APPEND );
        }
        $result->free();
        return $count;
    }

    private function export_table_chunked_fallback( $table_name, $offset, $limit, $sql_file ) {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM `$table_name` LIMIT $offset, $limit", ARRAY_A );
        if ( empty( $rows ) ) return 0;
        $columns = array_keys( $rows[0] );
        $insert_prefix = "INSERT INTO `$table_name` (`" . implode( "`, `", $columns ) . "`) VALUES\n";
        $values = array();
        foreach ( $rows as $row ) {
            $escaped = array();
            foreach ( $row as $value ) {
                if ( $value === null ) {
                    $escaped[] = 'NULL';
                } else {
                    $escaped[] = "'" . $wpdb->_real_escape( $value ) . "'";
                }
            }
            $values[] = "(" . implode( ', ', $escaped ) . ")";
        }
        $sql = $insert_prefix . implode( ",\n", $values ) . ";\n";
        file_put_contents( $sql_file, $sql, FILE_APPEND );
        return count( $rows );
    }

    private function get_table_create_sql( $table_name ) {
        global $wpdb;
        $row = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_N );
        return $row ? $row[1] : '';
    }

    public function do_backup_finalize( $task_id ) {
        set_time_limit( 0 );
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( ! $state || $state['status'] !== 'finalize' ) return;

        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/' . self::BACKUP_DIR;

        $zip = new ZipArchive();
        if ( $zip->open( $state['temp_zip'], ZipArchive::CREATE ) !== true ) {
            $this->mark_backup_error( $task_id, '无法打开备份文件' );
            return;
        }
        $sql_file = $state['db_sql_file'];
        if ( file_exists( $sql_file ) ) {
            $zip->addFile( $sql_file, 'database.sql' );
        } else {
            $this->mark_backup_error( $task_id, '数据库SQL文件丢失' );
            return;
        }
        $info_file = $state['info_file'];
        if ( file_exists( $info_file ) ) {
            $zip->addFile( $info_file, 'siteinfo.json' );
        }
        $zip->close();

        $final_path = $backup_dir . '/' . $state['backup_name'];
        if ( ! rename( $state['temp_zip'], $final_path ) ) {
            $this->mark_backup_error( $task_id, '无法保存备份文件' );
            return;
        }

        $this->remove_directory( $state['temp_dir'] );

        $state['status'] = 'done';
        $state['percent'] = 100;
        $state['message'] = '备份完成！';
        $state['final_name'] = $state['backup_name'];
        update_option( 'wp_backup_state_' . $task_id, $state );
        update_option( 'wp_last_backup_result', $state['backup_name'] );
        delete_transient( 'wp_current_backup_task' );
    }

    private function mark_backup_error( $task_id, $message ) {
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( $state ) {
            $state['status'] = 'error';
            $state['message'] = $message;
            update_option( 'wp_backup_state_' . $task_id, $state );
        }
        delete_transient( 'wp_current_backup_task' );
        error_log( "[WP Backup] ERROR: $message" );
    }

    private function clean_backup_state( $task_id ) {
        $state = get_option( 'wp_backup_state_' . $task_id );
        if ( $state && isset( $state['temp_dir'] ) && is_dir( $state['temp_dir'] ) ) {
            $this->remove_directory( $state['temp_dir'] );
        }
        delete_option( 'wp_backup_state_' . $task_id );
    }

    // ==================== 还原主动触发（最终稳定版） ====================
    public function ajax_restore_start() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        if ( ! check_ajax_referer( 'wp_restore_start', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
        $backup_file = isset( $_POST['backup_file'] ) ? sanitize_text_field( $_POST['backup_file'] ) : '';
        $restore_batch = isset( $_POST['restore_batch'] ) ? intval( $_POST['restore_batch'] ) : get_option( 'wp_restore_batch', self::DEFAULT_RESTORE_BATCH );
        if ( empty( $backup_file ) ) {
            wp_send_json_error( array( 'message' => '未选择备份文件' ) );
        }
        $old_task_id = get_transient( 'wp_current_restore_task' );
        if ( $old_task_id ) {
            $this->clean_restore_state( $old_task_id );
            delete_transient( 'wp_current_restore_task' );
        }
        $task_id = uniqid( 'restore_', true );
        set_transient( 'wp_current_restore_task', $task_id, HOUR_IN_SECONDS );
        $state = array(
            'task_id' => $task_id,
            'backup_file' => $backup_file,
            'restore_batch' => $restore_batch,
            'status' => 'init',
            'percent' => 0,
            'message' => '正在分析备份文件...',
            'processed' => 0,
            'total_entries' => 0,
            'entries' => array(),
            'current_index' => 0,
            'siteinfo' => null,
            'last_update' => microtime(true),
            'processed_since_update' => 0,
        );
        update_option( 'wp_restore_state_' . $task_id, $state );
        $this->do_restore_init( $task_id );
        wp_send_json_success( array( 'task_id' => $task_id ) );
    }

    public function ajax_restore_step() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        if ( ! check_ajax_referer( 'wp_restore_start', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( $_POST['task_id'] ) : '';
        if ( empty( $task_id ) ) {
            wp_send_json_error( array( 'message' => '缺少任务ID' ) );
        }
        $this->do_restore_process( $task_id );
        wp_send_json_success();
    }

    public function ajax_restore_status() {
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( $_POST['task_id'] ) : '';
        if ( empty( $task_id ) ) {
            $task_id = get_transient( 'wp_current_restore_task' );
        }
        if ( ! $task_id ) {
            wp_send_json_success( array( 'percent' => 0, 'message' => '无进行中的还原任务', 'done' => true ) );
        }
        $state = get_option( 'wp_restore_state_' . $task_id );
        if ( ! $state ) {
            wp_send_json_success( array( 'percent' => 0, 'message' => '任务已完成', 'done' => true ) );
        }
        wp_send_json_success( array(
            'percent' => $state['percent'],
            'message' => $state['message'],
            'done' => $state['status'] === 'done',
            'error' => $state['status'] === 'error',
        ) );
    }

    public function ajax_stop_restore() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        if ( ! check_ajax_referer( 'wp_stop_restore', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
        $task_id = isset( $_POST['task_id'] ) ? sanitize_text_field( $_POST['task_id'] ) : '';
        if ( empty( $task_id ) ) {
            $task_id = get_transient( 'wp_current_restore_task' );
        }
        if ( $task_id ) {
            $this->clean_restore_state( $task_id );
            delete_transient( 'wp_current_restore_task' );
        }
        wp_send_json_success();
    }

    public function do_restore_init( $task_id ) {
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $state = get_option( 'wp_restore_state_' . $task_id );
        if ( ! $state || $state['status'] !== 'init' ) return;

        $file = $state['backup_file'];
        $handle = fopen( $file, 'rb' );
        if ( ! $handle ) {
            $this->mark_restore_error( $task_id, '无法打开备份文件' );
            return;
        }

        $magic = fread( $handle, 4 );
        fclose( $handle );

        // ZIP 格式处理（带进度）
        if ( $magic === pack( 'N', 0x504b0304 ) || bin2hex($magic) === '504b0304' ) {
            $this->log( "检测到 ZIP 备份，开始解压" );

            $state['message'] = '正在解压文件...';
            $state['percent'] = 20;
            update_option( 'wp_restore_state_' . $task_id, $state );

            $zip = new ZipArchive();
            if ( $zip->open( $file ) !== true ) {
                $this->mark_restore_error( $task_id, '无法打开 ZIP 备份文件' );
                return;
            }
            if ( ! $zip->extractTo( ABSPATH ) ) {
                $zip->close();
                $this->mark_restore_error( $task_id, '解压备份文件到网站根目录失败' );
                return;
            }
            $zip->close();

            $state['message'] = '正在导入数据库...';
            $state['percent'] = 50;
            update_option( 'wp_restore_state_' . $task_id, $state );

            $sql_file = ABSPATH . 'database.sql';
            if ( file_exists( $sql_file ) ) {
                $this->log( "开始导入数据库 SQL" );
                $this->import_sql_file_with_progress( $task_id, $sql_file );
                @unlink( $sql_file );
            }

            $siteinfo_file = ABSPATH . 'siteinfo.json';
            if ( file_exists( $siteinfo_file ) ) {
                $info = json_decode( file_get_contents( $siteinfo_file ), true );
                if ( isset( $info['siteurl'] ) && $info['siteurl'] !== home_url() ) {
                    $state['message'] = '正在替换域名...';
                    $state['percent'] = 96;
                    update_option( 'wp_restore_state_' . $task_id, $state );
                    $this->replace_domain_in_db( $info['siteurl'], home_url() );
                }
                @unlink( $siteinfo_file );
            }

            $state['status'] = 'done';
            $state['percent'] = 100;
            $state['message'] = '还原完成！';
            update_option( 'wp_restore_state_' . $task_id, $state );
            delete_transient( 'wp_current_restore_task' );
            $this->log( "ZIP 还原完成" );
            return;
        }

        // 自定义格式处理（与原逻辑相同）
        if ( $magic !== pack( 'N', 0x4247424B ) ) {
            $this->mark_restore_error( $task_id, '无效的备份文件格式，实际魔数: ' . bin2hex($magic) );
            return;
        }

        $upload_dir = wp_upload_dir();
        $temp_meta = $upload_dir['basedir'] . '/' . self::BACKUP_DIR . '/meta_' . $task_id . '.txt';
        $handle = fopen( $file, 'rb' );
        fseek( $handle, 5 );
        $total = 0;
        $meta_handle = fopen( $temp_meta, 'w' );
        while ( ! feof( $handle ) ) {
            $pos = ftell( $handle );
            $name_len_data = fread( $handle, 4 );
            if ( strlen( $name_len_data ) < 4 ) break;
            $name_len = unpack( 'N', $name_len_data )[1];
            if ( $name_len == 0xFFFFFFFF ) break;
            $name = fread( $handle, $name_len );
            $size_data = fread( $handle, 4 );
            if ( strlen( $size_data ) < 4 ) break;
            $size = unpack( 'N', $size_data )[1];
            fwrite( $meta_handle, $pos . '|' . $name . '|' . $size . "\n" );
            $total++;
            fseek( $handle, $size, SEEK_CUR );
        }
        fclose( $handle );
        fclose( $meta_handle );

        $state['meta_file'] = $temp_meta;
        $state['total_entries'] = $total;
        $state['status'] = 'processing';
        $state['message'] = '开始还原...';
        $state['current_index'] = 0;
        $state['percent'] = 0;
        $state['temp_dir'] = null;
        $state['backup_file'] = $file;
        $state['last_update'] = microtime(true);
        $state['processed_since_update'] = 0;
        update_option( 'wp_restore_state_' . $task_id, $state );
        $this->log( "自定义备份分析完成，共 {$total} 个条目" );
        $this->log( "当前批次大小: {$state['restore_batch']}" );
        $this->do_restore_process( $task_id );
    }

    public function do_restore_process( $task_id ) {
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $state = get_option( 'wp_restore_state_' . $task_id );
        if ( ! $state || $state['status'] !== 'processing' ) return;

        $batch = $state['restore_batch'];
        $this->log( "处理批次: 当前索引 {$state['current_index']}, 批次大小 {$batch}, 总条目 {$state['total_entries']}" );

        $processed = 0;
        $start_time = microtime( true );
        $max_time = 120;
        $last_update = $state['last_update'];
        $processed_since_update = $state['processed_since_update'];
        $state_updated = false;

        $meta_file = $state['meta_file'];
        $handle = fopen( $state['backup_file'], 'rb' );
        if ( ! $handle ) {
            $this->mark_restore_error( $task_id, '无法打开备份文件' );
            return;
        }

        $lines = file( $meta_file );
        for ( $i = $state['current_index']; $i < $state['total_entries'] && $processed < $batch; $i++ ) {
            $line = trim( $lines[ $i ] );
            list( $pos, $name, $size ) = explode( '|', $line );
            fseek( $handle, $pos + 4 + strlen( $name ) + 4, SEEK_SET );
            $content = fread( $handle, $size );
            if ( strpos( $name, 'db:' ) === 0 ) {
                $this->execute_sql( $content );
                $msg_prefix = '正在导入数据库';
            } elseif ( $name === 'siteinfo.json' ) {
                $state['siteinfo'] = json_decode( $content, true );
                $msg_prefix = '正在处理站点信息';
            } else {
                $dest = ABSPATH . $name;
                $dir = dirname( $dest );
                if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
                file_put_contents( $dest, $content );
                $msg_prefix = '正在还原文件';
            }
            $processed++;
            $processed_since_update++;
            $state['current_index'] = $i + 1;
            $state['percent'] = round( ( $state['current_index'] / $state['total_entries'] ) * 100 );
            $state['message'] = sprintf( '%s (%d/%d)', $msg_prefix, $state['current_index'], $state['total_entries'] );

            $now = microtime(true);
            if ( $processed_since_update >= 200 || ( $now - $last_update ) > self::STATE_UPDATE_INTERVAL ) {
                $state['last_update'] = $now;
                $state['processed_since_update'] = 0;
                update_option( 'wp_restore_state_' . $task_id, $state );
                $state_updated = true;
            }

            if ( ( microtime( true ) - $start_time ) > $max_time ) break;
        }
        fclose( $handle );

        if ( ! $state_updated ) {
            $state['last_update'] = microtime(true);
            $state['processed_since_update'] = 0;
            update_option( 'wp_restore_state_' . $task_id, $state );
        }

        if ( $state['current_index'] >= $state['total_entries'] ) {
            $current_domain = home_url();
            $old_domain = isset( $state['siteinfo']['siteurl'] ) ? $state['siteinfo']['siteurl'] : '';
            if ( ! empty( $old_domain ) && $old_domain !== $current_domain ) {
                $this->replace_domain_in_db( $old_domain, $current_domain );
            }
            @unlink( $meta_file );
            $state['status'] = 'done';
            $state['percent'] = 100;
            $state['message'] = '还原完成！';
            update_option( 'wp_restore_state_' . $task_id, $state );
            delete_transient( 'wp_current_restore_task' );
            $this->log( "还原完成" );
        } else {
            $this->log( "批次处理完成，当前索引: {$state['current_index']}" );
        }
    }

    /**
     * 导入 SQL 文件，逐条执行，避免超时
     */
    private function import_sql_file_with_progress( $task_id, $sql_file ) {
        global $wpdb;
        $mysqli = $wpdb->dbh;

        if ( ! $mysqli instanceof mysqli ) {
            // 降级
            $this->log( "MySQLi 不可用，使用降级导入" );
            return $this->import_sql_file_fallback( $sql_file );
        }

        @$mysqli->query("SET SESSION wait_timeout=3600");
        @$mysqli->query("SET SESSION max_allowed_packet=268435456");
        @$mysqli->query("SET SESSION net_read_timeout=3600");
        @$mysqli->query("SET SESSION net_write_timeout=3600");

        $handle = fopen( $sql_file, 'r' );
        if ( ! $handle ) {
            $this->log( "无法打开 SQL 文件: $sql_file" );
            return false;
        }

        $buffer = '';
        $statement_count = 0;
        $last_update = microtime(true);
        $error_count = 0;
        $max_errors = 20;

        while ( ! feof( $handle ) ) {
            $line = fgets( $handle );
            if ( $line === false ) break;
            $buffer .= $line;

            if ( substr( trim( $line ), -1 ) === ';' ) {
                $query = trim( $buffer );
                if ( ! empty( $query ) ) {
                    if ( ! $mysqli->query( $query ) ) {
                        $error = $mysqli->error;
                        $this->log( "SQL 执行错误: $error | SQL片段: " . substr($query, 0, 200) );
                        $error_count++;
                        if ( $error_count >= $max_errors ) {
                            $this->log( "SQL 导入失败，错误过多" );
                            fclose( $handle );
                            return false;
                        }
                    } else {
                        $error_count = 0; // 重置错误计数
                    }
                    $statement_count++;

                    // 定期更新状态
                    $now = microtime(true);
                    if ( $now - $last_update >= 2 ) {
                        $state = get_option( 'wp_restore_state_' . $task_id );
                        if ( $state ) {
                            $state['message'] = sprintf( '正在导入数据库 (%d 条语句)', $statement_count );
                            $percent = min( 50 + ( $statement_count / 200 ), 95 );
                            $state['percent'] = (int) $percent;
                            update_option( 'wp_restore_state_' . $task_id, $state );
                        }
                        $last_update = $now;
                    }

                    // 释放结果集
                    if ( $mysqli->more_results() ) {
                        while ( $mysqli->next_result() ) {
                            if ( $result = $mysqli->store_result() ) {
                                $result->free();
                            }
                        }
                    }
                }
                $buffer = '';
            }
        }
        fclose( $handle );
        $this->log( "SQL 导入完成，共执行 $statement_count 条语句" );
        return true;
    }

    private function import_sql_file_fallback( $sql_file ) {
        global $wpdb;
        $handle = fopen( $sql_file, 'r' );
        if ( ! $handle ) return false;
        $buffer = '';
        while ( ! feof( $handle ) ) {
            $line = fgets( $handle );
            if ( $line === false ) break;
            $buffer .= $line;
            if ( substr( trim( $line ), -1 ) === ';' ) {
                $query = trim( $buffer );
                if ( ! empty( $query ) ) {
                    $wpdb->query( $query );
                }
                $buffer = '';
            }
        }
        fclose( $handle );
        return true;
    }

    private function execute_sql( $sql ) {
        global $wpdb;
        $mysqli = $wpdb->dbh;
        if ( $mysqli instanceof mysqli ) {
            if ( $mysqli->multi_query( $sql ) ) {
                do {
                    if ( $result = $mysqli->store_result() ) {
                        $result->free();
                    }
                } while ( $mysqli->next_result() );
            } else {
                $this->log( "SQL 执行错误: " . $mysqli->error );
            }
        } else {
            $queries = preg_split( '/;(?![^"]*"(?:(?:[^"]*"){2})*[^"]*$)/', $sql );
            foreach ( $queries as $q ) {
                $q = trim( $q );
                if ( empty( $q ) ) continue;
                $wpdb->query( $q );
            }
        }
    }

    private function replace_domain_in_db( $old, $new ) {
        global $wpdb;
        $old = esc_sql( $old );
        $new = esc_sql( $new );
        $wpdb->query( "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, '$old', '$new') WHERE option_name IN ('siteurl', 'home')" );
        $wpdb->query( "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, '$old', '$new') WHERE guid LIKE '%$old%'" );
        $wpdb->query( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, '$old', '$new') WHERE post_content LIKE '%$old%'" );
        $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, '$old', '$new') WHERE meta_value LIKE '%$old%'" );
        $this->log( "域名替换完成: $old -> $new" );
    }

    private function mark_restore_error( $task_id, $message ) {
        $state = get_option( 'wp_restore_state_' . $task_id );
        if ( $state ) {
            $state['status'] = 'error';
            $state['message'] = $message;
            update_option( 'wp_restore_state_' . $task_id, $state );
            if ( isset( $state['meta_file'] ) && file_exists( $state['meta_file'] ) ) {
                @unlink( $state['meta_file'] );
            }
            if ( isset( $state['temp_dir'] ) && is_dir( $state['temp_dir'] ) ) {
                $this->remove_directory( $state['temp_dir'] );
            }
        }
        delete_transient( 'wp_current_restore_task' );
        error_log( "[WP Restore] ERROR: $message" );
        $this->log( "错误: $message" );
    }

    private function clean_restore_state( $task_id ) {
        $state = get_option( 'wp_restore_state_' . $task_id );
        if ( $state ) {
            if ( isset( $state['meta_file'] ) && file_exists( $state['meta_file'] ) ) {
                @unlink( $state['meta_file'] );
            }
            if ( isset( $state['temp_dir'] ) && is_dir( $state['temp_dir'] ) ) {
                $this->remove_directory( $state['temp_dir'] );
            }
        }
        delete_option( 'wp_restore_state_' . $task_id );
    }

    private function get_file_list( $root, $exclude_dir ) {
        $files = array();
        $exclude_dir = realpath( $exclude_dir );
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
        } catch ( Exception $e ) {
            return array();
        }
        foreach ( $iterator as $fileinfo ) {
            if ( $fileinfo->isFile() ) {
                $path = $fileinfo->getRealPath();
                if ( $path && strpos( $path, $exclude_dir ) === 0 ) continue;
                $files[] = $path;
            }
        }
        return $files;
    }

    private function remove_directory( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $objects = scandir( $dir );
        foreach ( $objects as $object ) {
            if ( $object != '.' && $object != '..' ) {
                $path = $dir . DIRECTORY_SEPARATOR . $object;
                if ( is_dir( $path ) ) $this->remove_directory( $path );
                else @unlink( $path );
            }
        }
        @rmdir( $dir );
    }

    public function ajax_get_backup_info() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        if ( ! check_ajax_referer( 'wp_get_backup_info', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => '安全验证失败' ) );
        }
        $backup_file = isset( $_POST['backup_file'] ) ? sanitize_text_field( $_POST['backup_file'] ) : '';
        if ( empty( $backup_file ) ) wp_send_json_error( '未选择备份文件' );

        $handle = fopen( $backup_file, 'rb' );
        if ( ! $handle ) wp_send_json_error( '无法读取备份文件' );
        $magic = fread( $handle, 4 );
        fclose( $handle );

        if ( $magic === pack( 'N', 0x504b0304 ) || bin2hex($magic) === '504b0304' ) {
            $zip = new ZipArchive();
            if ( $zip->open( $backup_file ) === true ) {
                $info_content = $zip->getFromName( 'siteinfo.json' );
                $zip->close();
                if ( $info_content !== false ) {
                    $info = json_decode( $info_content, true );
                    $old_domain = isset( $info['siteurl'] ) ? $info['siteurl'] : '';
                    wp_send_json_success( array( 'old_domain' => $old_domain ) );
                } else {
                    wp_send_json_success( array( 'old_domain' => '', 'message' => '备份中未找到站点信息' ) );
                }
            } else {
                wp_send_json_error( '无法读取备份文件' );
            }
            return;
        }

        if ( $magic !== pack( 'N', 0x4247424B ) ) {
            wp_send_json_error( '无效的备份文件格式' );
        }

        $handle = fopen( $backup_file, 'rb' );
        fseek( $handle, 5 );
        while ( ! feof( $handle ) ) {
            $name_len_data = fread( $handle, 4 );
            if ( strlen( $name_len_data ) < 4 ) break;
            $name_len = unpack( 'N', $name_len_data )[1];
            if ( $name_len == 0xFFFFFFFF ) break;
            $name = fread( $handle, $name_len );
            $size_data = fread( $handle, 4 );
            if ( strlen( $size_data ) < 4 ) break;
            $size = unpack( 'N', $size_data )[1];
            if ( $name === 'siteinfo.json' ) {
                $content = fread( $handle, $size );
                $info = json_decode( $content, true );
                fclose( $handle );
                wp_send_json_success( array( 'old_domain' => isset( $info['siteurl'] ) ? $info['siteurl'] : '' ) );
            } else {
                fseek( $handle, $size, SEEK_CUR );
            }
        }
        fclose( $handle );
        wp_send_json_success( array( 'old_domain' => '', 'message' => '备份中未找到站点信息' ) );
    }
}

new WP_Backup_Restore_Active();
