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
 |   Spellchecking using different backends                              |
 +-----------------------------------------------------------------------+
 | Author: Aleksander Machniak <machniak@kolabsys.com>                   |
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

/**
 * Helper class for spellchecking with GoogieSpell and PSpell support.
 */
class rcube_spellchecker
{
    private $matches = [];
    private $options = [];
    private $content;
    private $engine;
    private $backend;
    private $lang;
    private $rc;
    private $error;
    private $dict;
    private $have_dict;

    /**
     * Constructor
     *
     * @param string $lang Language code
     */
    public function __construct($lang = 'en')
    {
        $this->rc     = rcube::get_instance();
        $this->engine = $this->rc->config->get('spellcheck_engine', 'googie');
        $this->lang   = $lang ?: 'en';

        $this->options = [
            'ignore_syms' => $this->rc->config->get('spellcheck_ignore_syms'),
            'ignore_nums' => $this->rc->config->get('spellcheck_ignore_nums'),
            'ignore_caps' => $this->rc->config->get('spellcheck_ignore_caps'),
            'dictionary'  => $this->rc->config->get('spellcheck_dictionary'),
        ];

        $class = 'rcube_spellchecker_' . $this->engine;

        if (class_exists($class)) {
            $this->backend = new $class($this, $this->lang, $this->options);
        } else {
            $this->error = "Unknown spellcheck engine '{$this->engine}'";
        }
    }

    /**
     * Return a list of supported languages
     */
    public function languages()
    {
        // trust configuration
        $configured = $this->rc->config->get('spellcheck_languages');
        if (!empty($configured) && is_array($configured) && empty($configured[0])) {
            return $configured;
        }

        $langs = [];
        if (!empty($configured)) {
            $langs = (array) $configured;
        } elseif ($this->backend) {
            $langs = $this->backend->languages();
        }

        // load index
        $rcube_languages        = [];
        $rcube_language_aliases = [];

        @include RCUBE_LOCALIZATION_DIR . 'index.inc';

        // add correct labels
        $languages = [];
        foreach ($langs as $lang) {
            $langc = strtolower(substr($lang, 0, 2));
            $alias = !empty($rcube_language_aliases[$langc]) ? $rcube_language_aliases[$langc] : null;

            if (!$alias) {
                $alias = $langc . '_' . strtoupper($langc);
            }
            if (!empty($rcube_languages[$lang])) {
                $languages[$lang] = $rcube_languages[$lang];
            } elseif (preg_match('/^en_([A-Z]+)/', $lang, $m)) {
                $languages[$lang] = sprintf('English (%s)', strtoupper($m[1]));
            } elseif (!empty($rcube_languages[$alias])) {
                $languages[$lang] = $rcube_languages[$alias];
            } else {
                $languages[$lang] = ucfirst($lang);
            }
        }

        // remove possible duplicates (#1489395)
        $languages = array_unique($languages);

        asort($languages);

        return $languages;
    }

    /**
     * Set content and check spelling
     *
     * @param string $text    Text content for spellchecking
     * @param bool   $is_html Enables HTML-to-Text conversion
     *
     * @return bool True when no misspelling found, otherwise false
     */
    public function check($text, $is_html = false)
    {
        // convert to plain text
        if ($is_html) {
            $this->content = $this->html2text($text);
        } else {
            $this->content = $text;
        }

        // ignore links (#8527)
        $callback = static function ($matches) {
            // replace the link with a dummy string that has the same length
            // we can't just remove the link
            return str_repeat(' ', strlen($matches[0]));
        };

        $this->content = preg_replace_callback('~(^|\s)(www.\S+|[a-z]+://\S+)~', $callback, $this->content);

        if ($this->backend) {
            $this->matches = $this->backend->check($this->content);
        }

        return $this->found() == 0;
    }

    /**
     * Number of misspellings found (after check)
     *
     * @return int Number of misspellings
     */
    public function found()
    {
        return count($this->matches);
    }

    /**
     * Returns suggestions for the specified word
     *
     * @param string $word The word
     *
     * @return array Suggestions list
     */
    public function get_suggestions($word)
    {
        if ($this->backend) {
            return $this->backend->get_suggestions($word);
        }

        return [];
    }

    /**
     * Returns misspelled words
     *
     * @param string $text The content for spellchecking. If empty content
     *                     used for check() method will be used.
     *
     * @return array List of misspelled words
     */
    public function get_words($text = null, $is_html = false)
    {
        if ($is_html) {
            $text = $this->html2text($text);
        }

        if ($this->backend) {
            return $this->backend->get_words($text);
        }

        return [];
    }

    /**
     * Returns checking result in XML (Googiespell) format
     *
     * @return string XML content
     */
    public function get_xml()
    {
        // send output
        $out = '<?xml version="1.0" encoding="' . RCUBE_CHARSET . '"?><spellresult charschecked="' . mb_strlen($this->content) . '">';

        foreach ((array) $this->matches as $item) {
            $out .= '<c o="' . $item[1] . '" l="' . $item[2] . '">';
            $out .= is_array($item[4]) ? implode("\t", $item[4]) : $item[4];
            $out .= '</c>';
        }

        $out .= '</spellresult>';

        return $out;
    }

    /**
     * Returns checking result (misspelled words with suggestions)
     *
     * @return array Spellchecking result. An array indexed by word.
     */
    public function get()
    {
        $result = [];

        foreach ((array) $this->matches as $item) {
            if ($this->engine == 'pspell') {
                $word = $item[0];
            } else {
                $word = mb_substr($this->content, $item[1], $item[2], RCUBE_CHARSET);
            }

            if (is_array($item[4])) {
                $suggestions = $item[4];
            } elseif (empty($item[4])) {
                $suggestions = [];
            } else {
                $suggestions = explode("\t", $item[4]);
            }

            $result[$word] = $suggestions;
        }

        return $result;
    }

