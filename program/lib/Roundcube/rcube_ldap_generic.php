<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 | Copyright (C) Kolab Systems AG                                        |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide basic functionality for accessing LDAP directories          |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 |         Aleksander Machniak <machniak@kolabsys.com>                   |
 +-----------------------------------------------------------------------+
*/

/**
 * Model class to access an LDAP directories
 */
class rcube_ldap_generic extends Net_LDAP3
{
    /** private properties */
    protected $cache;
    protected $attributes = ['dn'];
    protected $error;

    /**
     * Class constructor
     *
     * @param array $config Configuration
     */
    public function __construct($config = null)
    {
        parent::__construct($config);

        $this->config_set('log_hook', [$this, 'log']);
    }

    /**
     * Establish a connection to the LDAP server
     */
    public function connect($host = null)
    {
        // Net_LDAP3 does not support IDNA yet
        // also parse_host() here is very Roundcube specific
        $host = rcube_utils::parse_host($host, $this->config['mail_domain']);
        $host = rcube_utils::idn_to_ascii($host);

        return parent::connect($host);
    }

    /**
     * Prints debug/error info to the log
     */
    public function log($level, $msg)
    {
        $msg = implode("\n", $msg);

        switch ($level) {
            case \LOG_DEBUG:
            case \LOG_INFO:
            case \LOG_NOTICE:
                if (!empty($this->config['debug'])) {
                    rcube::write_log('ldap', $msg);
                }

                break;
            case \LOG_EMERG:
            case \LOG_ALERT:
            case \LOG_CRIT:
                rcube::raise_error($msg, true, true);
                break;
            case \LOG_ERR:
            case \LOG_WARNING:
                $this->error = $msg;
                rcube::raise_error($msg, true, false);
                break;
        }
    }

    /**
     * Returns the last LDAP error occurred
     *
     * @return mixed Error message string or null if no error occurred
     */
    public function get_error()
    {
        return $this->error;
    }

    /**
     * @deprecated
     */
    public function set_debug($dbg = true)
    {
        $this->config['debug'] = (bool) $dbg;
    }

    /**
     * @deprecated
     */
    public function set_cache($cache_engine)
    {
        $this->config['cache'] = $cache_engine;
    }

    /**
     * @deprecated
     */
    public static function scope2func($scope, &$ns_function = null)
    {
        return self::scope_to_function($scope, $ns_function);
    }

    /**
     * @deprecated
     */
    public function set_config($opt, $val = null)
    {
        $this->config_set($opt, $val);
    }

    /**
     * @deprecated
     */
    public function add($dn, $entry)
    {
        return $this->add_entry($dn, $entry);
    }

    /**
     * @deprecated
     */
    public function delete($dn)
    {
        return $this->delete_entry($dn);
    }

    /**
     * Wrapper for ldap_mod_replace()
     *
     * @see ldap_mod_replace()
     */
    public function mod_replace($dn, $entry)
    {
        $this->_debug("C: Replace {$dn}: " . print_r($entry, true));

        if (!ldap_mod_replace($this->conn, $dn, $entry)) {
            $this->_error('ldap_mod_replace() failed with ' . ldap_error($this->conn));
            return false;
        }

        $this->_debug('S: OK');
        return true;
    }

    /**
     * Wrapper for ldap_mod_add()
     *
     * @see ldap_mod_add()
     */
    public function mod_add($dn, $entry)
    {
        $this->_debug("C: Add {$dn}: " . print_r($entry, true));

        if (!ldap_mod_add($this->conn, $dn, $entry)) {
            $this->_error('ldap_mod_add() failed with ' . ldap_error($this->conn));
            return false;
        }

        $this->_debug('S: OK');
        return true;
    }

    /**
     * Wrapper for ldap_mod_del()
     *
     * @see ldap_mod_del()
     */
    public function mod_del($dn, $entry)
    {
        $this->_debug("C: Delete {$dn}: " . print_r($entry, true));

        if (!ldap_mod_del($this->conn, $dn, $entry)) {
            $this->_error('ldap_mod_del() failed with ' . ldap_error($this->conn));
            return false;
        }

        $this->_debug('S: OK');
        return true;
    }

