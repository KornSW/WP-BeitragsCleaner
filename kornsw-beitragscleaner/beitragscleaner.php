<?php
/**
 * Plugin Name: KornSW BeitragsCleaner
 * Description: Zeigt Beiträge ohne gültigen Autor an, verschiebt sie blockweise in den Papierkorb und kann den Papierkorb blockweise leeren.
 * Version: 1.2.5
 * Author: KornSW
 * Plugin URI: https://github.com/KornSW/WP-BeitragsCleaner
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-BeitragsCleaner/master/doc/kornsw-beitragscleaner.update.json
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}


/*************** SELF-UPDATE ***************/
define( 'KSWBEITRAGSCLEA9327_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswbeitragsclea9327_bootstrap( __FILE__ );
/*******************************************/

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
            'Beitragscleaner (KornSW)',
            'manage_options',
            'beitragscleaner',
            array($this, 'render_page')
        );
    }

    public function render_page() {
        $authorFilter = $this->get_selected_author_filter();
        $posts = $this->get_posts_by_author_filter($authorFilter);
        $authors = $this->get_post_author_choices();
        $trashCount = $this->count_trash_posts();

        echo '<div class="wrap">';
        echo '<h1>Beitragscleaner</h1>';

        if (isset($_GET['trashed'])) {
            echo '<div class="notice notice-success"><p>Die gefilterten Beiträge wurden in den Papierkorb verschoben.</p></div>';
        }

        echo '<h2>Beiträge nach Autor-ID filtern</h2>';
        echo '<form method="get" action="' . esc_url(admin_url('edit.php')) . '" style="margin:0 0 16px;">';
        echo '<input type="hidden" name="page" value="beitragscleaner">';
        echo '<label for="bc-author-filter"><strong>Autor:</strong></label> ';
        echo '<select id="bc-author-filter" name="author_filter">';

        foreach ($authors as $author) {
            $value = (string) intval($author->post_author);
            $label = $this->format_author_label(intval($author->post_author));
            echo '<option value="' . esc_attr($value) . '" ' . selected($authorFilter, $value, false) . '>';
            echo esc_html($label . ' — ' . intval($author->post_count) . ' Beiträge');
            echo '</option>';
        }

        echo '</select> ';
        echo '<button type="submit" class="button">Filtern</button>';
        echo '</form>';

        if ($authorFilter === null) {
            echo '<div class="notice notice-warning inline"><p>Bitte zuerst einen Autor auswählen. Ohne aktiven Autor-Filter kann nichts gelöscht werden.</p></div>';
        } else {
            echo '<p><strong>Aktiver Filter:</strong> ' . esc_html($this->format_author_label((int) $authorFilter)) . '</p>';
            echo '<p><strong>Gefundene Beiträge:</strong> ' . intval(count($posts)) . '</p>';
        }

        if ($authorFilter !== null && empty($posts)) {
            echo '<p>Für diesen Autor wurden keine passenden Beiträge gefunden.</p>';
        } elseif ($authorFilter !== null) {
            echo '<table class="widefat striped" style="font-size:12px;">';
            echo '<thead><tr>';
            echo '<th style="width:90px;">Beitrag-ID</th>';
            echo '<th>Titel</th>';
            echo '<th style="width:220px;">Autor (ID)</th>';
            echo '<th style="width:160px;">Erstellt</th>';
            echo '</tr></thead><tbody>';

            foreach ($posts as $post) {
                echo '<tr>';
                echo '<td>' . intval($post->ID) . '</td>';
                echo '<td>' . esc_html($post->post_title) . '</td>';
                echo '<td>' . esc_html($this->format_author_label((int) $post->post_author)) . '</td>';
                echo '<td>' . esc_html($post->post_date) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
            echo '<input type="hidden" name="action" value="bc_trash_all">';
            echo '<input type="hidden" name="author_filter" value="' . esc_attr($authorFilter) . '">';
            wp_nonce_field('bc_trash_all_action', 'bc_trash_all_nonce');
            echo '<button class="button">Alle gefilterten Beiträge klassisch in den Papierkorb schieben</button>';
            echo '</form>';

            echo '<p style="margin-top:16px;">';
            echo '<button id="bc-trash-chunked" class="button button-primary">Alle gefilterten Beiträge in Blöcken in den Papierkorb schieben</button>';
            echo '</p>';

            echo '<div id="bc-progress" style="display:none; margin-top:12px;">';
            echo '<strong>Fortschritt:</strong> <span id="bc-progress-current">0</span> von <span id="bc-progress-total">' . intval(count($posts)) . '</span>';
            echo '</div>';
        }

        echo '<hr style="margin:32px 0;">';
        echo '<h2>Papierkorb leeren</h2>';
        echo '<p><strong>Beiträge im Papierkorb:</strong> <span id="bc-trash-total">' . intval($trashCount) . '</span></p>';
        echo '<p><button id="bc-delete-trash-chunked" class="button button-secondary">Papierkorb blockweise endgültig löschen</button></p>';
        echo '<div id="bc-delete-progress" style="display:none; margin-top:12px;">';
        echo '<strong>Gelöscht:</strong> <span id="bc-delete-current">0</span> von <span id="bc-delete-total">' . intval($trashCount) . '</span>';
        echo '</div>';

        $trashNonce = wp_create_nonce('bc_trash_chunk_action');
        $deleteNonce = wp_create_nonce('bc_delete_trash_chunk_action');
        $jsAuthorFilter = $authorFilter === null ? '' : (string) $authorFilter;

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
    const authorFilter = "' . esc_js($jsAuthorFilter) . '";
    let initialTrashTotal = null;
    let initialDeleteTotal = null;

    if (trashButton) {
        trashButton.addEventListener("click", function() {
            if (authorFilter === "") {
                alert("Es ist kein Autor-Filter aktiv.");
                return;
            }
            if (!confirm("Wirklich alle Beiträge des aktuell ausgewählten Autors in den Papierkorb schieben?")) {
                return;
            }
            trashButton.disabled = true;
            trashProgress.style.display = "block";
            initialTrashTotal = parseInt(trashTotal.textContent, 10) || 0;
            trashNextChunk();
        });
    }

    if (deleteButton) {
        deleteButton.addEventListener("click", function() {
            if (!confirm("Wirklich ALLE Beiträge im Papierkorb endgültig löschen? Diese Aktion ist unabhängig vom Autor-Filter.")) {
                return;
            }
            deleteButton.disabled = true;
            deleteProgress.style.display = "block";
            initialDeleteTotal = parseInt(deleteTotal.textContent, 10) || 0;
            deleteNextChunk();
        });
    }

    function trashNextChunk() {
        const formData = new FormData();
        formData.append("action", "bc_trash_chunk");
        formData.append("nonce", "' . esc_js($trashNonce) . '");
        formData.append("author_filter", authorFilter);

        fetch(ajaxurl, { method: "POST", credentials: "same-origin", body: formData })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (!result.success) {
                throw new Error(result.data && result.data.message ? result.data.message : "Unbekannter Fehler.");
            }

            trashCurrent.textContent = result.data.processed_total;
            trashTotal.textContent = result.data.total;

            if (result.data.remaining > 0) {
                trashNextChunk();
                return;
            }

            trashButton.textContent = "Fertig";
            window.location.href = "' . esc_url(admin_url('edit.php?page=beitragscleaner&trashed=1&author_filter=')) . '" + encodeURIComponent(authorFilter);
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

        fetch(ajaxurl, { method: "POST", credentials: "same-origin", body: formData })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (!result.success) {
                throw new Error(result.data && result.data.message ? result.data.message : "Unbekannter Fehler.");
            }

            deleteCurrent.textContent = result.data.processed_total;
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

        $authorFilter = $this->validate_author_filter_from_request($_POST);
        if ($authorFilter === null) {
            wp_die('Kein gültiger Autor-Filter übermittelt. Aus Sicherheitsgründen wurde nichts gelöscht.');
        }

        do {
            $posts = $this->get_posts_by_author_filter($authorFilter, self::_ChunkSize);

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                if ((int) $post->post_author !== (int) $authorFilter) {
                    continue;
                }

                $currentAuthor = $this->get_current_post_author_id((int) $post->ID);
                if ($currentAuthor !== (int) $authorFilter) {
                    continue;
                }

                wp_trash_post((int) $post->ID);
            }
        } while (count($posts) > 0);

        wp_safe_redirect(admin_url('edit.php?page=beitragscleaner&trashed=1&author_filter=' . rawurlencode((string) $authorFilter)));
        exit;
    }

    public function trash_chunk() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Keine Berechtigung.'));
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bc_trash_chunk_action')) {
            wp_send_json_error(array('message' => 'Ungültige Anfrage.'));
        }

        $authorFilter = $this->validate_author_filter_from_request($_POST);
        if ($authorFilter === null) {
            wp_send_json_error(array('message' => 'Kein gültiger Autor-Filter übermittelt. Es wurde nichts gelöscht.'));
        }

        $totalBefore = $this->count_posts_by_author_filter($authorFilter);
        $posts = $this->get_posts_by_author_filter($authorFilter, self::_ChunkSize);
        $processed = 0;

        foreach ($posts as $post) {
            if ((int) $post->post_author !== (int) $authorFilter) {
                continue;
            }

            $currentAuthor = $this->get_current_post_author_id((int) $post->ID);
            if ($currentAuthor !== (int) $authorFilter) {
                continue;
            }

            if (wp_trash_post((int) $post->ID)) {
                $processed++;
            }
        }

        $remaining = $this->count_posts_by_author_filter($authorFilter);
        $processedTotal = max(0, $totalBefore - $remaining);

        wp_send_json_success(array(
            'processed_total' => $processedTotal,
            'processed_chunk' => $processed,
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

        $processed = 0;
        foreach ($posts as $postId) {
            if (wp_delete_post((int) $postId, true)) {
                $processed++;
            }
        }

        $remaining = $this->count_trash_posts();
        $processedTotal = max(0, $totalBefore - $remaining);

        wp_send_json_success(array(
            'processed_total' => $processedTotal,
            'processed_chunk' => $processed,
            'remaining' => $remaining,
            'total' => $totalBefore
        ));
    }

    private function get_selected_author_filter() {
        if (!isset($_GET['author_filter'])) {
            return null;
        }

        return $this->normalize_author_filter(wp_unslash($_GET['author_filter']));
    }

    private function validate_author_filter_from_request($source) {
        if (!isset($source['author_filter'])) {
            return null;
        }

        return $this->normalize_author_filter(wp_unslash($source['author_filter']));
    }

    private function normalize_author_filter($value) {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return null;
        }

        return (string) intval($value);
    }

    private function get_post_author_choices() {
        global $wpdb;

        $statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "
            SELECT p.post_author, COUNT(*) AS post_count
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'post'
            AND p.post_status IN ($placeholders)
            GROUP BY p.post_author
            ORDER BY p.post_author ASC
        ";

        return $wpdb->get_results($wpdb->prepare($sql, $statuses));
    }

    private function format_author_label($authorId) {
        $authorId = (int) $authorId;

        if ($authorId === 0) {
            return '— / kein Autor (ID 0)';
        }

        $user = get_userdata($authorId);
        if ($user) {
            return $user->display_name . ' (ID ' . $authorId . ')';
        }

        return '— / Benutzer nicht vorhanden (ID ' . $authorId . ')';
    }

    private function get_posts_by_author_filter($authorFilter, $limit = 0) {
        global $wpdb;

        if ($authorFilter === null) {
            return array();
        }

        $authorId = (int) $authorFilter;
        $statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "
            SELECT p.ID, p.post_title, p.post_date, p.post_author
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'post'
            AND p.post_status IN ($placeholders)
            AND p.post_author = %d
            ORDER BY p.post_date ASC, p.ID ASC
        ";

        $params = array_merge($statuses, array($authorId));

        if ($limit > 0) {
            $sql .= ' LIMIT ' . intval($limit);
        }

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    private function count_posts_by_author_filter($authorFilter) {
        global $wpdb;

        if ($authorFilter === null) {
            return 0;
        }

        $authorId = (int) $authorFilter;
        $statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $sql = "
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'post'
            AND p.post_status IN ($placeholders)
            AND p.post_author = %d
        ";

        $params = array_merge($statuses, array($authorId));
        return intval($wpdb->get_var($wpdb->prepare($sql, $params)));
    }

    private function get_current_post_author_id($postId) {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'post' LIMIT 1",
                $postId
            )
        );

        return $value === null ? null : (int) $value;
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
