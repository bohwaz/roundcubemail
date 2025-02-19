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
 |   SORT/SEARCH/ESEARCH response handler                                |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

/**
 * Class holding a set of rcube_result_index instances that together form a
 * result set of a multi-folder search
 */
class rcube_result_multifolder
{
    public $multi      = true;
    public $sets       = [];
    public $incomplete = false;
    public $folder;

    protected $meta    = [];
    protected $index   = [];
    protected $folders = [];
    protected $sdata   = [];
    protected $order   = 'ASC';
    protected $sorting;

    /**
     * Object constructor.
     *
     * @param array $folders List of IMAP folders
     */
    public function __construct($folders = [])
    {
        $this->folders = $folders;
        $this->meta    = ['count' => 0];
    }

    /**
     * Initializes object with SORT command response
     *
     * @param rcube_result_index|rcube_result_thread $result Search result
     */
    public function add($result)
    {
        $this->sets[] = $result;

        if ($result->count()) {
            $this->append_result($result);
        } elseif ($result->incomplete) {
            $this->incomplete = true;
        }
    }

    /**
     * Append message UIDs from the given result to our index
     *
     * @param rcube_result_index|rcube_result_thread $result Search result
     */
    protected function append_result($result)
    {
        $this->meta['count'] += $result->count();

        // append UIDs to global index
        $folder = $result->get_parameters('MAILBOX');
        $index  = array_map(static function ($uid) use ($folder) { return $uid . '-' . $folder; }, $result->get());

        $this->index = array_merge($this->index, $index);
    }

    /**
     * Store a global index of (sorted) message UIDs
     *
     * @param rcube_message_header[] $headers    Messages in the index
     * @param string                 $sort_field Header field to sort by
     * @param string                 $sort_order Sort order
     */
    public function set_message_index($headers, $sort_field, $sort_order)
    {
        $this->sorting = $sort_field;
        $this->order   = $sort_order;
        $this->index   = [];

        foreach ($headers as $header) {
            $this->index[] = $header->uid . '-' . $header->folder;
        }
    }

    /**
     * Checks the result from IMAP command
     *
     * @return bool True if the result is an error, False otherwise
     */
    public function is_error()
    {
        return false;
    }

    /**
     * Checks if the result is empty
     *
     * @return bool True if the result is empty, False otherwise
     */
    public function is_empty()
    {
        return empty($this->sets) || $this->meta['count'] == 0;
    }

    /**
     * Returns number of elements in the result
     *
     * @return int Number of elements
     */
    public function count()
    {
        return $this->meta['count'];
    }

    /**
     * Returns number of elements in the result.
     * Alias for count() for compatibility with rcube_result_thread
     *
     * @return int Number of elements
     */
    public function count_messages()
    {
        return $this->count();
    }

    /**
     * Reverts order of elements in the result
     */
    public function revert()
    {
        $this->order = $this->order == 'ASC' ? 'DESC' : 'ASC';
        $this->index = array_reverse($this->index);

        // revert order in all sub-sets
        foreach ($this->sets as $set) {
            if ($this->order != $set->get_parameters('ORDER')) {
                $set->revert();
            }
        }
    }

    /**
     * Check if the given message ID exists in the object
     *
     * @param int  $msgid     Message ID
     * @param bool $get_index When enabled element's index will be returned.
     *                        Elements are indexed starting with 0
     *
     * @return mixed False if message ID doesn't exist, True if exists or
     *               index of the element if $get_index=true
     */
    public function exists($msgid, $get_index = false)
    {
        if (!empty($this->folder)) {
            $msgid .= '-' . $this->folder;
        }

        $idx = array_search($msgid, $this->index);

        if ($get_index) {
            return $idx;
        }

        return $idx !== false;
    }