    /**
     * Wrapper for ldap_rename()
     *
     * @see ldap_rename()
     */
    public function rename($dn, $newrdn, $newparent = null, $deleteoldrdn = true)
    {
        $this->_debug("C: Rename {$dn} to {$newrdn}");

        if (!ldap_rename($this->conn, $dn, $newrdn, $newparent, $deleteoldrdn)) {
            $this->_error('ldap_rename() failed with ' . ldap_error($this->conn));
            return false;
        }

        $this->_debug('S: OK');
        return true;
    }

    /**
     * Wrapper for ldap_list() + ldap_get_entries()
     *
     * @see ldap_list()
     * @see ldap_get_entries()
     */
    public function list_entries($dn, $filter, $attributes = ['dn'])
    {
        $this->_debug("C: List {$dn} [{$filter}]");

        if ($result = ldap_list($this->conn, $dn, $filter, $attributes)) {
            $list = ldap_get_entries($this->conn, $result);

            if ($list === false) {
                $this->_error('ldap_get_entries() failed with ' . ldap_error($this->conn));
                return [];
            }

            $count = $list['count'];
            unset($list['count']);

            $this->_debug("S: {$count} record(s)");
        } else {
            $list = [];
            $this->_error('ldap_list() failed with ' . ldap_error($this->conn));
        }

        return $list;
    }

    /**
     * Wrapper for ldap_read() + ldap_get_entries()
     *
     * @see ldap_read()
     * @see ldap_get_entries()
     */
    public function read_entries($dn, $filter, $attributes = null)
    {
        $this->_debug("C: Read {$dn} [{$filter}]");

        if ($this->conn && $dn) {
            $result = @ldap_read($this->conn, $dn, $filter, $attributes, 0, (int) $this->config['sizelimit'], (int) $this->config['timelimit']);
            if ($result === false) {
                $this->_error('ldap_read() failed with ' . ldap_error($this->conn));
                return false;
            }

            $this->_debug('S: OK');
            return ldap_get_entries($this->conn, $result);
        }

        return false;
    }

    /**
     * Turn an LDAP entry into a regular PHP array with attributes as keys.
     *
     * @param array $entry Attributes array as retrieved from ldap_get_attributes() or ldap_get_entries()
     * @param bool  $flat  Convert one-element-array values into strings (not implemented)
     *
     * @return array Hash array with attributes as keys
     */
    public static function normalize_entry($entry, $flat = false)
    {
        if (!isset($entry['count'])) {
            return $entry;
        }

        $rec = [];

        for ($i = 0; $i < $entry['count']; $i++) {
            $attr = $entry[$i];
            if ($entry[$attr]['count'] == 1) {
                switch ($attr) {
                    case 'objectclass':
                        $rec[$attr] = [strtolower($entry[$attr][0])];
                        break;
                    default:
                        $rec[$attr] = $entry[$attr][0];
                        break;
                }
            } else {
                for ($j = 0; $j < $entry[$attr]['count']; $j++) {
                    $rec[$attr][$j] = $entry[$attr][$j];
                }
            }
        }

        return $rec;
    }

    /**
     * Compose an LDAP filter string matching all words from the search string
     * in the given list of attributes.
     *
     * @param string $value      Search value
     * @param mixed  $attributes List of LDAP attributes to search
     * @param int    $mode       Matching mode:
     *                           0 - partial (*abc*),
     *                           1 - strict (=),
     *                           2 - prefix (abc*)
     *
     * @return string LDAP filter
     */
    public static function fulltext_search_filter($value, $attributes, $mode = 1)
    {
        if (empty($attributes)) {
            $attributes = ['cn'];
        }

        $groups = [];
        $value  = str_replace('*', '', $value);
        $words  = $mode == 0 ? rcube_utils::tokenize_string($value, 1) : [$value];

        // set wildcards
        $wp = $ws = '';
        if ($mode != 1) {
            $ws = '*';
            $wp = !$mode ? '*' : '';
        }

        // search each word in all listed attributes
        foreach ($words as $word) {
            $parts = [];

            foreach ($attributes as $attr) {
                $parts[] = "({$attr}={$wp}" . self::quote_string($word) . "{$ws})";
            }

            $groups[] = '(|' . implode('', $parts) . ')';
        }

        return count($groups) > 1 ? '(&' . implode('', $groups) . ')' : implode('', $groups);
    }
}
