<?php

if (!defined('ABSPATH')) exit;

class AsgarosForum {
    var $executePlugin = false;
    var $db = false;
    var $directory = '';
    var $date_format = '';
    var $error = false;
    var $info = false;
    var $links = null;
    var $table_forums = '';
    var $table_threads = '';
    var $table_posts = '';
    var $current_category = false;
    var $current_forum = false;
    var $current_thread = false;
    var $current_post = false;
    var $current_view = false;
    var $current_page = 0;
    var $parent_forum = false;
    var $options = array();
    var $options_default = array(
        'location'                  => '',
        'posts_per_page'            => 10,
        'threads_per_page'          => 20,
        'minimalistic_editor'       => true,
        'allow_shortcodes'          => false,
        'allow_guest_postings'      => false,
        'allowed_filetypes'         => 'jpg,jpeg,gif,png,bmp,pdf',
        'allow_file_uploads'        => false,
        'allow_file_uploads_guests' => false,
        'hide_uploads_from_guests'  => false,
        'admin_subscriptions'       => false,
        'allow_subscriptions'       => true,
        'highlight_admin'           => true,
        'highlight_authors'         => true,
        'show_edit_date'            => true,
        'require_login'             => false,
        'custom_color'              => '#2d89cc',
        'custom_text_color'         => '#444444',
        'custom_background_color'   => '#ffffff',
        'theme'                     => 'default'
    );
    var $options_editor = array(
        'media_buttons' => false,
        'textarea_rows' => 12,
        'teeny'         => true,
        'quicktags'     => false
    );
    var $cache = array();   // Used to store selected database queries.

    function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->directory = plugin_dir_url(dirname(__FILE__));
        $this->options = array_merge($this->options_default, get_option('asgarosforum_options', array()));
        $this->options_editor['teeny'] = $this->options['minimalistic_editor'];
        $this->date_format = get_option('date_format').', '.get_option('time_format');
        $this->table_forums = AsgarosForumDatabase::getTable('forums');
        $this->table_threads = AsgarosForumDatabase::getTable('threads');
        $this->table_posts = AsgarosForumDatabase::getTable('posts');

        add_action('wp', array($this, 'prepare'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_scripts'));
        add_filter('wp_title', array($this, 'change_wp_title'), 10, 3);
        add_filter('document_title_parts', array($this, 'change_document_title_parts'));
        add_filter('teeny_mce_buttons', array($this, 'add_mce_buttons'), 9999, 2);
        add_filter('mce_buttons', array($this, 'add_mce_buttons'), 9999, 2);
        add_filter('disable_captions', array($this, 'disable_captions'));
        add_shortcode('forum', array($this, 'forum'));
    }

    function prepare() {
        global $post;

        if (isset($post) && $post->ID == $this->options['location'] && is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'forum')) {
            $this->executePlugin = true;
        } else {
            $this->executePlugin = false;
            $this->error = __('The forum has not been configured correctly.', 'asgaros-forum');
            return;
        }

        if (isset($_GET['view'])) {
            $this->current_view = esc_html($_GET['view']);
        }

        if (isset($_GET['part']) && absint($_GET['part']) > 0) {
            $this->current_page = (absint($_GET['part']) - 1);
        }

        $elementID = (isset($_GET['id'])) ? absint($_GET['id']) : false;

        switch ($this->current_view) {
            case 'forum':
            case 'addthread':
                if ($this->element_exists($elementID, $this->table_forums)) {
                    $this->current_forum = $elementID;
                    $this->parent_forum = $this->get_parent_id($this->current_forum, $this->table_forums, 'parent_forum');
                    $this->current_category = $this->get_parent_id($this->current_forum, $this->table_forums);
                } else {
                    $this->error = __('Sorry, this forum does not exist.', 'asgaros-forum');
                }
                break;
            case 'movethread':
            case 'thread':
            case 'addpost':
                if ($this->element_exists($elementID, $this->table_threads)) {
                    $this->current_thread = $elementID;
                    $this->current_forum = $this->get_parent_id($this->current_thread, $this->table_threads);
                    $this->parent_forum = $this->get_parent_id($this->current_forum, $this->table_forums, 'parent_forum');
                    $this->current_category = $this->get_parent_id($this->current_forum, $this->table_forums);
                } else {
                    $this->error = __('Sorry, this thread does not exist.', 'asgaros-forum');
                }
                break;
            case 'editpost':
                if ($this->element_exists($elementID, $this->table_posts)) {
                    $this->current_post = $elementID;
                    $this->current_thread = $this->get_parent_id($this->current_post, $this->table_posts);
                    $this->current_forum = $this->get_parent_id($this->current_thread, $this->table_threads);
                    $this->parent_forum = $this->get_parent_id($this->current_forum, $this->table_forums, 'parent_forum');
                    $this->current_category = $this->get_parent_id($this->current_forum, $this->table_forums);
                } else {
                    $this->error = __('Sorry, this post does not exist.', 'asgaros-forum');
                }
                break;
        }

        // Generate all links.
        $this->links = AsgarosForumRewrite::setLinks();

        // Check
        $this->check_access();

        // Override editor settings
        $this->options_editor = apply_filters('asgarosforum_filter_editor_settings', $this->options_editor);

        // Prevent generation of some head-elements.
        remove_action('wp_head', 'rel_canonical');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');

        if (isset($_POST['submit_action']) && (is_user_logged_in() || (!is_user_logged_in() && $this->options['allow_guest_postings']))) {
            AsgarosForumInsert::determineAction();
            if (AsgarosForumInsert::getAction()) {
                AsgarosForumInsert::setData();
                if (AsgarosForumInsert::validateExecution()) {
                    AsgarosForumInsert::insertData();
                }
            }
        } else if ($this->current_view === 'markallread') {
            AsgarosForumUnread::markAllRead();
        } else if (isset($_GET['move_thread'])) {
            $this->move_thread();
        } else if (isset($_GET['delete_thread'])) {
            $this->delete_thread($this->current_thread);
        } else if (isset($_GET['remove_post'])) {
            $this->remove_post();
        } else if (isset($_GET['sticky_topic']) || isset($_GET['unsticky_topic'])) {
            $this->change_status('sticky');
        } else if (isset($_GET['open_topic']) || isset($_GET['close_topic'])) {
            $this->change_status('closed');
        } else if (isset($_GET['subscribe_topic'])) {
            AsgarosForumNotifications::subscribeTopic();
        } else if (isset($_GET['unsubscribe_topic'])) {
            AsgarosForumNotifications::unsubscribeTopic();
        }

        // Mark visited topic as read.
        if ($this->current_view === 'thread' && $this->current_thread) {
            AsgarosForumUnread::markThreadRead();
        }
    }

