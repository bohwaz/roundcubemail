<?php

/*
 * Redundant attachments
 *
 * This plugin provides a redundant storage for temporary uploaded
 * attachment files. They are stored in both the database backend
 * as well as on the local file system.
 *
 * It provides also memcache/redis store as a fallback (see config file).
 *
 * This plugin relies on the core filesystem_attachments plugin
 * and combines it with the functionality of the database_attachments plugin.
 *
 * @author Thomas Bruederli <roundcube@gmail.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) The Roundcube Dev Team
 * Copyright (C) Kolab Systems AG
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

require_once RCUBE_PLUGINS_DIR . 'filesystem_attachments/filesystem_attachments.php';

class redundant_attachments extends filesystem_attachments
{
    // A prefix for the cache key used in the session and in the key field of the cache table
    public const PREFIX = 'ATTACH';

    // rcube_cache instance for SQL DB
    private $cache;

    // rcube_cache instance for memcache/redis
    private $mem_cache;

    private $loaded;

    /**
     * Loads plugin configuration and initializes cache object(s)
     */
    private function _load_drivers()
    {
        if ($this->loaded) {
            return;
        }

        $rcmail = rcube::get_instance();

        // load configuration
        $this->load_config();

        $ttl      = 12 * 60 * 60; // 12 hours
        $ttl      = $rcmail->config->get('redundant_attachments_cache_ttl', $ttl);
        $fallback = $rcmail->config->get('redundant_attachments_fallback');
        $prefix   = self::PREFIX;

        if ($id = session_id()) {
            $prefix .= $id;
        }

        if ($fallback === null) {
            $fallback = $rcmail->config->get('redundant_attachments_memcache') ? 'memcache' : null; // BC
        }

        // Init SQL cache (disable cache data serialization)
        $this->cache = $rcmail->get_cache($prefix, 'db', $ttl, false, true);

        // Init memcache/redis (fallback) cache
        if ($fallback) {
            $this->mem_cache = $rcmail->get_cache($prefix, $fallback, $ttl, false, true);
        }

        $this->loaded = true;
    }

    /**
     * Helper method to generate a unique key for the given attachment file
     */
    private function _key($args)
    {
        $uname = !empty($args['path']) ? $args['path'] : $args['name'];

        return $args['group'] . md5(microtime() . $uname . $_SESSION['user_id']);
    }

    /**
     * Save a newly uploaded attachment
     */
    public function upload($args)
    {
        $args = parent::upload($args);

        $this->_load_drivers();

        $key  = $this->_key($args);
        $data = base64_encode(file_get_contents($args['path']));

        $status = $this->cache->set($key, $data);

        if (!$status && $this->mem_cache) {
            $status = $this->mem_cache->set($key, $data);
        }

        if ($status) {
            $args['id'] = $key;
            $args['status'] = true;
        }

        return $args;
    }

    /**
     * Save an attachment from a non-upload source (draft or forward)
     */
    public function save($args)
    {
        $args = parent::save($args);

        $this->_load_drivers();

        $data = !empty($args['path']) ? file_get_contents($args['path']) : $args['data'];

        $args['data'] = null;

        $key  = $this->_key($args);
        $data = base64_encode($data);

        $status = $this->cache->set($key, $data);

        if (!$status && $this->mem_cache) {
            $status = $this->mem_cache->set($key, $data);
        }

        if ($status) {
            $args['id'] = $key;
            $args['status'] = true;
        }

        return $args;
    }

    /**
     * Remove an attachment from storage
     * This is triggered by the remove attachment button on the compose screen
     */
    public function remove($args)
    {
        parent::remove($args);

        $this->_load_drivers();

        $status = $this->cache->remove($args['id']);

        if (!$status && $this->mem_cache) {
            $status = $this->cache->remove($args['id']);
        }

        // we cannot trust the result of any of the methods above
        // assume true, attachments will be removed on cleanup
        $args['status'] = true;

        return $args;
    }

    /**
     * When composing an html message, image attachments may be shown
     * For this plugin, $this->get() will check the file and
     * return it's contents
     */
    public function display($args)
    {
        return $this->get($args);
    }

    /**
     * When displaying or sending the attachment the file contents are fetched
     * using this method. This is also called by the attachment_display hook.
     */
    public function get($args)
    {
        // attempt to get file from local file system
        $args = parent::get($args);

        if (!empty($args['path']) && ($args['status'] = file_exists($args['path']))) {
            return $args;
        }

        $this->_load_drivers();

        // fetch from database if not found on FS
        $data = $this->cache->get($args['id']);

        // fetch from memcache if not found on FS and DB
        if (($data === false || $data === null) && $this->mem_cache) {
            $data = $this->mem_cache->get($args['id']);
        }

        if ($data) {
            $args['data'] = base64_decode($data);
            $args['status'] = true;
        }

        return $args;
    }

    /**
     * Delete all temp files associated with this user
     */
    public function cleanup($args)
    {
        $this->_load_drivers();

        $group = $args['group'] ?? null;

        if ($this->cache) {
            $this->cache->remove($group, true);
        }

        if ($this->mem_cache) {
            $this->mem_cache->remove($group, true);
        }

        parent::cleanup($args);

        $args['status'] = true;

        return $args;
    }
}
