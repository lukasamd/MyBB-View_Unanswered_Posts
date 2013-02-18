<?php
/**
 * This file is part of Unanswered Posts plugin for MyBB.
 * Copyright (C) 2010-2013 Lukasz Tkacz <lukasamd@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */ 
 
/**
 * Disallow direct access to this file for security reasons
 * 
 */
if (!defined("IN_MYBB")) exit;

/**
 * Create plugin object
 * 
 */
$plugins->objects['unansweredPosts'] = new unansweredPosts();

/**
 * Standard MyBB info function
 * 
 */
function unansweredPosts_info()
{
    global $lang;

    $lang->load("unansweredPosts");
    
    $lang->unansweredPostsDesc = '<form action="https://www.paypal.com/cgi-bin/webscr" method="post" style="float:right;">' .
        '<input type="hidden" name="cmd" value="_s-xclick">' . 
        '<input type="hidden" name="hosted_button_id" value="3BTVZBUG6TMFQ">' .
        '<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">' .
        '<img alt="" border="0" src="https://www.paypalobjects.com/pl_PL/i/scr/pixel.gif" width="1" height="1">' .
        '</form>' . $lang->unansweredPostsDesc;

    return Array(
        'name' => $lang->unansweredPostsName,
        'description' => $lang->unansweredPostsDesc,
        'website' => 'http://lukasztkacz.com',
        'author' => 'Lukasz Tkacz',
        'authorsite' => 'http://lukasztkacz.com',
        'version' => '1.4',
        'guid' => '296b5c7da4995c4fca95bcc252959071',
        'compatibility' => '16*'
    );
}

/**
 * Standard MyBB installation functions 
 * 
 */
function unansweredPosts_install()
{
    require_once('unansweredPosts.settings.php');
    unansweredPostsInstaller::install();

    rebuildsettings();
}

function unansweredPosts_is_installed()
{
    global $mybb;

    return (isset($mybb->settings['unansweredPostsExceptions']));
}

function unansweredPosts_uninstall()
{
    require_once('unansweredPosts.settings.php');
    unansweredPostsInstaller::uninstall();

    rebuildsettings();
}

/**
 * Standard MyBB activation functions 
 * 
 */
function unansweredPosts_activate()
{
    require_once('unansweredPosts.tpl.php');
    unansweredPostsActivator::activate();
}

function unansweredPosts_deactivate()
{
    require_once('unansweredPosts.tpl.php');
    unansweredPostsActivator::deactivate();
}

/**
 * Plugin Class 
 * 
 */
class unansweredPosts
{

    /**
     * Constructor - add plugin hooks
     */
    public function __construct()
    {
        global $plugins;

        $plugins->hooks["search_start"][10]["unansweredPosts_doSearch"] = array("function" => create_function('', 'global $plugins; $plugins->objects[\'unansweredPosts\']->doSearch();'));
        $plugins->hooks["pre_output_page"][10]["unansweredPosts_modifyOutput"] = array("function" => create_function('&$arg', 'global $plugins; $plugins->objects[\'unansweredPosts\']->modifyOutput($arg);'));
        $plugins->hooks["pre_output_page"][10]["unansweredPosts_pluginThanks"] = array("function" => create_function('&$arg', 'global $plugins; $plugins->objects[\'unansweredPosts\']->pluginThanks($arg);')); 
    }

    /**
     * Change links action from lastpost to unread and display link to search unreads
     */
    public function modifyOutput(&$content)
    {
        global $db, $lang, $mybb, $templates;
        $lang->load("unansweredPosts");

        // Is user logged in?
        if (!$mybb->user['uid'])
        {
            return false;
        }

        // Counter is not enable, display standard link
        if (!$this->getConfig('StatusCounter') || !$this->isPageCounterAllowed())
        {
            eval("\$unansweredPosts .= \"" . $templates->get("unansweredPosts_link") . "\";");
            $content = str_replace('<!-- UNANSWEREDPOSTS_LINK -->', $unansweredPosts, $content);
            return false;
        }

        // Prepare sql statements
        $this->where = '';
        $this->getStandardWhere();
        $this->getExceptions();
        $this->getPermissions();
        $this->getUnsearchableForums();
        $this->getInactiveForums();

        // Make a query to calculate unread posts
        $sql = "SELECT COUNT(tid) AS num_threads
                FROM " . TABLE_PREFIX . "threads 
                WHERE {$this->where}";
        $result = $db->query($sql);
        $numThreads = (int) $db->fetch_field($result, "num_threads");

        // Check numer of unread and couter visible setting
        eval("\$unansweredPostsCounter .= \"" . $templates->get("unansweredPosts_counter") . "\";");
        if ($numThreads > 0 || $this->getConfig('StatusCounterHide') == 0)
        {
            eval("\$unansweredPosts .= \"" . $templates->get("unansweredPosts_linkCounter") . "\";");
            $content = str_replace('<!-- UNANSWEREDPOSTS_LINK -->', $unansweredPosts, $content);
        }
    }

