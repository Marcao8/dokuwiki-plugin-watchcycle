<?php
/**
 * DokuWiki Plugin watchcycle (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_watchcycle extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {

       $controller->register_hook('PARSER_METADATA_RENDER', 'AFTER', $this, 'handle_parser_metadata_render');
       $controller->register_hook('PARSER_CACHE_USE', 'AFTER', $this, 'handle_parser_cache_use');

    }

    /**
     * [Custom event handler which performs action]
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */

    public function handle_parser_metadata_render(Doku_Event &$event, $param) {
        /** @var \helper_plugin_sqlite $sqlite */
        $sqlite = plugin_load('helper', 'watchcycle_db')->getDB();
        /* @var \helper_plugin_watchcycle */
        $helper = plugin_load('helper', 'watchcycle');

        $page = $event->data['current']['last_change']['id'];

        if(isset($event->data['current']['plugin']['watchcycle'])) {
            $watchcycle = $event->data['current']['plugin']['watchcycle'];
            $res = $sqlite->query('SELECT * FROM watchcycle WHERE page=?', $page);
            $row = $sqlite->res2row($res);
            $changes = $this->getLastMaintainerRev($event->data, $watchcycle['maintainer'], $last_maintainer_rev);
            //false if page needs checking
            $uptodate = $helper->daysAgo($last_maintainer_rev) <= $watchcycle['cycle'] ? '1' : '0';
            if (!$row) {
                $entry = $watchcycle;
                $entry['page'] = $page;
                $entry['last_maintainer_rev'] = $last_maintainer_rev;
                $entry['uptodate'] = $uptodate;
                if ($uptodate == '0') {
                    $this->informMaintainer();
                }

                $sqlite->storeEntry('watchcycle', $entry);
            } else { //check if we need to update something
                $toupdate = array();

                if ($row['cycle'] != $watchcycle['cycle']) {
                    $toupdate['cycle'] = $watchcycle['cycle'];
                }

                if ($row['maintainer'] != $watchcycle['maintainer']) {
                    $toupdate['maintainer'] = $watchcycle['maintainer'];
                }

                if ($row['last_maintainer_rev'] != $last_maintainer_rev) {
                    $toupdate['last_maintainer_rev'] = $last_maintainer_rev;
                }

                //uptodate value has chaned
                if ($row['uptodate'] != $uptodate) {
                    $toupdate['uptodate'] = $uptodate;
                    if (!$uptodate) {
                        $this->informMaintainer();
                    }
                }

                if (count($toupdate) > 0) {
                    $set = implode(',', array_map(function($v) {
                        return "$v=?";
                    }, array_keys($toupdate)));
                    $toupdate[] = $page;
                    $sqlite->query("UPDATE watchcycle SET $set WHERE page=?", $toupdate);
                }
            }
            $event->data['current']['plugin']['watchcycle']['last_maintainer_rev'] = $last_maintainer_rev;
            $event->data['current']['plugin']['watchcycle']['changes'] = $changes;
        } else { //maybe we've removed the syntax -> delete from the database
            $sqlite->query('DELETE FROM watchcycle WHERE page=?', $page);
        }
    }

    /**
     * @param array  $meta metadata of the page
     * @param string $maintanier
     * @param int    $rev revision of the last page edition by maintainer or create date if no edition was made
     * @return int   number of changes since last maintainer's revision
     */
    protected function getLastMaintainerRev($meta, $maintanier, &$rev) {

        $changes = 0;
        if ($meta['current']['last_change']['user'] == $maintanier) {
            $rev = $meta['current']['last_change']['date'];
            return $changes;
        } else {
            $page = $meta['current']['last_change']['id'];
            $changelog = new PageChangeLog($page);
            $first = 0;
            $num = 100;
            while (count($revs = $changelog->getRevisions($first, $num)) > 0) {
                foreach ($revs as $rev) {
                    $changes += 1;
                    $revInfo = $changelog->getRevisionInfo($rev);
                    if ($revInfo['user'] == $maintanier) {
                        $rev = $revInfo['date'];
                        return $changes;
                    }
                }
                $first += $num;
            }
        }

        $rev = $meta['current']['date']['created'];
        return -1;
    }

    protected function informMaintainer() {
        //TODO
    }

    /**
     * Clean the cache every 24 hours
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */

    public function handle_parser_cache_use(Doku_Event &$event, $param) {
        /* @var \helper_plugin_watchcycle */
        $helper = plugin_load('helper', 'watchcycle');

        if ($helper->daysAgo($event->data->_time) >= 1) {
            $event->result = false;
        }
    }

}

// vim:ts=4:sw=4:et:
