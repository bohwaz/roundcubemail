<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Class representing the client browser's properties                  |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

/**
 * Provide details about the client's browser based on the User-Agent header
 */
class rcube_browser
{
    /** @var float Browser version */
    public $ver = 0;

    /** @var bool Browser OS is Windows */
    public $win = false;

    /** @var bool Browser OS is Mac */
    public $mac = false;

    /** @var bool Browser OS is Linux */
    public $linux = false;

    /** @var bool Browser OS is Unix */
    public $unix = false;

    /** @var bool Browser uses WebKit engine */
    public $webkit = false;

    /** @var bool Browser is Opera */
    public $opera = false;

    /** @var bool Browser is Chrome */
    public $chrome = false;

    /** @var bool Browser is Internet Explorer */
    public $ie = false;

    /** @var bool Browser is Edge */
    public $edge = false;

    /** @var bool Browser is Safari */
    public $safari = false;

    /** @var bool Browser is Mozilla Firefox */
    public $mz = false;

    /**
     * Object constructor
     */
    public function __construct()
    {
        $HTTP_USER_AGENT = !empty($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';

        // Operating system detection
        $this->win   = strpos($HTTP_USER_AGENT, 'win') != false;
        $this->mac   = strpos($HTTP_USER_AGENT, 'mac') != false;
        $this->linux = strpos($HTTP_USER_AGENT, 'linux') != false;
        $this->unix  = strpos($HTTP_USER_AGENT, 'unix') != false;

        // Engine detection
        $this->webkit = strpos($HTTP_USER_AGENT, 'applewebkit') !== false;
        $this->opera  = strpos($HTTP_USER_AGENT, 'opera') !== false || ($this->webkit && strpos($HTTP_USER_AGENT, 'opr/') !== false);
        $this->edge   = strpos($HTTP_USER_AGENT, 'edge/') !== false;
        $this->ie     = !$this->opera && !$this->edge && (strpos($HTTP_USER_AGENT, 'compatible; msie') !== false || strpos($HTTP_USER_AGENT, 'trident/') !== false);
        $this->chrome = !$this->opera && !$this->edge && strpos($HTTP_USER_AGENT, 'chrome') !== false;
        $this->safari = !$this->opera && !$this->chrome && !$this->edge
                        && ($this->webkit || strpos($HTTP_USER_AGENT, 'safari') !== false);
        $this->mz     = !$this->ie && !$this->edge && !$this->safari && !$this->chrome && !$this->opera
                        && strpos($HTTP_USER_AGENT, 'mozilla') !== false;

        // Version detection
        if ($this->edge && preg_match('/edge\/([0-9.]+)/', $HTTP_USER_AGENT, $regs)) {
            $this->ver = (float) $regs[1];
        } elseif ($this->opera && preg_match('/(opera|opr)(\s*|\/)([0-9.]+)/', $HTTP_USER_AGENT, $regs)) {
            $this->ver = (float) $regs[3];
        } elseif ($this->safari && preg_match('/(version|safari)\/([0-9.]+)/', $HTTP_USER_AGENT, $regs)) {
            $this->ver = (float) $regs[1];
        } elseif (preg_match('/(chrome|khtml|version|msie|rv:)(\s*|\/)([0-9.]+)/', $HTTP_USER_AGENT, $regs)) {
            $this->ver = (float) $regs[3];
        }
    }
}