    function check_access() {
        // Check login access.
        if ($this->options['require_login'] && !is_user_logged_in()) {
            $this->error = __('Sorry, only logged in users have access to the forum.', 'asgaros-forum').'&nbsp;<a href="'.esc_url(wp_login_url($this->links->current)).'">&raquo; '.__('Login', 'asgaros-forum').'</a>';
            return false;
        }

        // Check category access.
        $category_access = get_term_meta($this->current_category, 'category_access', true);

        if (!empty($category_access)) {
            if ($category_access === 'loggedin' && !is_user_logged_in()) {
                $this->error = __('Sorry, only logged in users have access to this category.', 'asgaros-forum').'&nbsp;<a href="'.esc_url(wp_login_url($this->links->current)).'">&raquo; '.__('Login', 'asgaros-forum').'</a>';
                return false;
            }

            if ($category_access === 'moderator' && !AsgarosForumPermissions::isModerator('current')) {
                $this->error = __('Sorry, you dont have access to this area.', 'asgaros-forum');
                return false;
            }
        }

        // Check custom access.
        $custom_access = apply_filters('asgarosforum_filter_check_access', true, $this->current_category);

        if (!$custom_access) {
            $this->error = __('Sorry, you dont have access to this area.', 'asgaros-forum');
            return false;
        }
    }

    function enqueue_front_scripts() {
        if (!$this->executePlugin) {
            return;
        }

        wp_enqueue_script('asgarosforum-js', $this->directory.'js/script.js', array('jquery'));
        wp_enqueue_style('dashicons');
    }

    function change_wp_title($title, $sep, $seplocation) {
        if (!$this->executePlugin) {
            return $title;
        }

        return $this->get_title($title);
    }

    function change_document_title_parts($title) {
        if (!$this->executePlugin) {
            return $title;
        }

        $title['title'] = $this->get_title($title['title']);

        return $title;
    }

