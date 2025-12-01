<?php
/**
 * Plugin Name: Gestión de Usuarios
 * Description: Gestión de usuarios con listado y creacion de credenciales aleatorias.
 * Version: 1.0.0
 * Author: Inecxus
 */

if (!defined('ABSPATH')) exit;

class GUC_Plugin {

    /** Ajusta si usas otro rol o dominio ficticio para el correo */
    const DB_VERSION       = '1.0.0';
    const TABLE            = 'guc_users';
    const GUC_DEFAULT_ROLE = 'customer';       // cambia a 'cliente' si tu rol personalizado se llama así (slug)
    const GUC_EMAIL_DOMAIN = 'legalengineering.local';   // dominio ficticio para generar emails únicos

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_shortcode('gestion_usuarios_casos', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts',        [$this, 'enqueue_assets']);

        // AJAX: logged-in y no-logged (nopriv) para funcionar con logins de membresía
        add_action('wp_ajax_guc_create',        [$this, 'ajax_create']);
        add_action('wp_ajax_guc_list',          [$this, 'ajax_list']);
        add_action('wp_ajax_guc_delete',        [$this, 'ajax_delete']);
        add_action('wp_ajax_guc_update',        [$this, 'ajax_update']);

        add_action('wp_ajax_nopriv_guc_create', [$this, 'ajax_create']);
        add_action('wp_ajax_nopriv_guc_list',   [$this, 'ajax_list']);
        add_action('wp_ajax_nopriv_guc_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_nopriv_guc_update', [$this, 'ajax_update']);

        // evita caché en /panel-administrador (recomendado)
        add_action('wp',                         [$this, 'nocache_panel_page']);
    }

