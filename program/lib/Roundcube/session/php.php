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
 |   Provide database supported session management                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 | Author: Cor Bosman <cor@roundcu.be>                                   |
 +-----------------------------------------------------------------------+
*/

/**
 * Class to provide native php session storage
 */
class rcube_session_php extends rcube_session
{
    /**
     * Native php sessions don't need a save handler.
     * We do need to define abstract function implementations but they are not used.
     */
    public function open($save_path, $session_name)
    {
        return true;
    }

    public function close()
    {
        return true;
    }

    public function destroy($key)
    {
        return true;
    }

    public function read($key)
    {
        return '';
    }

    protected function save($key, $vars) {}

    protected function update($key, $newvars, $oldvars) {}

    /**
     * Object constructor
     *
     * @param rcube_config $config Configuration
     */
    public function __construct($config)
    {
        parent::__construct($config);
    }

    /**
     * Wrapper for session_write_close()
     */
    public function write_close()
    {
        $_SESSION['__IP'] = $this->ip;
        $_SESSION['__MTIME'] = time();

        parent::write_close();
    }

    /**
     * Wrapper for session_start()
     */
    public function start()
    {
        parent::start();

        $this->key     = session_id();
        $this->ip      = $_SESSION['__IP'] ?? null;
        $this->changed = $_SESSION['__MTIME'] ?? null;
    }
}