    function get_title($title) {
        $pre = '';

        if (!$this->error && $this->current_view) {
            if ($this->current_view == 'forum') {
                if ($this->current_forum) {
                    $pre = esc_html(stripslashes($this->get_name($this->current_forum, $this->table_forums))).' - ';
                }
            } else if ($this->current_view == 'thread') {
                if ($this->current_thread) {
                    $pre = esc_html(stripslashes($this->get_name($this->current_thread, $this->table_threads))).' - ';
                }
            } else if ($this->current_view == 'editpost') {
                $pre = __('Edit Post', 'asgaros-forum').' - ';
            } else if ($this->current_view == 'addpost') {
                $pre = __('Post Reply', 'asgaros-forum').' - ';
            } else if ($this->current_view == 'addthread') {
                $pre = __('New Thread', 'asgaros-forum').' - ';
            } else if ($this->current_view == 'movethread') {
                $pre = __('Move Thread', 'asgaros-forum').' - ';
            }
        }

        return $pre.$title;
    }

    function add_mce_buttons($buttons, $editor_id) {
        if (!$this->executePlugin || $editor_id !== 'message') {
            return $buttons;
        } else {
            $buttons[] = 'image';
            return $buttons;
        }
    }

    function disable_captions($args) {
        if (!$this->executePlugin) {
            return $args;
        } else {
            return true;
        }
    }

    function forum() {
        ob_start();
        echo '<div id="af-wrapper">';

        if (!empty($this->error)) {
            echo '<div class="error">'.$this->error.'</div>';
        } else {
            echo $this->breadcrumbs();

            if (!empty($this->info)) {
                echo '<div class="info">'.$this->info.'</div>';
            }

            $this->showLoginMessage();

            switch ($this->current_view) {
                case 'movethread':
                    $this->movethread();
                    break;
                case 'forum':
                    $this->showforum();
                    break;
                case 'thread':
                    $this->showthread();
                    break;
                case 'addthread':
                case 'addpost':
                case 'editpost':
                    include('views/editor.php');
                    break;
                default:
                    $this->overview();
                    break;
            }
        }

        echo '</div>';
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }

    function overview() {
        $categories = $this->get_categories();

        if ($categories) {
            require('views/overview.php');
        } else {
            echo '<div class="notice">'.__('There are no categories yet!', 'asgaros-forum').'</div>';
        }
    }

    function showforum() {
        $threads = $this->get_threads($this->current_forum);
        $sticky_threads = $this->get_threads($this->current_forum, 'sticky');
        $counter_normal = count($threads);
        $counter_total = $counter_normal + count($sticky_threads);

        require('views/forum.php');
    }

    function showthread() {
        global $wp_embed;
        $posts = $this->get_posts();

        if ($posts) {
            $this->db->query($this->db->prepare("UPDATE {$this->table_threads} SET views = views + 1 WHERE id = %d", $this->current_thread));

            $meClosed = ($this->get_status('closed')) ? '&nbsp;('.__('Thread closed', 'asgaros-forum').')' : '';

            require('views/thread.php');
        } else {
            echo '<div class="notice">'.__('Sorry, but there are no posts.', 'asgaros-forum').'</div>';
        }
    }

    function showLoginMessage() {
        $loginMessage = '';

        if (!is_user_logged_in() && !$this->options['allow_guest_postings']) {
            $loginMessage = '<div class="info">'.__('You need to login in order to create posts and topics.', 'asgaros-forum').'&nbsp;<a href="'.esc_url(wp_login_url($this->links->current)).'">&raquo; '.__('Login', 'asgaros-forum').'</a></div>';
        }

        $loginMessage = apply_filters('asgarosforum_filter_login_message', $loginMessage);
        echo $loginMessage;
    }

    function movethread() {
        if (AsgarosForumPermissions::isModerator('current')) {
            $strOUT = '<form method="post" action="'.$this->links->topic_move.'&amp;move_thread">';
            $strOUT .= '<div class="title-element">'.sprintf(__('Move "<strong>%s</strong>" to new forum:', 'asgaros-forum'), esc_html(stripslashes($this->get_name($this->current_thread, $this->table_threads)))).'</div>';
            $strOUT .= '<div class="content-element"><div class="notice">';
            $strOUT .= '<select name="newForumID">';

            $frs = $this->get_forums();

            foreach ($frs as $f) {
                $strOUT .= '<option value="'.$f->id.'"'.($f->id == $this->current_forum ? ' selected="selected"' : '').'>'.esc_html($f->name).'</option>';
            }

            $strOUT .= '</select><br /><input type="submit" value="'.__('Move', 'asgaros-forum').'"></div></div></form>';

            echo $strOUT;
        } else {
            echo '<div class="notice">'.__('You are not allowed to move threads.', 'asgaros-forum').'</div>';
        }
    }

