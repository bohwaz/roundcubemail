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
 |   Invoke the configured or default spell checking engine.             |
 +-----------------------------------------------------------------------+
 | Author: Kris Steinhoff <steinhof@umich.edu>                           |
 +-----------------------------------------------------------------------+
*/

class rcmail_action_utils_spell extends rcmail_action
{
    // only process ajax requests
    protected static $mode = self::MODE_AJAX;

    /**
     * Request handler.
     *
     * @param array $args Arguments from the previous step(s)
     */
    public function run($args = [])
    {
        // read input
        $lang = rcube_utils::get_input_string('lang', rcube_utils::INPUT_GET);
        $data = file_get_contents('php://input');

        $learn_word = strpos($data, '<learnword>');

        // Get data string
        $left  = strpos($data, '<text>');
        $right = strrpos($data, '</text>');
        $data  = substr($data, $left + 6, $right - ($left + 6));
        $data  = html_entity_decode($data, \ENT_QUOTES, RCUBE_CHARSET);

        $spellchecker = new rcube_spellchecker($lang);

        if ($learn_word) {
            $spellchecker->add_word($data);
            $result = '<?xml version="1.0" encoding="' . RCUBE_CHARSET . '"?><learnwordresult></learnwordresult>';
        } elseif (empty($data)) {
            $result = '<?xml version="1.0" encoding="' . RCUBE_CHARSET . '"?><spellresult charschecked="0"></spellresult>';
        } else {
            $spellchecker->check($data);
            $result = $spellchecker->get_xml();
        }

        if ($error = $spellchecker->error()) {
            rcube::raise_error([
                'code' => 500,
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => 'Spellcheck error: ' . $error,
            ], true, false);

            http_response_code(500);
            exit;
        }

        // set response length
        header('Content-Length: ' . strlen($result));

        // Don't use server's default Content-Type charset (#1486406)
        header('Content-Type: text/xml; charset=' . RCUBE_CHARSET);
        echo $result;
        exit;
    }
}