    /**
     * Filters data set. Removes elements listed in $ids list.
     *
     * @param array  $ids    List of IDs to remove.
     * @param string $folder IMAP folder
     */
    public function filter($ids = [], $folder = null)
    {
        $this->meta['count'] = 0;
        foreach ($this->sets as $set) {
            if ($set->get_parameters('MAILBOX') == $folder) {
                $set->filter($ids);
            }

            $this->meta['count'] += $set->count();
        }
    }

    /**
     * Slices data set.
     *
     * @param int $offset Offset (as for PHP's array_slice())
     * @param int $length Number of elements (as for PHP's array_slice())
     */
    public function slice($offset, $length)
    {
        $data = array_slice($this->get(), $offset, $length);

        $this->index = $data;
        $this->meta['count'] = count($data);
    }

    /**
     * Filters data set. Removes elements not listed in $ids list.
     *
     * @param array $ids List of IDs to keep.
     */
    public function intersect($ids = [])
    {
        // not implemented
    }

    /**
     * Return all messages in the result.
     *
     * @return array List of message IDs
     */
    public function get()
    {
        return $this->index;
    }

    /**
     * Return all messages in the result in compressed form
     *
     * @return string List of message IDs in compressed form
     */
    public function get_compressed()
    {
        return '';
    }

    /**
     * Return result element at specified index
     *
     * @param int|string $idx Element's index or "FIRST" or "LAST"
     *
     * @return int Element value
     */
    public function get_element($idx)
    {
        switch ($idx) {
            case 'FIRST': return $this->index[0];
            case 'LAST':  return end($this->index);
            default:      return $this->index[$idx] ?? null;
        }
    }

    /**
     * Returns response parameters, e.g. ESEARCH's MIN/MAX/COUNT/ALL/MODSEQ
     * or internal data e.g. MAILBOX, ORDER
     *
     * @param string $param Parameter name
     *
     * @return array|string Response parameters or parameter value
     */
    public function get_parameters($param = null)
    {
        $params = [
            'SORT'    => $this->sorting,
            'ORDER'   => $this->order,
            'MAILBOX' => $this->folders,
        ];

        if ($param !== null) {
            return $params[$param];
        }

        return $params;
    }

    /**
     * Returns the stored result object for a particular folder
     *
     * @param string $folder Folder name
     *
     * @return false|rcube_result_index|rcube_result_thread A result set or false if none found
     */
    public function get_set($folder)
    {
        foreach ($this->sets as $set) {
            if ($set->get_parameters('MAILBOX') == $folder) {
                return $set;
            }
        }

        return false;
    }

    /**
     * Returns length of internal data representation
     *
     * @return int Data length
     */
    protected function length()
    {
        return $this->count();
    }

    /**
     * Serialization __sleep handler
     *
     * @return array Names of all object properties that should be serialized
     */
    public function __sleep()
    {
        $this->sdata = ['incomplete' => [], 'error' => []];

        foreach ($this->sets as $set) {
            if ($set->incomplete) {
                $this->sdata['incomplete'][] = $set->get_parameters('MAILBOX');
            } elseif ($set->is_error()) {
                $this->sdata['error'][] = $set->get_parameters('MAILBOX');
            }
        }

        return ['sdata', 'index', 'folders', 'sorting', 'order'];
    }

    /**
     * Serialization __wakeup handler
     */
    public function __wakeup()
    {
        $this->meta       = ['count' => count($this->index)];
        $this->incomplete = count($this->sdata['incomplete']) > 0;

        // restore result sets from saved index
        $data = [];
        foreach ($this->index as $item) {
            [$uid, $folder] = explode('-', $item, 2);
            $data[$folder] = ($data[$folder] ?? '') . ' ' . $uid;
        }

        foreach ($this->folders as $folder) {
            if (in_array($folder, $this->sdata['error'])) {
                $data_str = null;
            } else {
                $data_str = '* SORT' . ($data[$folder] ?? '');
            }

            $set = new rcube_result_index($folder, $data_str, strtoupper($this->order));

            if (in_array($folder, $this->sdata['incomplete'])) {
                $set->incomplete = true;
            }

            $this->sets[] = $set;
        }
    }
}