    function element_exists($id, $location) {
        if (!empty($id) && is_numeric($id) && $this->db->get_row($this->db->prepare("SELECT id FROM {$location} WHERE id = %d;", $id))) {
            return true;
        } else {
            return false;
        }
    }

    // TODO: optimize.
    function get_link($id, $location, $page = 1) {
        $page_appendix = ($page > 1) ? '&amp;part='.$page : '';
        return esc_url($location.$id.$page_appendix);
    }

    function get_postlink($thread_id, $post_id, $page = 0) {
        if (!$page) {
            $this->db->get_col($this->db->prepare("SELECT id FROM {$this->table_posts} WHERE parent_id = %d;", $thread_id));
            $page = ceil($this->db->num_rows / $this->options['posts_per_page']);
        }

        return $this->get_link($thread_id, $this->links->topic, $page) . '#postid-' . $post_id;
    }

    function get_categories($disable_hooks = false) {
        $filter = array();

        if (!$disable_hooks) {
            $filter = apply_filters('asgarosforum_filter_get_categories', $filter);
        }

        $categories = get_terms('asgarosforum-category', array('hide_empty' => false, 'exclude' => $filter));

        foreach ($categories as $key => $category) {
            $term_meta = get_term_meta($category->term_id);
            $category->order = $term_meta['order'][0];
            $category->category_access = (!empty($term_meta['category_access'][0])) ? $term_meta['category_access'][0] : 'everyone';

            // Remove categories from array where the user has no access.
            if (($category->category_access === 'loggedin' && !is_user_logged_in()) || ($category->category_access === 'moderator' && !AsgarosForumPermissions::isModerator('current'))) {
                unset($categories[$key]);
            }
        }

        usort($categories, array($this, 'categories_compare'));

        return $categories;
    }

    function categories_compare($a, $b) {
        return ($a->order < $b->order) ? -1 : (($a->order > $b->order) ? 1 : 0);
    }

    function get_forums($id = false, $parent_forum = 0) {
        if ($id) {
            return $this->db->get_results($this->db->prepare("SELECT f.id, f.name, f.description, f.closed, f.sort, f.parent_forum, (SELECT COUNT(ct_t.id) FROM {$this->table_threads} AS ct_t, {$this->table_forums} AS ct_f WHERE ct_t.parent_id = ct_f.id AND (ct_f.id = f.id OR ct_f.parent_forum = f.id)) AS count_threads, (SELECT COUNT(cp_p.id) FROM {$this->table_posts} AS cp_p, {$this->table_threads} AS cp_t, {$this->table_forums} AS cp_f WHERE cp_p.parent_id = cp_t.id AND cp_t.parent_id = cp_f.id AND (cp_f.id = f.id OR cp_f.parent_forum = f.id)) AS count_posts, (SELECT COUNT(csf_f.id) FROM {$this->table_forums} AS csf_f WHERE csf_f.parent_forum = f.id) AS count_subforums FROM {$this->table_forums} AS f WHERE f.parent_id = %d AND f.parent_forum = %d GROUP BY f.id ORDER BY f.sort ASC;", $id, $parent_forum));
        } else {
            // Load all forums.
            return $this->db->get_results("SELECT id, name FROM {$this->table_forums} ORDER BY sort ASC;");
        }
    }

    function get_threads($id, $type = 'normal') {
        $limit = "";

        if ($type == 'normal') {
            $start = $this->current_page * $this->options['threads_per_page'];
            $end = $this->options['threads_per_page'];
            $limit = $this->db->prepare("LIMIT %d, %d", $start, $end);
        }

        $order = apply_filters('asgarosforum_filter_get_threads_order', "(SELECT MAX(id) FROM {$this->table_posts} AS p WHERE p.parent_id = t.id) DESC");
        $results = $this->db->get_results($this->db->prepare("SELECT t.id, t.name, t.views, t.status FROM {$this->table_threads} AS t WHERE t.parent_id = %d AND t.status LIKE %s ORDER BY {$order} {$limit};", $id, $type.'%'));
        $results = apply_filters('asgarosforum_filter_get_threads', $results);
        return $results;
    }