    public function doSearch()
    {
        global $db, $lang, $mybb, $plugins, $session;

        if ($mybb->input['action'] != 'unanswered' || !$mybb->user['uid'])
        {
            return;
        }

        // Prepare sql statements
        $this->where = '';
        $this->getStandardWhere();
        $this->getExceptions();
        $this->getPermissions();
        $this->getUnsearchableForums();
        $this->getInactiveForums();

        // Make a query to search unanswered topics
        $sql = "SELECT tid
            FROM " . TABLE_PREFIX . "threads 
            WHERE {$this->where}
            ORDER BY dateline DESC
            LIMIT 1000";
        $result = $db->query($sql);

        // Build a unanswered topics list 
        while ($row = $db->fetch_array($result))
        {
            $tids[] = $row['tid'];
        }

        // Decide and make a where statement
        if (sizeof($tids) > 0)
        {
            $this->where = 'tid IN (' . implode(',', $tids) . ')';
        }
        else
        {
            $this->where = '1 < 0';
        }

        // Use mybb built-in search engine system
        $sid = md5(uniqid(microtime(), 1));
        $searcharray = array(
            "sid" => $db->escape_string($sid),
            "uid" => $mybb->user['uid'],
            "dateline" => TIME_NOW,
            "ipaddress" => $db->escape_string($session->ipaddress),
            "threads" => '',
            "posts" => '',
            "resulttype" => "threads",
            "querycache" => $db->escape_string($this->where),
            "keywords" => ''
        );

        $plugins->run_hooks("search_do_search_process");
        $db->insert_query("searchlog", $searcharray);
        redirect("search.php?action=results&sid=" . $sid, $lang->redirect_searchresults);
    }

    /**
     * Helper function to decide if unread counter is allowed on current page
     * 
     * @return bool Is allowed or not allowed
     */
    private function isPageCounterAllowed()
    {
        $allowedPages = explode("\n", $this->getConfig('CounterPages'));
        $allowedPages = array_map("trim", $allowedPages);
        for ($i = 0; $i < sizeof($allowedPages); $i++)
        {
            if ($allowedPages[$i] == '')
            {
                unset($allowedPages[$i]);
            }
        }
        shuffle($allowedPages);

        if (empty($allowedPages) || in_array(THIS_SCRIPT, $allowedPages))
        {
            return true;
        }

        return false;
    }

    /**
     * Get standard SQL WHERE statement - closed and moved threads are not allowed
     */
    private function getStandardWhere()
    {
        $this->where .= "replies = 0 AND visible = 1 AND closed NOT LIKE 'moved|%'";
    }

    /**
     * Get all forums exceptions to SQL WHERE statement
     */
    private function getExceptions()
    {
        if ($this->getConfig('Exceptions') == '')
        {
            return;
        }

        $exceptions_list = explode(',', $this->getConfig('Exceptions'));
        $exceptions_list = array_map('intval', $exceptions_list);

        if (sizeof($exceptions_list) > 0)
        {
            $this->where .= " AND fid NOT IN (" . implode(',', $exceptions_list) . ")";
        }
    }