    /**
     * Returns error message
     *
     * @return string Error message
     */
    public function error()
    {
        return $this->error ?: ($this->backend ? $this->backend->error() : false);
    }

    private function html2text($text)
    {
        $h2t = new rcube_html2text($text, false, false, 0);
        return $h2t->get_text();
    }

    /**
     * Check if the specified word is an exception according to the spellcheck options.
     *
     * @param string $word The word
     *
     * @return bool True if the word is an exception, False otherwise
     */
    public function is_exception($word)
    {
        // Contain only symbols (e.g. "+9,0", "2:2")
        if (!$word || preg_match('/^[0-9@#$%^&_+~*<>=:;?!,.-]+$/', $word)) {
            return true;
        }

        // Contain symbols (e.g. "g@@gle"), all symbols excluding separators
        if (!empty($this->options['ignore_syms']) && preg_match('/[@#$%^&_+~*=-]/', $word)) {
            return true;
        }

        // Contain numbers (e.g. "g00g13")
        if (!empty($this->options['ignore_nums']) && preg_match('/[0-9]/', $word)) {
            return true;
        }

        // Blocked caps (e.g. "GOOGLE")
        if (!empty($this->options['ignore_caps']) && $word == mb_strtoupper($word)) {
            return true;
        }

        // Use exceptions from dictionary
        if (!empty($this->options['dictionary'])) {
            $this->load_dict();

            // @TODO: should dictionary be case-insensitive?
            if (!empty($this->dict) && in_array($word, $this->dict)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a word to dictionary
     *
     * @param string $word The word to add
     */
    public function add_word($word)
    {
        $this->load_dict();

        foreach (explode(' ', $word) as $word) {
            // sanity check
            if (strlen($word) < 512) {
                $this->dict[] = $word;
                $valid = true;
            }
        }

        if (!empty($valid)) {
            $this->dict = array_unique($this->dict);
            $this->update_dict();
        }
    }

    /**
     * Remove a word from dictionary
     *
     * @param string $word The word to remove
     */
    public function remove_word($word)
    {
        $this->load_dict();

        if (($key = array_search($word, $this->dict)) !== false) {
            unset($this->dict[$key]);
            $this->update_dict();
        }
    }

    /**
     * Update dictionary row in DB
     */
    private function update_dict()
    {
        $userid = null;
        if (strcasecmp($this->options['dictionary'], 'shared') != 0) {
            $userid = $this->rc->get_user_id();
        }

        $plugin = $this->rc->plugins->exec_hook('spell_dictionary_save', [
            'userid' => $userid, 'language' => $this->lang, 'dictionary' => $this->dict]);

        if (!empty($plugin['abort'])) {
            return;
        }

        if ($this->have_dict) {
            if (!empty($this->dict)) {
                $this->rc->db->query(
                    'UPDATE ' . $this->rc->db->table_name('dictionary', true)
                    . ' SET `data` = ?'
                    . ' WHERE `user_id` ' . ($plugin['userid'] ? '= ' . $this->rc->db->quote($plugin['userid']) : 'IS NULL')
                        . ' AND `language` = ?',
                    implode(' ', $plugin['dictionary']), $plugin['language']);
            }
            // don't store empty dict
            else {
                $this->rc->db->query(
                    'DELETE FROM ' . $this->rc->db->table_name('dictionary', true)
                    . ' WHERE `user_id` ' . ($plugin['userid'] ? '= ' . $this->rc->db->quote($plugin['userid']) : 'IS NULL')
                        . ' AND `language` = ?',
                    $plugin['language']);
            }
        } elseif (!empty($this->dict)) {
            $this->rc->db->query(
                'INSERT INTO ' . $this->rc->db->table_name('dictionary', true)
                . ' (`user_id`, `language`, `data`) VALUES (?, ?, ?)',
                $plugin['userid'], $plugin['language'], implode(' ', $plugin['dictionary']));
        }
    }

    /**
     * Get dictionary from DB
     */
    private function load_dict()
    {
        if (is_array($this->dict)) {
            return $this->dict;
        }

        $userid = null;
        if (strcasecmp($this->options['dictionary'], 'shared') != 0) {
            $userid = $this->rc->get_user_id();
        }

        $plugin = $this->rc->plugins->exec_hook('spell_dictionary_get', [
            'userid' => $userid, 'language' => $this->lang, 'dictionary' => []]);

        if (empty($plugin['abort'])) {
            $dict = [];
            $sql_result = $this->rc->db->query(
                'SELECT `data` FROM ' . $this->rc->db->table_name('dictionary', true)
                . ' WHERE `user_id` ' . ($plugin['userid'] ? '= ' . $this->rc->db->quote($plugin['userid']) : 'IS NULL')
                    . ' AND `language` = ?',
                $plugin['language']);

            if ($sql_arr = $this->rc->db->fetch_assoc($sql_result)) {
                $this->have_dict = true;
                if (!empty($sql_arr['data'])) {
                    $dict = explode(' ', $sql_arr['data']);
                }
            }

            $plugin['dictionary'] = array_merge((array) $plugin['dictionary'], $dict);
        }

        if (!empty($plugin['dictionary']) && is_array($plugin['dictionary'])) {
            $this->dict = $plugin['dictionary'];
        } else {
            $this->dict = [];
        }

        return $this->dict;
    }
}