    function get_posts() {
        $start = $this->current_page * $this->options['posts_per_page'];
        $end = $this->options['posts_per_page'];

        $order = apply_filters('asgarosforum_filter_get_posts_order', 'p1.id ASC');
        $results = $this->db->get_results($this->db->prepare("SELECT p1.id, p1.text, p1.date, p1.date_edit, p1.author_id, (SELECT COUNT(p2.id) FROM {$this->table_posts} AS p2 WHERE p2.author_id = p1.author_id) AS author_posts, uploads FROM {$this->table_posts} AS p1 WHERE p1.parent_id = %d ORDER BY {$order} LIMIT %d, %d;", $this->current_thread, $start, $end));
        $results = apply_filters('asgarosforum_filter_get_posts', $results);
        return $results;
    }

    function is_first_post($post_id) {
        $first_post_id = $this->db->get_var("SELECT id FROM {$this->table_posts} WHERE parent_id = {$this->current_thread} ORDER BY id ASC LIMIT 1;");

        if ($first_post_id == $post_id) {
            return true;
        } else {
            return false;
        }
    }

    function get_name($id, $location) {
        if (empty($this->cache['get_name'][$location][$id])) {
            $this->cache['get_name'][$location][$id] = $this->db->get_var($this->db->prepare("SELECT name FROM {$location} WHERE id = %d;", $id));
        }

        return $this->cache['get_name'][$location][$id];
    }

    function cut_string($string, $length = 33) {
        if (strlen($string) > $length) {
            return mb_substr($string, 0, $length, 'UTF-8') . ' ...';
        }

        return $string;
    }

    function get_username($user_id, $widget = false) {
        if ($user_id == 0) {
            return __('Guest', 'asgaros-forum');
        } else {
            $user = get_userdata($user_id);

            if ($user) {
                $username = $user->display_name;

                if ($this->options['highlight_admin'] && !$widget) {
                    if (user_can($user_id, 'manage_options')) {
                        $username = '<span class="highlight-admin">'.$username.'</span>';
                    } else if (AsgarosForumPermissions::isModerator($user_id)) {
                        $username = '<span class="highlight-moderator">'.$username.'</span>';
                    }
                }

                return $username;
            } else {
                return __('Deleted user', 'asgaros-forum');
            }
        }
    }

    function get_lastpost($lastpost_data, $context = 'forum') {
        $lastpost = false;

        if ($lastpost_data) {
            $lastpost_link = $this->get_postlink($lastpost_data->parent_id, $lastpost_data->id);
            $lastpost = '<small>'.__('Last post by', 'asgaros-forum').'&nbsp;<strong>'.$this->get_username($lastpost_data->author_id).'</strong></small>';
            $lastpost .= ($context === 'forum') ? '<small>'.__('in', 'asgaros-forum').'&nbsp;<strong><a href="'.$lastpost_link.'">'.esc_html($this->cut_string(stripslashes($lastpost_data->name))).'</a></strong></small>' : '';
            $lastpost .= '<small>'.sprintf(__('on %s', 'asgaros-forum'), '<a href="'.$lastpost_link.'">'.$this->format_date($lastpost_data->date).'</a>').'</small>';
        } else if ($context === 'forum') {
            $lastpost = '<small>'.__('No threads yet!', 'asgaros-forum').'</small>';
        }

        return $lastpost;
    }

    function get_thread_starter($thread_id) {
        return $this->db->get_var($this->db->prepare("SELECT author_id FROM {$this->table_posts} WHERE parent_id = %d ORDER BY id ASC LIMIT 1;", $thread_id));
    }

    function post_menu($post_id, $author_id, $counter) {
        $o = '';

        if ((!is_user_logged_in() && $this->options['allow_guest_postings'] && !$this->get_status('closed')) || (is_user_logged_in() && (!$this->get_status('closed') || AsgarosForumPermissions::isModerator('current')) && !AsgarosForumPermissions::isBanned('current'))) {
            $o .= '<a href="'.$this->links->post_add.'&amp;quote='.$post_id.'"><span class="dashicons-before dashicons-editor-quote"></span>'.__('Quote', 'asgaros-forum').'</a>';
        }

        if (is_user_logged_in()) {
            if (($counter > 1 || $this->current_page >= 1) && AsgarosForumPermissions::isModerator('current')) {
                $o .= '<a onclick="return confirm(\''.__('Are you sure you want to remove this?', 'asgaros-forum').'\');" href="'.$this->get_link($this->current_thread, $this->links->topic).'&amp;remove_post&amp;post='.$post_id.'"><span class="dashicons-before dashicons-trash"></span>'.__('Remove', 'asgaros-forum').'</a>';
            }

            if ((AsgarosForumPermissions::isModerator('current') || get_current_user_id() == $author_id) && !AsgarosForumPermissions::isBanned('current')) {
                $o .= '<a href="'.$this->links->post_edit.$post_id.'&amp;part='.($this->current_page + 1).'"><span class="dashicons-before dashicons-edit"></span>'.__('Edit', 'asgaros-forum').'</a>';
            }
        }

        $o = (!empty($o)) ? $o = '<div class="post-menu">'.$o.'</div>' : $o;

        return $o;
    }