    /**
     * Build a comma separated list of the forums this user cannot search
     *
     * @param int The parent ID to build from
     * @param int First rotation or not (leave at default)
     * @return return a CSV list of forums the user cannot search
     */
    private function getUnsearchableForums($pid="0", $first=1)
    {
        global $db, $forum_cache, $permissioncache, $mybb, $unsearchableforums, $unsearchable, $templates, $forumpass;

        $pid = intval($pid);

        if (!is_array($forum_cache))
        {
            // Get Forums
            $query = $db->simple_select("forums", "fid,parentlist,password,active", '', array('order_by' => 'pid, disporder'));
            while ($forum = $db->fetch_array($query))
            {
                $forum_cache[$forum['fid']] = $forum;
            }
        }


        if (THIS_SCRIPT == 'index.php')
        {
            $permissioncache = false;
        }

        if (!is_array($permissioncache))
        {
            $permissioncache = forum_permissions();
        }

        foreach ($forum_cache as $fid => $forum)
        {
            if ($permissioncache[$forum['fid']])
            {
                $perms = $permissioncache[$forum['fid']];
            }
            else
            {
                $perms = $mybb->usergroup;
            }

            $pwverified = 1;
            if ($forum['password'] != '')
            {
                if ($mybb->cookies['forumpass'][$forum['fid']] != md5($mybb->user['uid'] . $forum['password']))
                {
                    $pwverified = 0;
                }
            }

            $parents = explode(",", $forum['parentlist']);
            if (is_array($parents))
            {
                foreach ($parents as $parent)
                {
                    if ($forum_cache[$parent]['active'] == 0)
                    {
                        $forum['active'] = 0;
                    }
                }
            }

            if ($perms['canview'] != 1 || $perms['cansearch'] != 1 || $pwverified == 0 || $forum['active'] == 0)
            {
                if ($unsearchableforums)
                {
                    $unsearchableforums .= ",";
                }
                $unsearchableforums .= "'{$forum['fid']}'";
            }
        }
        $unsearchable = $unsearchableforums;

        // Get our unsearchable password protected forums
        $pass_protected_forums = $this->getPasswordProtectedForums();

        if ($unsearchable && $pass_protected_forums)
        {
            $unsearchable .= ",";
        }

        if ($pass_protected_forums)
        {
            $unsearchable .= implode(",", $pass_protected_forums);
        }

        if ($unsearchable)
        {
            $this->where .= " AND fid NOT IN ($unsearchable)";
        }
    }

    /**
     * Build a array list of the forums this user cannot search due to password protection
     *
     * @param int the fids to check (leave null to check all forums)
     * @return return a array list of password protected forums the user cannot search
     */
    private function getPasswordProtectedForums($fids=array())
    {
        global $forum_cache, $mybb;

        if (!is_array($fids))
        {
            return false;
        }

        if (!is_array($forum_cache))
        {
            $forum_cache = cache_forums();
            if (!$forum_cache)
            {
                return false;
            }
        }

        if (empty($fids))
        {
            $fids = array_keys($forum_cache);
        }

        $pass_fids = array();
        foreach ($fids as $fid)
        {
            if (empty($forum_cache[$fid]['password']))
            {
                continue;
            }

            if (md5($mybb->user['uid'] . $forum_cache[$fid]['password']) != $mybb->cookies['forumpass'][$fid])
            {
                $pass_fids[] = $fid;
                $child_list = get_child_list($fid);
            }

            if (is_array($child_list))
            {
                $pass_fids = array_merge($pass_fids, $child_list);
            }
        }
        return array_unique($pass_fids);
    }

    /**
     * Get all forums premissions to SQL WHERE statement
     */
    private function getPermissions()
    {
        $onlyusfids = array();

        // Check group permissions if we can't view threads not started by us
        $group_permissions = forum_permissions();
        foreach ($group_permissions as $fid => $forum_permissions)
        {
            if ($forum_permissions['canonlyviewownthreads'] == 1)
            {
                $onlyusfids[] = $fid;
            }
        }
        if (!empty($onlyusfids))
        {
            $this->where .= " AND ((fid IN(" . implode(',', $onlyusfids) . ") AND uid='{$mybb->user['uid']}') OR fid NOT IN(" . implode(',', $onlyusfids) . "))";
        }
    }

    /**
     * Get all inactive forums
     */
    private function getInactiveForums()
    {
        $inactiveforums = get_inactive_forums();
        if ($inactiveforums)
        {
            $this->where .= " AND fid NOT IN ($inactiveforums)";
        }
    }

    /**
     * Helper function to get variable from config
     * 
     * @param string $name Name of config to get
     * @return string Data config from MyBB Settings
     */
    private function getConfig($name)
    {
        global $mybb;

        return $mybb->settings["unansweredPosts{$name}"];
    }
    
    /**
     * Say thanks to plugin author - paste link to author website.
     * Please don't remove this code if you didn't make donate
     * It's the only way to say thanks without donate :)     
     */
    public function pluginThanks(&$content)
    {
        global $session, $lukasamd_thanks;
        
        if (!isset($lukasamd_thanks) && $session->is_spider)
        {
            $thx = '<div style="margin:auto; text-align:center;">This forum uses <a href="http://lukasztkacz.com">Lukasz Tkacz</a> MyBB addons.</div></body>';
            $content = str_replace('</body>', $thx, $content);
            $lukasamd_thanks = true;
        }
    }

}