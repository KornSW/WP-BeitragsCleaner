<?php
/**
 * Plugin Name: Beitragscleaner
 * Description: Zeigt Beiträge ohne gültigen Autor an, verschiebt sie blockweise in den Papierkorb und kann den Papierkorb blockweise leeren.
 * Version: 1.2.0
 * Author: KornSW
 * Plugin URI: https://github.com/KornSW/WP-PluginCollection
 * Update URI: https://tobiaskorn.de/updates/Beitragscleaner/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Beitragscleaner {
    private const _ChunkSize = 50;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_post_bc_trash_all', array($this, 'trash_all'));
        add_action('wp_ajax_bc_trash_chunk', array($this, 'trash_chunk'));
        add_action('wp_ajax_bc_delete_trash_chunk', array($this, 'delete_trash_chunk'));
    }

    public function add_menu() {
        add_submenu_page(
            'edit.php',
            'Beitragscleaner',
            'Beitragscleaner',
            'manage_options',
            'beitragscleaner',
            array($this, 'render_page')
        );
    }

    public function render_page() {
        $posts = $this->get_orphan_posts();
        $trashCount = $this->count_trash_posts();

        echo '<div class="wrap">';
        echo '<h1>Beitragscleaner</h1>';

        if (isset($_GET['trashed'])) {
            echo '<div class="notice notice-success"><p>Beiträge wurden in den Papierkorb verschoben.</p></div>';
        }

        echo '<h2>Beiträge ohne gültigen Autor</h2>';

        if (empty($posts)) {
            echo '<p>Keine passenden Beiträge gefunden.</p>';
        } else {
            echo '<p><strong>Gefundene Beiträge ohne gültigen Autor:</strong> ' . intval(count($posts)) . '</p>';

            echo '<ul style="font-size:12px; line-height:1.4; max-height:400px; overflow:auto; background:#fff; padding:12px; border:1px solid #ccd0d4;">';

            foreach ($posts as $post) {
                echo '<li>' . esc_html($post->post_title) .
                    ' (ID: ' . intval($post->ID) .
                    ', Autor-ID: ' . intval($post->post_author) .
                    ', Erstellt: ' . esc_html($post->post_date) . ')</li>';
            }

            echo '</ul>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
            echo '<input type="hidden" name="action" value="bc_trash_all">';
            wp_nonce_field('bc_trash_all_action', 'bc_trash_all_nonce');
            echo '<button class="button">Alle klassisch in den Papierkorb schieben</button>';
            echo '</form>';

            echo '<p style="margin-top:16px;">';
            echo '<button id="bc-trash-chunked" class="button button-primary">Alle in Blöcken in den Papierkorb schieben</button>';
            echo '</p>';

            echo '<div id="bc-progress" style="display:none; margin-top:12px;">';
            echo '<strong>Fortschritt:</strong> <span id="bc-progress-current">0</span> von <span id="bc-progress-total">' . intval(count($posts)) . '</span>';
            echo '</div>';
        }

        echo '<hr style="margin:32px 0;">';

        echo '<h2>Papierkorb leeren</h2>';
        echo '<p><strong>Beiträge im Papierkorb:</strong> <span id="bc-trash-total">' . intval($trashCount) . '</span></p>';

        echo '<p>';
        echo '<button id="bc-delete-trash-chunked" class="button button-secondary">Papierkorb blockweise endgültig löschen</button>';
        echo '</p>';

        echo '<div id="bc-delete-progress" style="display:none; margin-top:12px;">';
        echo '<strong>Gelöscht:</strong> <span id="bc-delete-current">0</span> von <span id="bc-delete-total">' . intval($trashCount) . '</span>';
        echo '</div>';

        $trashNonce = wp_create_nonce('bc_trash_chunk_action');
        $deleteNonce = wp_create_nonce('bc_delete_trash_chunk_action');

        echo '<script>
(function() {
    const trashButton = document.getElementById("bc-trash-chunked");
    const trashProgress = document.getElementById("bc-progress");
    const trashCurrent = document.getElementById("bc-progress-current");
    const trashTotal = document.getElementById("bc-progress-total");

    const deleteButton = document.getElementById("bc-delete-trash-chunked");
    const deleteProgress = document.getElementById("bc-delete-progress");
    const deleteCurrent = document.getElementById("bc-delete-current");
    const deleteTotal = document.getElementById("bc-delete-total");
    const trashTotalLabel = document.getElementById("bc-trash-total");

    if (trashButton) {
        trashButton.addEventListener("click", function() {
            if (!confirm("Wirklich alle gefundenen Beiträge in den Papierkorb schieben?")) {
                return;
            }

            trashButton.disabled = true;
            trashProgress.style.display = "block";

            trashNextChunk();
        });
    }

    if (deleteButton) {
        deleteButton.addEventListener("click", function() {
            if (!confirm("Wirklich ALLE Beiträge im Papierkorb endgültig löschen?")) {
                return;
            }

            deleteButton.disabled = true;
            deleteProgress.style.display = "block";

            deleteNextChunk();
        });
    }

    function trashNextChunk() {
        const formData = new FormData();

        formData.append("action", "bc_trash_chunk");
        formData.append("nonce", "' . esc_js($trashNonce) . '");

        fetch(ajaxurl, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            if (!result.success) {
                throw new Error(result.data && result.data.message ? result.data.message : "Unbekannter Fehler.");
            }

            trashCurrent.textContent = result.data.trashed_total;
            trashTotal.textContent = result.data.total;

            if (result.data.remaining > 0) {
                trashNextChunk();
                return;
            }

            trashButton.textContent = "Fertig";
            window.location.href = "' . esc_url(admin_url('edit.php?page=beitragscleaner&trashed=1')) . '";
        })
        .catch(function(error) {
            alert("Fehler: " + error.message);
            trashButton.disabled = false;
        });
    }

    function deleteNextChunk() {
        const formData = new FormData();

        formData.append("action", "bc_delete_trash_chunk");
        formData.append("nonce", "' . esc_js($deleteNonce) . '");

        fetch(ajaxurl, {
            method: "POST",
            credentials: "same-origin",
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            if (!result.success) {
                throw new Error(result.data && result.data.message ? result.data.message : "Unbekannter Fehler.");
            }

            deleteCurrent.textContent = result.data.deleted_total;
            deleteTotal.textContent = result.data.total;
            trashTotalLabel.textContent = result.data.remaining;

            if (result.data.remaining > 0) {
                deleteNextChunk();
                return;
            }

            deleteButton.textContent = "Papierkorb geleert";
        })
        .catch(function(error) {
            alert("Fehler: " + error.message);
            deleteButton.disabled = false;
        });
    }
})();
</script>';

        echo '</div>';
    }

    public function trash_all() {
        if (!current_user_can('manage_options')) {
            wp_die('Keine Berechtigung.');
        }

        if (!isset($_POST['bc_trash_all_nonce']) || !wp_verify_nonce($_POST['bc_trash_all_nonce'], 'bc_trash_all_action')) {
            wp_die('Ungültige Anfrage.');
        }

        $posts = $this->get_orphan_posts();

        foreach ($posts as $post) {
            wp_trash_post($post->ID);
        }

        wp_redirect(admin_url('edit.php?page=beitragscleaner&trashed=1'));
        exit;
    }

    public function trash_chunk() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Keine Berechtigung.'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bc_trash_chunk_action')) {
            wp_send_json_error(array('message' => 'Ungültige Anfrage.'));
        }

        $totalBefore = $this->count_orphan_posts();
        $posts = $this->get_orphan_posts(self::_ChunkSize);

        foreach ($posts as $post) {
            wp_trash_post($post->ID);
        }

        $remaining = $this->count_orphan_posts();
        $trashedTotal = max(0, $totalBefore - $remaining);

        wp_send_json_success(array(
            'trashed_total' => $trashedTotal,
            'remaining' => $remaining,
            'total' => $totalBefore
        ));
    }

    public function delete_trash_chunk() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Keine Berechtigung.'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bc_delete_trash_chunk_action')) {
            wp_send_json_error(array('message' => 'Ungültige Anfrage.'));
        }

        $totalBefore = $this->count_trash_posts();

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'trash',
            'posts_per_page' => self::_ChunkSize,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC'
        ));

        foreach ($posts as $postId) {
            wp_delete_post($postId, true);
        }

        $remaining = $this->count_trash_posts();
        $deletedTotal = max(0, $totalBefore - $remaining);

        wp_send_json_success(array(
            'deleted_total' => $deletedTotal,
            'remaining' => $remaining,
            'total' => $totalBefore
        ));
    }

    private function get_orphan_posts($limit = 0) {
        global $wpdb;

        $statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "
            SELECT p.ID, p.post_title, p.post_date, p.post_author
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author
            WHERE p.post_type = 'post'
            AND p.post_status IN ($placeholders)
            AND (p.post_author = 0 OR u.ID IS NULL)
            ORDER BY p.post_date ASC
        ";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . intval($limit);
        }

        return $wpdb->get_results($wpdb->prepare($sql, $statuses));
    }

    private function count_orphan_posts() {
        global $wpdb;

        $statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author
            WHERE p.post_type = 'post'
            AND p.post_status IN ($placeholders)
            AND (p.post_author = 0 OR u.ID IS NULL)
        ";

        return intval($wpdb->get_var($wpdb->prepare($sql, $statuses)));
    }

    private function count_trash_posts() {
        global $wpdb;

        $sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'post'
            AND post_status = 'trash'
        ";

        return intval($wpdb->get_var($sql));
    }
}

new Beitragscleaner();