    function format_date($date) {
        return date_i18n($this->date_format, strtotime($date));
    }

    function current_time() {
        return current_time('Y-m-d H:i:s');
    }

    function get_post_author($post_id) {
        return $this->db->get_var($this->db->prepare("SELECT author_id FROM {$this->table_posts} WHERE id = %d;", $post_id));
    }

    function count_elements($id, $location, $where = 'parent_id') {
        return $this->db->get_var($this->db->prepare("SELECT COUNT(id) FROM {$location} WHERE {$where} = %d;", $id));
    }

    function forum_menu($location, $showallbuttons = true) {
        $menu = '';

        if ($location === 'forum' && ((is_user_logged_in() && !AsgarosForumPermissions::isBanned('current')) || (!is_user_logged_in() && $this->options['allow_guest_postings'])) && $this->get_forum_status()) {
            $menu .= '<a href="'.$this->links->topic_add.'"><span class="dashicons-before dashicons-format-aside"></span><span>'.__('New Thread', 'asgaros-forum').'</span></a>';
        } else if ($location === 'thread' && ((is_user_logged_in() && (AsgarosForumPermissions::isModerator('current') || (!$this->get_status('closed') && !AsgarosForumPermissions::isBanned('current')))) || (!is_user_logged_in() && $this->options['allow_guest_postings'] && !$this->get_status('closed')))) {
            $menu .= '<a href="'.$this->links->post_add.'"><span class="dashicons-before dashicons-format-aside"></span><span>'.__('Reply', 'asgaros-forum').'</span></a>';
        }

        if (is_user_logged_in() && $location === 'thread' && AsgarosForumPermissions::isModerator('current') && $showallbuttons) {
            $menu .= '<a href="'.$this->links->topic_move.'"><span class="dashicons-before dashicons-randomize"></span><span>'.__('Move Thread', 'asgaros-forum').'</span></a>';
            $menu .= '<a href="'.$this->links->topic.$this->current_thread.'&amp;delete_thread" onclick="return confirm(\''.__('Are you sure you want to remove this?', 'asgaros-forum').'\');"><span class="dashicons-before dashicons-trash"></span><span>'.__('Delete Thread', 'asgaros-forum').'</span></a>';

            if ($this->get_status('sticky')) {
                $menu .= '<a href="'.$this->get_link($this->current_thread, $this->links->topic).'&amp;unsticky_topic"><span class="dashicons-before dashicons-sticky"></span><span>'.__('Undo Sticky', 'asgaros-forum').'</span></a>';
            } else {
                $menu .= '<a href="'.$this->get_link($this->current_thread, $this->links->topic).'&amp;sticky_topic"><span class="dashicons-before dashicons-admin-post"></span><span>'.__('Sticky', 'asgaros-forum').'</span></a>';
            }

            if ($this->get_status('closed')) {
                $menu .= '<a href="'.$this->get_link($this->current_thread, $this->links->topic).'&amp;open_topic"><span class="dashicons-before dashicons-unlock"></span><span>'.__('Re-open', 'asgaros-forum').'</span></a>';
            } else {
                $menu .= '<a href="'.$this->get_link($this->current_thread, $this->links->topic).'&amp;close_topic"><span class="dashicons-before dashicons-lock"></span><span>'.__('Close', 'asgaros-forum').'</span></a>';
            }
        }

        return $menu;
    }

    function get_parent_id($id, $location, $value = 'parent_id') {
        return $this->db->get_var($this->db->prepare("SELECT {$value} FROM {$location} WHERE id = %d;", $id));
    }