    /** Crear tabla interna al activar */
    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(16) NOT NULL,
            password_plain VARCHAR(64) NOT NULL,
            entity VARCHAR(191) NULL,
            expediente VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        add_option('guc_db_version', self::DB_VERSION);
    }

    /** Evitar cache en /panel-administrador */
    public function nocache_panel_page() {
        if (function_exists('is_page') && is_page('panel-administrador')) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
            if (!headers_sent()) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
        }
    }

    /** Cargar CSS y JS sólo donde se usa el shortcode */
    public function enqueue_assets() {
        if (!is_singular()) return;
        global $post;
        if (!$post) return;
        if (has_shortcode($post->post_content, 'gestion_usuarios_casos')) {
            wp_register_style('guc-css', false, [], '1.3.0');
            wp_enqueue_style('guc-css');

            $css = <<<CSS
            @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Poppins:wght@400;500;600;700&display=swap');
            .guc-wrap{font-family:'Poppins','Montserrat',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#2e1b25;background:linear-gradient(135deg,#f8f3ec 0%,#fdfbf7 100%);padding:8px;border-radius:18px}
            .guc-wrap *{box-sizing:border-box}
            .guc-wrap .guc-header{display:flex;align-items:center;justify-content:space-between;margin:8px 0 16px;gap:12px}
            .guc-wrap .guc-title{font-weight:800;font-size:24px;margin:0;color:#42041a;letter-spacing:.3px}
            .guc-wrap .guc-card{background:#fff;border:1px solid #f0e3d4;border-radius:18px;padding:12px;box-shadow:0 8px 28px rgba(104,9,43,.12)}
            .guc-wrap .guc-btn{border:0;border-radius:14px;padding:11px 18px;cursor:pointer;transition:.2s ease;box-shadow:0 6px 16px rgba(66,4,26,.15);font-weight:700;letter-spacing:.2px}
            .guc-wrap .guc-btn-primary{background:linear-gradient(135deg,#68092b,#42041a);color:#fff}
            .guc-wrap .guc-btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(66,4,26,.25)}
            .guc-wrap .guc-btn-primary:disabled{opacity:.65;cursor:not-allowed;box-shadow:none;transform:none}
            .guc-wrap .guc-btn-outline{background:#fff;border:2px solid #d2ae6d;color:#42041a;box-shadow:none}
            .guc-wrap .guc-btn-outline:hover{background:#fff7ea}
            .guc-wrap .guc-table{width:100%;border-collapse:separate;border-spacing:0;border-radius:14px;overflow:hidden}
            .guc-wrap .guc-table th,.guc-wrap .guc-table td{padding:12px;border-bottom:1px solid #f0e6db;text-align:left}
            .guc-wrap .guc-table thead th{font-size:13px;text-transform:uppercase;letter-spacing:.8px;background:#f6efe5;color:#42041a}
            .guc-wrap .guc-table tbody tr:hover td{background:#fff7ea}
            .guc-wrap .guc-badge-green{background:linear-gradient(135deg,#d2ae6d,#b29f59);color:#42041a;padding:7px 12px;border-radius:999px;font-size:12px;display:inline-block;font-weight:800;box-shadow:inset 0 1px 0 rgba(255,255,255,.45)}
            .guc-wrap .guc-actions{display:flex;align-items:center}
            .guc-wrap .guc-actions .guc-icon{border:0;background:#f7efe5;padding:9px;border-radius:12px;margin-right:8px;cursor:pointer;transition:.18s;box-shadow:0 4px 10px rgba(0,0,0,.08);color:#42041a;font-size:14px}
            .guc-wrap .guc-actions .guc-icon:last-child{margin-right:0}
            .guc-wrap .guc-actions .guc-view{background:#d2ae6d;color:#42041a}
            .guc-wrap .guc-actions .guc-edit{background:#bb985c;color:#fff}
            .guc-wrap .guc-actions .guc-del{background:#68092b;color:#fff}
            .guc-wrap .guc-actions .guc-icon:hover{transform:translateY(-1px);box-shadow:0 10px 18px rgba(0,0,0,.16)}
            .guc-wrap .guc-empty{padding:20px;text-align:center;color:#6b4b53;font-size:14px;background:#fff7ea;border-radius:14px;border:1px dashed #d2ae6d;margin-top:10px}
            .guc-wrap .guc-modal-mask{position:fixed;inset:0;background:rgba(35,10,23,.55);display:flex;align-items:center;justify-content:center;z-index:9999;padding:14px}
            .guc-wrap .guc-modal-mask[hidden]{display:none!important}
            .guc-wrap .guc-modal{width:min(680px,92vw);background:#fff;border-radius:24px;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden;border:1px solid #f0e3d4}
            .guc-wrap .guc-modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #f0e6db;background:linear-gradient(135deg,#68092b,#42041a);color:#fff}
            .guc-wrap .guc-modal-title{font-size:20px;font-weight:800;margin:0;letter-spacing:.4px}
            .guc-wrap .guc-close{background:transparent;border:0;font-size:22px;cursor:pointer;color:#fff}
            .guc-wrap .guc-modal-body{padding:22px 20px;background:#fffdfa}
            .guc-wrap .guc-field{margin-bottom:16px}
            .guc-wrap .guc-label{display:block;font-size:13px;font-weight:700;margin-bottom:8px;color:#42041a;letter-spacing:.2px}
            .guc-wrap .guc-input{width:100%;padding:12px 12px;border-radius:12px;border:1px solid #e6d6c2;background:#fffefb;font-family:'Montserrat','Poppins',sans-serif;transition:.18s}
            .guc-wrap .guc-input:focus{outline:none;border-color:#d2ae6d;box-shadow:0 0 0 3px rgba(210,174,109,.35)}
            .guc-wrap .guc-input[disabled]{background:#f4efe6;color:#8c7b74}
            .guc-wrap .guc-modal-footer{display:flex;gap:10px;justify-content:flex-end;padding:16px 20px;border-top:1px solid #f0e6db;background:#fff}
            .guc-wrap .guc-helper{font-size:12px;color:#7d625d;margin-top:-6px;margin-bottom:10px}
            @media (max-width:640px){
                .guc-wrap .guc-header{flex-direction:column;align-items:flex-start;gap:12px}
                .guc-wrap .guc-title{font-size:22px}
            }
            CSS;

            wp_add_inline_style('guc-css', $css);

            wp_enqueue_script('guc-js', plugin_dir_url(__FILE__) . 'js.js', [], '1.3.0', true);
        }
    }

    /** Shortcode (sin exigir sesión WP: tu login ya protege la página) */
    public function shortcode($atts) {
        $this->maybe_create_table();

        $ajax    = admin_url('admin-ajax.php');
        $nonce   = wp_create_nonce('guc_nonce');
        $cap_err = __('No autorizado', 'guc');

        ob_start(); ?>
        <div id="guc-usuarios-casos" class="guc-wrap" data-ajax="<?php echo esc_url($ajax); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-caperr="<?php echo esc_attr($cap_err); ?>">
            <div class="guc-header">
                <h2 class="guc-title">Gestión de Usuarios</h2>
                <button class="guc-btn guc-btn-primary" id="guc-open-modal" type="button">Crear usuario</button>
            </div>
            <div class="guc-card">
                <table class="guc-table" id="guc-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Contraseña</th>
                            <th>Entidad</th>
                            <th>Expediente</th>
                            <th>Fecha creación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="guc-tbody"></tbody>
                </table>
                <div class="guc-empty" id="guc-empty" hidden>No hay usuarios registrados.</div>
            </div>

            <!-- Modal: Crear -->
            <div class="guc-modal-mask" id="guc-mask" hidden>
                <div class="guc-modal" role="dialog" aria-modal="true" aria-labelledby="guc-modal-title">
                    <div class="guc-modal-header">
                        <h3 class="guc-modal-title" id="guc-modal-title">Crear nuevo usuario</h3>
                        <button class="guc-close" id="guc-close" type="button" aria-label="Cerrar">×</button>
                    </div>
                    <div class="guc-modal-body">
                        <div class="guc-field">
                            <label class="guc-label" for="guc-expediente">Nro Expediente</label>
                            <input type="text" class="guc-input" id="guc-expediente" placeholder="Ej.: LEG-2033-GL">
                            <div class="guc-helper">Se guardará exactamente como lo ingreses.</div>
                        </div>
                    </div>
                    <div class="guc-modal-footer">
                        <button class="guc-btn guc-btn-outline" id="guc-cancel" type="button">Cancelar</button>
                        <button class="guc-btn guc-btn-primary" id="guc-create" type="button">Crear</button>
                    </div>
                </div>
            </div>

            <!-- Modal: Ver -->
            <div class="guc-modal-mask" id="guc-view-mask" hidden>
                <div class="guc-modal" role="dialog" aria-modal="true" aria-labelledby="guc-view-title">
                    <div class="guc-modal-header">
                        <h3 class="guc-modal-title" id="guc-view-title">Ver usuario</h3>
                        <button class="guc-close" id="guc-view-close" type="button" aria-label="Cerrar">×</button>
                    </div>
                    <div class="guc-modal-body">
                        <div class="guc-field">
                            <label class="guc-label" for="guc-view-username">Usuario</label>
                            <input type="text" class="guc-input" id="guc-view-username" disabled>
                        </div>
                        <div class="guc-field">
                            <label class="guc-label" for="guc-view-password">Contraseña</label>
                            <input type="text" class="guc-input" id="guc-view-password" disabled>
                        </div>
                        <div class="guc-field">
                            <label class="guc-label" for="guc-view-entity">Entidad</label>
                            <input type="text" class="guc-input" id="guc-view-entity" disabled>
                        </div>
                        <div class="guc-field">
                            <label class="guc-label" for="guc-view-expediente">Expediente</label>
                            <input type="text" class="guc-input" id="guc-view-expediente" disabled>
                        </div>
                    </div>
                    <div class="guc-modal-footer">
                        <button class="guc-btn guc-btn-primary" id="guc-view-ok" type="button">Cerrar</button>
                    </div>
                </div>
            </div>

            <!-- Modal: Editar -->
            <div class="guc-modal-mask" id="guc-edit-mask" hidden>
                <div class="guc-modal" role="dialog" aria-modal="true" aria-labelledby="guc-edit-title">
                    <div class="guc-modal-header">
                        <h3 class="guc-modal-title" id="guc-edit-title">Editar usuario</h3>
                        <button class="guc-close" id="guc-edit-close" type="button" aria-label="Cerrar">×</button>
                    </div>
                    <div class="guc-modal-body">
                        <div class="guc-field">
                            <label class="guc-label" for="guc-edit-username">Usuario</label>
                            <input type="text" class="guc-input" id="guc-edit-username" disabled>
                        </div>
                        <div class="guc-field">
                            <label class="guc-label" for="guc-edit-password">Contraseña</label>
                            <input type="text" class="guc-input" id="guc-edit-password" disabled>
                        </div>
                        <div class="guc-field">
                            <label class="guc-label" for="guc-edit-entity">Entidad</label>
                            <input type="text" class="guc-input" id="guc-edit-entity" placeholder="Ej.: Fiscalía / LEGAL ENGINEERING">
                        </div>
                        <div class="guc-field">
                            <label class="guc-label" for="guc-edit-expediente">Expediente</label>
                            <input type="text" class="guc-input" id="guc-edit-expediente" placeholder="Ej.: LEG-2033-GL">
                        </div>
                    </div>
                    <div class="guc-modal-footer">
                        <button class="guc-btn guc-btn-outline" id="guc-edit-cancel" type="button">Cancelar</button>
                        <button class="guc-btn guc-btn-primary" id="guc-edit-save" type="button">Guardar</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** ---------- UTILIDADES ---------- */
    private function maybe_create_table(){
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table){
            $this->activate();
        }
    }

    /** Seguridad mínima: validamos solo el nonce (CSRF). Tu página ya está restringida por tu sistema. */
    private function ensure_nonce_only() {
        check_ajax_referer('guc_nonce', 'nonce');
    }

    private function random_password($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $pass = '';
        for ($i=0;$i<$length;$i++) $pass .= $chars[random_int(0, strlen($chars)-1)];
        return $pass;
    }

    /** Generar username AAA-000 único también en wp_users */
    private function generate_unique_username_wp(){
        global $wpdb;
        $table_guc = $wpdb->prefix . self::TABLE;

        do {
            $letters = '';
            for ($i=0;$i<3;$i++) $letters .= chr(rand(65,90)); // A-Z
            $digits = str_pad((string)rand(0,999), 3, '0', STR_PAD_LEFT);
            $u = $letters . '-' . $digits;

            $exists_guc = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_guc WHERE username=%s", $u));
            $exists_wp  = username_exists($u);
        } while ($exists_guc > 0 || $exists_wp);

        return $u;
    }

    /** Crear usuario real en WordPress (wp_users) sin necesidad de estar logueado */
    private function create_wp_user($username, $password, $display = ''){
        // correo obligatorio y único
        $email = $username . '@' . self::GUC_EMAIL_DOMAIN;
        $n = 0;
        while (email_exists($email)) {
            $n++;
            $email = $username . '+' . $n . '@' . self::GUC_EMAIL_DOMAIN;
        }

        $user_id = wp_insert_user([
            'user_login'   => $username,
            'user_pass'    => $password,
            'user_email'   => $email,
            'display_name' => $display ?: $username,
            'role'         => self::GUC_DEFAULT_ROLE, // customer / cliente
        ]);

        if (is_wp_error($user_id)) return $user_id;
        return $user_id;
    }

    /** ---------- AJAX ---------- */
    public function ajax_create() {
        $this->ensure_nonce_only();

        $exp = isset($_POST['expediente']) ? sanitize_text_field($_POST['expediente']) : '';
        if (empty($exp)) wp_send_json_error(['msg'=>'Expediente es requerido'], 422);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // 1) generar credenciales únicas (válidas en wp_users)
        $username = $this->generate_unique_username_wp();
        $password = $this->random_password(8);
        $now = current_time('mysql');

        // 2) crear usuario REAL en WP
        $wp_result = $this->create_wp_user($username, $password, $username);
        if (is_wp_error($wp_result)) {
            wp_send_json_error(['msg' => 'No se pudo crear el usuario WP: ' . $wp_result->get_error_message()], 500);
        }

        // 3) guardar además en la tabla del plugin (tu panel)
        $wpdb->insert($table, [
            'username'       => $username,
            'password_plain' => $password,
            'entity'         => '',
            'expediente'     => $exp,
            'created_at'     => $now
        ], ['%s','%s','%s','%s','%s']);

        if (!$wpdb->insert_id) {
            // rollback del usuario WP si algo falló aquí
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($wp_result);
            wp_send_json_error(['msg'=>'Error al crear registro interno'], 500);
        }

        wp_send_json_success([
            'row' => [
                'id'         => $wpdb->insert_id,
                'username'   => $username,
                'password'   => $password,
                'entity'     => '',
                'expediente' => $exp,
                'created_at' => mysql2date('d/m/Y', $now),
            ]
        ]);
    }

    public function ajax_list() {
        $this->ensure_nonce_only();
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results("SELECT id, username, password_plain, entity, expediente, created_at FROM $table ORDER BY id DESC", ARRAY_A);
        $data = array_map(function($r){
            return [
                'id'         => (int)$r['id'],
                'username'   => $r['username'],
                'password'   => $r['password_plain'],
                'entity'     => $r['entity'],
                'expediente' => $r['expediente'],
                'created_at' => mysql2date('d/m/Y', $r['created_at']),
            ];
        }, $rows);
        wp_send_json_success(['rows' => $data]);
    }

    public function ajax_delete() {
    $this->ensure_nonce_only();
    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    if (!$id) wp_send_json_error(['msg'=>'ID inválido'], 422);

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE;

    // 1) obtener el username desde tu tabla interna
    $row = $wpdb->get_row($wpdb->prepare("SELECT username FROM $table WHERE id=%d", $id));
    if (!$row) wp_send_json_error(['msg'=>'Usuario no encontrado'], 404);

    // 2) si existe en wp_users, eliminarlo con la API de WP
    $wp_user = get_user_by('login', $row->username);
    if ($wp_user) {
        // cargar helpers de usuario si hiciera falta
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        // elimina el usuario de wp_users + metas/roles
        wp_delete_user((int)$wp_user->ID);
    }

    // 3) eliminar el espejo en tu tabla interna
    $ok = $wpdb->delete($table, ['id'=>$id], ['%d']);
    if (!$ok) wp_send_json_error(['msg'=>'No se pudo eliminar'], 500);

    wp_send_json_success(['id'=>$id]);
    }

    /** Nuevo: actualizar entidad y expediente */
    public function ajax_update() {
        $this->ensure_nonce_only();

        $id         = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $entity     = isset($_POST['entity']) ? sanitize_text_field($_POST['entity']) : '';
        $expediente = isset($_POST['expediente']) ? sanitize_text_field($_POST['expediente']) : '';

        if (!$id) wp_send_json_error(['msg'=>'ID inválido'], 422);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $ok = $wpdb->update($table, [
            'entity'     => $entity,
            'expediente' => $expediente
        ], ['id' => $id], ['%s','%s'], ['%d']);

        if ($ok === false) wp_send_json_error(['msg'=>'No se pudo actualizar'], 500);

        wp_send_json_success([
            'id'         => $id,
            'entity'     => $entity,
            'expediente' => $expediente
        ]);
    }
}
new GUC_Plugin();
