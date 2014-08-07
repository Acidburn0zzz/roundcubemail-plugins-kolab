<?php

/**
 * Kolab storage class providing access to configuration objects on a Kolab server.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2012-2014, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_storage_config
{
    const FOLDER_TYPE = 'configuration';


    /**
     * Singleton instace of kolab_storage_config
     *
     * @var kolab_storage_config
     */
    static protected $instance;

    private $folders;
    private $default;
    private $enabled;


    /**
     * This implements the 'singleton' design pattern
     *
     * @return kolab_storage_config The one and only instance
     */
    static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new kolab_storage_config();
        }

        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->folders = kolab_storage::get_folders(self::FOLDER_TYPE);

        foreach ($this->folders as $folder) {
            if ($folder->default) {
                $this->default = $folder;
                break;
            }
        }

        // if no folder is set as default, choose the first one
        if (!$this->default) {
            $this->default = reset($this->folders);
        }

        // check if configuration folder exist
        if ($this->default && $this->default->name) {
            $this->enabled = true;
        }
    }

    /**
     * Check wether any configuration storage (folder) exists
     *
     * @return bool
     */
    public function is_enabled()
    {
        return $this->enabled;
    }

    /**
     * Get configuration objects
     *
     * @param array $filter      Search filter
     * @param bool  $default     Enable to get objects only from default folder
     * @param array $data_filter Additional object data filter
     * @param int   $limit       Max. number of records (per-folder)
     *
     * @return array List of objects
     */
    public function get_objects($filter = array(), $default = false, $data_filter = array(), $limit = 0)
    {
        $list = array();

        foreach ($this->folders as $folder) {
            // we only want to read from default folder
            if ($default && !$folder->default) {
                continue;
            }

            // for better performance it's good to assume max. number of records
            if ($limit) {
                $folder->set_order_and_limit(null, $limit);
            }

            foreach ($folder->select($filter) as $object) {
                foreach ($data_filter as $key => $val) {
                    if ($object[$key] != $val) {
                        continue 2;
                    }
                }

                $list[] = $object;
            }
        }

        return $list;
    }

    /**
     * Get configuration object
     *
     * @param string $uid     Object UID
     * @param bool   $default Enable to get objects only from default folder
     *
     * @return array Object data
     */
    public function get_object($uid, $default = false)
    {
        foreach ($this->folders as $folder) {
            // we only want to read from default folder
            if ($default && !$folder->default) {
                continue;
            }

            if ($object = $folder->get_object($uid)) {
                return $object;
            }
        }
    }

    /**
     * Create/update configuration object
     *
     * @param array  $object Object data
     * @param string $type   Object type
     *
     * @return bool True on success, False on failure
     */
    public function save(&$object, $type)
    {
        if (!$this->enabled) {
            return false;
        }

        $folder = $this->find_folder($object);

        if ($type) {
            $object['type'] = $type;
        }

        return $folder->save($object, self::FOLDER_TYPE . '.' . $object['type'], $object['uid']);
    }

    /**
     * Remove configuration object
     *
     * @param string $uid Object UID
     *
     * @return bool True on success, False on failure
     */
    public function delete($uid)
    {
        if (!$this->enabled) {
            return false;
        }

        // fetch the object to find folder
        $list   = $this->get_object($uid);
        $object = $list[0];

        if (!$object) {
            return false;
        }

        $folder = $this->find_folder($object);

        return $folder->delete($uid);
    }

    /**
     * Find folder
     */
    public function find_folder($object = array())
    {
        // find folder object
        if ($object['_mailbox']) {
            foreach ($this->folders as $folder) {
                if ($folder->name == $object['_mailbox']) {
                    break;
                }
            }
        }
        else {
            $folder = $this->default;
        }

        return $folder;
    }

    /**
     * Builds relation member URI
     *
     * @param string|array Object UUID or Message folder, UID, Search headers (Message-Id, Date)
     *
     * @return string $url Member URI
     */
    public static function build_member_url($params)
    {
        // param is object UUID
        if (is_string($params) && !empty($params)) {
            return 'urn:uuid:' . $params;
        }

        if (empty($params) || !strlen($params['folder'])) {
            return null;
        }

        $rcube   = rcube::get_instance();
        $storage = $rcube->get_storage();

        // modify folder spec. according to namespace
        $folder = $params['folder'];
        $ns     = $storage->folder_namespace($folder);

        if ($ns == 'shared') {
            // Note: this assumes there's only one shared namespace root
            if ($ns = $storage->get_namespace('shared')) {
                if ($prefix = $ns[0][0]) {
                    $folder = 'shared' . substr($folder, strlen($prefix));
                }
            }
        }
        else {
            if ($ns == 'other') {
                // Note: this assumes there's only one other users namespace root
                if ($ns = $storage->get_namespace('shared')) {
                    if ($prefix = $ns[0][0]) {
                        $folder = 'user' . substr($folder, strlen($prefix));
                    }
                }
            }
            else {
                $folder = 'user' . '/' . $rcube->get_user_name() . '/' . $folder;
            }
        }

        $folder = implode('/', array_map('rawurlencode', explode('/', $folder)));

        // build URI
        $url = 'imap:///' . $folder;

        // UID is optional here because sometimes we want
        // to build just a member uri prefix
        if ($params['uid']) {
            $url .= '/' . $params['uid'];
        }

        unset($params['folder']);
        unset($params['uid']);

        if (!empty($params)) {
            $url .= '?' . http_build_query($params, '', '&');
        }

        return $url;
    }

    /**
     * Parses relation member string
     *
     * @param string $url Member URI
     *
     * @return array Message folder, UID, Search headers (Message-Id, Date)
     */
    public static function parse_member_url($url)
    {
        // Look for IMAP URI:
        // imap:///(user/username@domain|shared)/<folder>/<UID>?<search_params>
        if (strpos($url, 'imap:///') === 0) {
            $rcube   = rcube::get_instance();
            $storage = $rcube->get_storage();

            // parse_url does not work with imap:/// prefix
            $url   = parse_url(substr($url, 8));
            $path  = explode('/', $url['path']);
            parse_str($url['query'], $params);

            $uid  = array_pop($path);
            $ns   = array_shift($path);
            $path = array_map('rawurldecode', $path);

            // resolve folder name
            if ($ns == 'shared') {
                $folder = implode('/', $path);
                // Note: this assumes there's only one shared namespace root
                if ($ns = $storage->get_namespace('shared')) {
                    if ($prefix = $ns[0][0]) {
                        $folder = $prefix . '/' . $folder;
                    }
                }
            }
            else if ($ns == 'user') {
                $username = array_shift($path);
                $folder   = implode('/', $path);

                if ($username != $rcube->get_user_name()) {
                    // Note: this assumes there's only one other users namespace root
                    if ($ns = $storage->get_namespace('other')) {
                        if ($prefix = $ns[0][0]) {
                            $folder = $prefix . '/' . $username . '/' . $folder;
                        }
                    }
                }
                else if (!strlen($folder)) {
                    $folder = 'INBOX';
                }
            }
            else {
                return;
            }

            return array(
                'folder' => $folder,
                'uid'    => $uid,
                'params' => $params,
            );
        }
    }

    /**
     * Build array of member URIs from set of messages
     *
     * @param string $folder   Folder name
     * @param array  $messages Array of rcube_message objects
     *
     * @return array List of members (IMAP URIs)
     */
    public static function build_members($folder, $messages)
    {
        $members = array();

        foreach ((array) $messages as $msg) {
            $params = array(
                'folder' => $folder,
                'uid'    => $msg->uid,
            );

            // add search parameters:
            // we don't want to build "invalid" searches e.g. that
            // will return false positives (more or wrong messages)
            if (($messageid = $msg->get('message-id', false)) && ($date = $msg->get('date', false))) {
                $params['message-id'] = $messageid;
                $params['date']       = $date;

                if ($subject = $msg->get('subject', false)) {
                    $params['subject'] = substr($subject, 0, 256);
                }
            }

            $members[] = self::build_member_url($params);
        }

        return $members;
    }

    /**
     * Resolve/validate/update members (which are IMAP URIs) of relation object.
     *
     * @param array $tag   Tag object
     * @param bool  $force Force members list update
     *
     * @return array Folder/UIDs list
     */
    public static function resolve_members(&$tag, $force = true)
    {
        $result = array();

        foreach ((array) $tag['members'] as $member) {
            // IMAP URI members
            if ($url = self::parse_member_url($member)) {
                $folder = $url['folder'];

                if (!$force) {
                    $result[$folder][] = $url['uid'];
                }
                else {
                    $result[$folder]['uid'][]    = $url['uid'];
                    $result[$folder]['params'][] = $url['params'];
                    $result[$folder]['member'][] = $member;
                }
            }
        }

        if (empty($result) || !$force) {
            return $result;
        }

        $rcube   = rcube::get_instance();
        $storage = $rcube->get_storage();
        $search  = array();
        $missing = array();

        // first we search messages by Folder+UID
        foreach ($result as $folder => $data) {
            // @FIXME: maybe better use index() which is cached?
            // @TODO: consider skip_deleted option
            $index = $storage->search_once($folder, 'UID ' . rcube_imap_generic::compressMessageSet($data['uid']));
            $uids  = $index->get();

            // messages that were not found need to be searched by search parameters
            $not_found = array_diff($data['uid'], $uids);
            if (!empty($not_found)) {
                foreach ($not_found as $uid) {
                    $idx = array_search($uid, $data['uid']);

                    if ($p = $data['params'][$idx]) {
                        $search[] = $p;
                    }

                    $missing[] = $result[$folder]['member'][$idx];

                    unset($result[$folder]['uid'][$idx]);
                    unset($result[$folder]['params'][$idx]);
                    unset($result[$folder]['member'][$idx]);
                }
            }

            $result[$folder] = $uids;
        }

        // search in all subscribed mail folders using search parameters
        if (!empty($search)) {
            // remove not found members from the members list
            $tag['members'] = array_diff($tag['members'], $missing);

            // get subscribed folders
            $folders = $storage->list_folders_subscribed('', '*', 'mail', null, true);

            // @TODO: do this search in chunks (for e.g. 10 messages)?
            $search_str = '';

            foreach ($search as $p) {
                $search_params = array();
                foreach ($p as $key => $val) {
                    $key = strtoupper($key);
                    // don't search by subject, we don't want false-positives
                    if ($key != 'SUBJECT') {
                        $search_params[] = 'HEADER ' . $key . ' ' . rcube_imap_generic::escape($val);
                    }
                }

                $search_str .= ' (' . implode(' ', $search_params) . ')';
            }

            $search_str = trim(str_repeat(' OR', count($search)-1) . $search_str);

            // search
            $search = $storage->search_once($folders, $search_str);

            // handle search result
            $folders = (array) $search->get_parameters('MAILBOX');

            foreach ($folders as $folder) {
                $set  = $search->get_set($folder);
                $uids = $set->get();

                if (!empty($uids)) {
                    $msgs    = $storage->fetch_headers($folder, $uids, false);
                    $members = self::build_members($folder, $msgs);

                    // merge new members into the tag members list
                    $tag['members'] = array_merge($tag['members'], $members);

                    // add UIDs into the result
                    $result[$folder] = array_unique(array_merge((array)$result[$folder], $uids));
                }
            }

            // update tag object with new members list
            $tag['members'] = array_unique($tag['members']);
            kolab_storage_config::get_instance()->save($tag, 'relation', false);
        }

        return $result;
    }
}