    function breadcrumbs() {
        $trail = '<span class="dashicons-before dashicons-admin-home"></span><a href="'.$this->links->home.'">'.__('Forum', 'asgaros-forum').'</a>';

        if ($this->parent_forum && $this->parent_forum > 0) {
            $link = $this->get_link($this->parent_forum, $this->links->forum);
            $trail .= '&nbsp;<span class="sep">&rarr;</span>&nbsp;<a href="'.$link.'">'.esc_html(stripslashes($this->get_name($this->parent_forum, $this->table_forums))).'</a>';
        }

        if ($this->current_forum) {
            $link = $this->get_link($this->current_forum, $this->links->forum);
            $trail .= '&nbsp;<span class="sep">&rarr;</span>&nbsp;<a href="'.$link.'">'.esc_html(stripslashes($this->get_name($this->current_forum, $this->table_forums))).'</a>';
        }

        if ($this->current_thread) {
            $link = $this->get_link($this->current_thread, $this->links->topic);
            $name = stripslashes($this->get_name($this->current_thread, $this->table_threads));
            $trail .= '&nbsp;<span class="sep">&rarr;</span>&nbsp;<a href="'.$link.'" title="'.esc_html($name).'">'.esc_html($this->cut_string($name)).'</a>';
        }

        if ($this->current_view === 'addpost') {
            $trail .= '&nbsp;<span class="sep">&rarr;</span>&nbsp;' . __('Post Reply', 'asgaros-forum');
        } else if ($this->current_view === 'editpost') {
            $trail .= '&nbsp;<span class="sep">&rarr;</span>&nbsp;' . __('Edit Post', 'asgaros-forum');
        } else if ($this->current_view === 'addthread') {
            $trail .= '&nbsp;<span class="sep">&rarr;</span>&nbsp;' . __('New Thread', 'asgaros-forum');
        }

        return '<div class="breadcrumbs">'.$trail.'</div>';
    }

    function pageing($location) {
        $out = '<div class="pages">'.__('Pages:', 'asgaros-forum');
        $num_pages = 0;
        $select_source = '';
        $select_url = '';

        if ($location == $this->table_posts) {
            $count = $this->db->get_var($this->db->prepare("SELECT count(id) FROM {$location} WHERE parent_id = %d;", $this->current_thread));
            $num_pages = ceil($count / $this->options['posts_per_page']);
            $select_source = $this->current_thread;
            $select_url = $this->links->topic;
        } else if ($location == $this->table_threads) {
            $count = $this->db->get_var($this->db->prepare("SELECT count(id) FROM {$location} WHERE parent_id = %d AND status LIKE %s;", $this->current_forum, "normal%"));
            $num_pages = ceil($count / $this->options['threads_per_page']);
            $select_source = $this->current_forum;
            $select_url = $this->links->forum;
        }

        if ($num_pages > 1) {
            if ($num_pages <= 6) {
                for ($i = 0; $i < $num_pages; ++$i) {
                    if ($i == $this->current_page) {
                        $out .= ' <strong>'.($i + 1).'</strong>';
                    } else {
                        $out .= ' <a href="'.$this->get_link($select_source, $select_url, ($i + 1)).'">'.($i + 1).'</a>';
                    }
                }
            } else {
                if ($this->current_page >= 4) {
                    $out .= ' <a href="'.$this->get_link($select_source, $select_url).'">'.__('First', 'asgaros-forum').'</a> &laquo;';
                }

                for ($i = 3; $i > 0; $i--) {
                    if ((($this->current_page + 1) - $i) > 0) {
                        $out .= ' <a href="'.$this->get_link($select_source, $select_url, (($this->current_page + 1) - $i)).'">'.(($this->current_page + 1) - $i).'</a>';
                    }
                }

                $out .= ' <strong>'.($this->current_page + 1).'</strong>';

                for ($i = 1; $i <= 3; $i++) {
                    if ((($this->current_page + 1) + $i) <= $num_pages) {
                        $out .= ' <a href="'.$this->get_link($select_source, $select_url, (($this->current_page + 1) + $i)).'">'.(($this->current_page + 1) + $i).'</a>';
                    }
                }

                if ($num_pages - $this->current_page >= 5) {
                    $out .= ' &raquo; <a href="'.$this->get_link($select_source, $select_url, $num_pages).'">'.__('Last', 'asgaros-forum').'</a>';
                }
            }

            $out .= '</div>';
            return $out;
        } else {
            return '';
        }
    }

    function delete_thread($thread_id, $admin_action = false) {
        if (AsgarosForumPermissions::isModerator('current')) {
            if ($thread_id) {
                // Delete uploads
                $posts = $this->db->get_col($this->db->prepare("SELECT id FROM {$this->table_posts} WHERE parent_id = %d;", $thread_id));
                foreach ($posts as $post) {
                    AsgarosForumUploads::deletePostFiles($post);
                }

                $this->db->delete($this->table_posts, array('parent_id' => $thread_id), array('%d'));
                $this->db->delete($this->table_threads, array('id' => $thread_id), array('%d'));
                AsgarosForumNotifications::removeTopicSubscriptions($thread_id);

                if (!$admin_action) {
                    wp_redirect(html_entity_decode($this->links->forum . $this->current_forum));
                    exit;
                }
            }
        }
    }

