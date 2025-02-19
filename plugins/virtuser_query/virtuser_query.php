<?php

/**
 * DB based User-to-Email and Email-to-User lookup
 *
 * Add it to the plugins list in config.inc.php and set
 * SQL queries to resolve usernames, e-mail addresses and hostnames from the database
 * %u will be replaced with the current username for login.
 * %m will be replaced with the current e-mail address for login.
 *
 * Queries should select the user's e-mail address, username or the imap hostname as first column
 * The email query could optionally select identity data columns in specified order:
 *    name, organization, reply-to, bcc, signature, html_signature
 *
 * $config['virtuser_query'] = ['email' => '', 'user' => '', 'host' => '', 'alias' => ''];
 *
 * The email query can return more than one record to create more identities.
 * This requires identities_level option to be set to value less than 2.
 *
 * By default Roundcube database is used. To use different database (or host)
 * you can specify DSN string in $config['virtuser_query_dsn'] option.
 *
 * @author Aleksander Machniak <alec@alec.pl>
 * @author Steffen Vogel
 * @author Tim Gerundt
 * @license GNU GPLv3+
 */
class virtuser_query extends rcube_plugin
{
    private $config;
    private $app;
    private $db;

    public function init()
    {
        $this->app    = rcmail::get_instance();
        $this->config = $this->app->config->get('virtuser_query');

        if (!empty($this->config)) {
            if (is_string($this->config)) {
                $this->config = ['email' => $this->config];
            }

            if (!empty($this->config['email'])) {
                $this->add_hook('user2email', [$this, 'user2email']);
            }
            if (!empty($this->config['user'])) {
                $this->add_hook('email2user', [$this, 'email2user']);
            }
            if (!empty($this->config['host'])) {
                $this->add_hook('authenticate', [$this, 'user2host']);
            }
            if (!empty($this->config['alias'])) {
                $this->add_hook('authenticate', [$this, 'alias2user']);
            }
        }
    }

    /**
     * User > Email
     */
    public function user2email($p)
    {
        $dbh = $this->get_dbh();

        $sql_result = $dbh->query(preg_replace('/%u/', $dbh->escape($p['user']), $this->config['email']));
        $result     = [];

        while ($sql_arr = $dbh->fetch_array($sql_result)) {
            if (strpos($sql_arr[0], '@')) {
                if (!empty($p['extended']) && count($sql_arr) > 1) {
                    $result[] = [
                        'email'          => rcube_utils::idn_to_ascii($sql_arr[0]),
                        'name'           => $sql_arr[1] ?? '',
                        'organization'   => $sql_arr[2] ?? '',
                        'reply-to'       => isset($sql_arr[3]) ? rcube_utils::idn_to_ascii($sql_arr[3]) : '',
                        'bcc'            => isset($sql_arr[4]) ? rcube_utils::idn_to_ascii($sql_arr[4]) : '',
                        'signature'      => $sql_arr[5] ?? '',
                        'html_signature' => isset($sql_arr[6]) ? intval($sql_arr[6]) : 0,
                    ];
                } else {
                    $result[] = $sql_arr[0];
                }

                if (!empty($p['first'])) {
                    break;
                }
            }
        }

        $p['email'] = $result;

        return $p;
    }

    /**
     * EMail > User
     */
    public function email2user($p)
    {
        $dbh = $this->get_dbh();

        $sql_result = $dbh->query(preg_replace('/%m/', $dbh->escape($p['email']), $this->config['user']));

        if ($sql_arr = $dbh->fetch_array($sql_result)) {
            $p['user'] = $sql_arr[0];
        }

        return $p;
    }

    /**
     * User > Host
     */
    public function user2host($p)
    {
        $dbh = $this->get_dbh();

        $sql_result = $dbh->query(preg_replace('/%u/', $dbh->escape($p['user']), $this->config['host']));

        if ($sql_arr = $dbh->fetch_array($sql_result)) {
            $p['host'] = $sql_arr[0];
        }

        return $p;
    }

    /**
     * Alias > User
     */
    public function alias2user($p)
    {
        $dbh = $this->get_dbh();

        $sql_result = $dbh->query(preg_replace('/%u/', $dbh->escape($p['user']), $this->config['alias']));

        if ($sql_arr = $dbh->fetch_array($sql_result)) {
            $p['user'] = $sql_arr[0];
        }

        return $p;
    }

    /**
     * Initialize database handler
     */
    public function get_dbh()
    {
        if (!$this->db) {
            if ($dsn = $this->app->config->get('virtuser_query_dsn')) {
                // connect to the virtuser database
                $this->db = rcube_db::factory($dsn);
                $this->db->set_debug((bool) $this->app->config->get('sql_debug'));
                $this->db->db_connect('r'); // connect in read mode
            } else {
                $this->db = $this->app->get_dbh();
            }
        }

        return $this->db;
    }
}
