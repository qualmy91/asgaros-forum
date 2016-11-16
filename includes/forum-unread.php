<?php

if (!defined('ABSPATH')) exit;

class AsgarosForumUnread {
    private static $instance = null;
    private static $userID;
    private static $markAllReadLink;
    private static $excludedItems = array();

    // AsgarosForumUnread instance creator
    public static function createInstance() {
		if (self::$instance === null) {
			self::$instance = new self;
		}

        return self::$instance;
	}

    // AsgarosForumUnread constructor
	private function __construct() {
        add_action('wp', array($this, 'initUnreadSettings'));
    }

    public static function initUnreadSettings() {
        // Determine with the user ID if the user is logged in.
        self::$userID = get_current_user_id();

        // Generate link for mark all forums read.
        self::$markAllReadLink = esc_url(add_query_arg(array('view' => 'markallread'), esc_url(get_permalink())));

        // Initialize data. For guests we use a cookie as source, otherwise use database.
        if (self::$userID) {
            // Create database entry when it does not exist.
            if (!get_user_meta(self::$userID, 'asgarosforum_unread_cleared', true)) {
                add_user_meta(self::$userID, 'asgarosforum_unread_cleared', '0000-00-00 00:00:00');
            }

            // Get IDs of excluded topics.
            self::$excludedItems = get_user_meta(self::$userID, 'asgarosforum_unread_exclude', true);
        } else {
            // Create a cookie when it does not exist.
            if (!isset($_COOKIE['asgarosforum_unread_cleared'])) {
                // There is no cookie set so basically the forum has never been visited.
                setcookie('asgarosforum_unread_cleared', '0000-00-00 00:00:00', 2147483647);
            }

            // Get IDs of excluded topics.
            if (isset($_COOKIE['asgarosforum_unread_exclude'])) {
                self::$excludedItems = maybe_unserialize($_COOKIE['asgarosforum_unread_exclude']);
            }
        }
    }

    public static function markAllRead() {
        global $asgarosforum;

        // Get current time.
        $currentTime = $asgarosforum->current_time();

        if (self::$userID) {
            update_user_meta(self::$userID, 'asgarosforum_unread_cleared', $currentTime);
            delete_user_meta(self::$userID, 'asgarosforum_unread_exclude');
        } else {
            setcookie('asgarosforum_unread_cleared', $currentTime, 2147483647);
            unset($_COOKIE['asgarosforum_unread_exclude']);
        }

        // Redirect to the forum overview.
        wp_redirect(html_entity_decode($asgarosforum->links->home));
        exit;
    }

    // Marks a thread as read when open it.
    public static function markThreadRead() {
        global $asgarosforum;

        self::$excludedItems[$asgarosforum->current_thread] = intval($asgarosforum->get_lastpost_in_thread($asgarosforum->current_thread)->id);

        if (self::$userID) {
            update_user_meta(self::$userID, 'asgarosforum_unread_exclude', self::$excludedItems);
        } else {
            setcookie('asgarosforum_unread_exclude', maybe_serialize(self::$excludedItems), 2147483647);
        }
    }

    public static function getLastVisit() {
        if (self::$userID) {
            return get_user_meta(self::$userID, 'asgarosforum_unread_cleared', true);
        } else if (isset($_COOKIE['asgarosforum_unread_cleared'])) {
            return $_COOKIE['asgarosforum_unread_cleared'];
        } else {
            return "0000-00-00 00:00:00";
        }
    }

    public static function getStatus($lastpostData) {
        $status = ' read';

        if ($lastpostData) {
            $lastpostTime = $lastpostData->date;

            // TODO: Produces wrong results when -> forum with more than one unread threads -> post in one thread -> shows as read
            if ($lastpostTime) {
                $lp = strtotime($lastpostTime);
                $lv = strtotime(self::getLastVisit());

                if ($lp > $lv) {
                    $status = ' unread';
                }
            }
        }

        return $status;
    }

    public static function getStatusForum($id) {
        global $asgarosforum;
        $lastpostData = null;
        $lastpostList = null;

        // Only ignore posts from the loggedin user because we cant determine if a post from a guest was created by the visiting guest.
        if (self::$userID) {
            $sql = $asgarosforum->db->prepare("SELECT p.id, p.date, p.parent_id, p.author_id, t.name FROM {$asgarosforum->table_posts} AS p INNER JOIN {$asgarosforum->table_threads} AS t ON p.parent_id = t.id INNER JOIN {$asgarosforum->table_forums} AS f ON t.parent_id = f.id LEFT JOIN {$asgarosforum->table_posts} AS p2 ON p.parent_id = p2.parent_id AND p.id < p2.id WHERE p2.id IS NULL AND p.author_id <> %d AND (f.id = %d OR f.parent_forum = %d) AND p.date > '%s' ORDER BY p.id DESC;", self::$userID, $id, $id, self::getLastVisit());
            $lastpostList = $asgarosforum->db->get_results($sql);
        } else {
            $sql = $asgarosforum->db->prepare("SELECT p.id, p.date, p.parent_id, p.author_id, t.name FROM {$asgarosforum->table_posts} AS p INNER JOIN {$asgarosforum->table_threads} AS t ON p.parent_id = t.id INNER JOIN {$asgarosforum->table_forums} AS f ON t.parent_id = f.id LEFT JOIN {$asgarosforum->table_posts} AS p2 ON p.parent_id = p2.parent_id AND p.id < p2.id WHERE p2.id IS NULL AND (f.id = %d OR f.parent_forum = %d) AND p.date > '%s' ORDER BY p.id DESC;", $id, $id, self::getLastVisit());
            $lastpostList = $asgarosforum->db->get_results($sql);
        }

        foreach ($lastpostList as $key => $lastpostListItem) {
            // This topic has not been opened yet, so it is actually the last post.
            if (!isset(self::$excludedItems[$lastpostListItem->parent_id])) {
                $lastpostData = $lastpostListItem;
                break;
            }

            // This topic has been opened, but there is already a newer post, so it is actually the last post.
            if (isset(self::$excludedItems[$lastpostListItem->parent_id]) && $lastpostListItem->id > self::$excludedItems[$lastpostListItem->parent_id]) {
                $lastpostData = $lastpostListItem;
                break;
            }
        }

        return self::getStatus($lastpostData);
    }

    public static function getStatusThread($id) {
        global $asgarosforum;
        $lastpostData = $asgarosforum->get_lastpost_in_thread($id);

        // Set empty lastpostData for loggedin user when he is the author of the last post or when topic already read.
        if ((self::$userID && $lastpostData->author_id == self::$userID) || (isset(self::$excludedItems[$id]) && self::$excludedItems[$id] == $lastpostData->id)) {
            $lastpostData = null;
        }

        return self::getStatus($lastpostData);
    }

    public static function showUnreadControls() {
        echo '<div class="footer">';
            echo '<span class="dashicons-before dashicons-admin-page-small unread"></span>'.__('New posts', 'asgaros-forum').' &middot; ';
            echo '<span class="dashicons-before dashicons-admin-page-small read"></span>'.__('No new posts', 'asgaros-forum').' &middot; ';
            echo '<span class="dashicons-before dashicons-yes"></span><a href="'.self::$markAllReadLink.'">'.__('Mark All Read', 'asgaros-forum').'</a>';
        echo '</div>';
    }
}

?>