    function move_thread() {
        $newForumID = $_POST['newForumID'];

        if (AsgarosForumPermissions::isModerator('current') && $newForumID && $this->element_exists($newForumID, $this->table_forums)) {
            $this->db->update($this->table_threads, array('parent_id' => $newForumID), array('id' => $this->current_thread), array('%d'), array('%d'));
            wp_redirect(html_entity_decode($this->links->topic . $this->current_thread));
            exit;
        }
    }

    function remove_post() {
        $post_id = (isset($_GET['post']) && is_numeric($_GET['post'])) ? absint($_GET['post']) : 0;

        if (AsgarosForumPermissions::isModerator('current') && $this->element_exists($post_id, $this->table_posts)) {
            $this->db->delete($this->table_posts, array('id' => $post_id), array('%d'));
            AsgarosForumUploads::deletePostFiles($post_id);
        }
    }

    // TODO: Optimize sql-query same as widget-query. (http://stackoverflow.com/a/28090544/4919483)
    function get_lastpost_in_thread($id) {
        if (empty($this->cache['get_lastpost_in_thread'][$id])) {
            $this->cache['get_lastpost_in_thread'][$id] = $this->db->get_row($this->db->prepare("SELECT p.id, p.date, p.author_id, p.parent_id FROM {$this->table_posts} AS p INNER JOIN {$this->table_threads} AS t ON p.parent_id = t.id WHERE p.parent_id = %d ORDER BY p.id DESC LIMIT 1;", $id));
        }

        return $this->cache['get_lastpost_in_thread'][$id];
    }

    // TODO: Optimize sql-query same as widget-query. (http://stackoverflow.com/a/28090544/4919483)
    function get_lastpost_in_forum($id) {
        if (empty($this->cache['get_lastpost_in_forum'][$id])) {
            return $this->db->get_row($this->db->prepare("SELECT p.id, p.date, p.parent_id, p.author_id, t.name FROM {$this->table_posts} AS p INNER JOIN {$this->table_threads} AS t ON p.parent_id = t.id INNER JOIN {$this->table_forums} AS f ON t.parent_id = f.id WHERE f.id = %d OR f.parent_forum = %d ORDER BY p.id DESC LIMIT 1;", $id, $id));
        }

        return $this->cache['get_lastpost_in_forum'][$id];
    }

    function change_status($property) {
        $new_status = '';

        if (AsgarosForumPermissions::isModerator('current')) {
            if ($property == 'sticky') {
                $new_status .= ($this->get_status('sticky')) ? 'normal_' : 'sticky_';
                $new_status .= ($this->get_status('closed')) ? 'closed' : 'open';
            } else if ($property == 'closed') {
                $new_status .= ($this->get_status('sticky')) ? 'sticky_' : 'normal_';
                $new_status .= ($this->get_status('closed')) ? 'open' : 'closed';
            }

            $this->db->update($this->table_threads, array('status' => $new_status), array('id' => $this->current_thread), array('%s'), array('%d'));

            // Update cache
            $this->cache['get_status'][$this->current_thread] = $new_status;
        }
    }

    function get_status($property) {
        if (empty($this->cache['get_status'][$this->current_thread])) {
            $this->cache['get_status'][$this->current_thread] = $this->db->get_var($this->db->prepare("SELECT status FROM {$this->table_threads} WHERE id = %d;", $this->current_thread));
        }

        $status = $this->cache['get_status'][$this->current_thread];

        if ($property == 'sticky' && ($status == 'sticky_open' || $status == 'sticky_closed')) {
            return true;
        } else if ($property == 'closed' && ($status == 'normal_closed' || $status == 'sticky_closed')) {
            return true;
        } else {
            return false;
        }
    }

    // Returns TRUE if the forum is opened or the user has at least moderator rights.
    function get_forum_status() {
        if (!AsgarosForumPermissions::isModerator('current')) {
            $closed = intval($this->db->get_var($this->db->prepare("SELECT closed FROM {$this->table_forums} WHERE id = %d;", $this->current_forum)));

            if ($closed === 1) {
                return false;
            }
        }

        return true;
    }
}

?>
