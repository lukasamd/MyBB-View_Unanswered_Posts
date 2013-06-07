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
        'version' => '1.5',
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

    // SQL Where Statement
    private $where = '';
    
    /**
     * Constructor - add plugin hooks
     *      
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
     *      
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

        // Prepare sql statements
        $this->buildSQLWhere();

        // Make a query to calculate unread posts
        $sql = "SELECT 1
                FROM " . TABLE_PREFIX . "threads 
                WHERE {$this->where}
                LIMIT " . $this->buildSQLLimit();  
        $result = $db->query($sql);
        $numThreads = (int) $db->num_rows($result);
        
        // Change counter
        if ($numThreads > $this->limit)
        {
            $numThreads = ($numThreads - 1) . '+';
        }
        
        // Hide link
        if ($this->getConfig('StatusCounterHide') && $numThreads == 0)
        {
            return;
        }

        // Link without counter
        if (!$this->getConfig('StatusCounter') || !$this->isPageCounterAllowed())
        {
            eval("\$unansweredPosts .= \"" . $templates->get("unansweredPosts_link") . "\";");
            $content = str_replace('<!-- UNANSWEREDPOSTS_LINK -->', $unansweredPosts, $content);
            return;
        }

        // Link with counter
        eval("\$unansweredPostsCounter .= \"" . $templates->get("unansweredPosts_counter") . "\";");
        if ($numUnreads > 0 || $this->getConfig('StatusCounterHide') == 0)
        {
            eval("\$unansweredPosts .= \"" . $templates->get("unansweredPosts_linkCounter") . "\";");
            $content = str_replace('<!-- UNANSWEREDPOSTS_LINK -->', $unansweredPosts, $content);
        }
    }
    
    /**
     * Search for unanswered threads ids
     *      
     */
    public function doSearch()
    {
        global $db, $lang, $mybb, $plugins, $session;

        if ($mybb->input['action'] != 'unanswered' || !$mybb->user['uid'])
        {
            return;
        }

        // Prepare sql statements
        $this->buildSQLWhere();

        // Make a query to search unanswered topics
        $sql = "SELECT tid
            FROM " . TABLE_PREFIX . "threads 
            WHERE {$this->where}
            ORDER BY dateline DESC
            LIMIT 500";
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
     * Prepare WHERE statement for unread posts search query
     *      
     */
    private function buildSQLWhere()
    {
        if ($this->where != '')
        {
            return;
        }        
    
        // Standard where
        $this->where .= "replies = 0 AND visible = 1 AND closed NOT LIKE 'moved|%'";
    
        // Exceptions
        if ($this->getConfig('Exceptions') != '')
        {
            $exceptions_list = explode(',', $this->getConfig('Exceptions'));
            $exceptions_list = array_map('intval', $exceptions_list);
    
            if (sizeof($exceptions_list) > 0)
            {
                $this->where .= " AND fid NOT IN (" . implode(',', $exceptions_list) . ")";
            }
        }

        // Permissions
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
        
        // Unsearchable forums
        if (!function_exists('get_unsearchable_forums'))
        {
            require_once MYBB_ROOT."inc/functions_search.php";
            $unsearchforums = get_unsearchable_forums();
            if ($unsearchforums)
            {
                $this->where .= " AND fid NOT IN ($unsearchforums)";
            }
        }
        
        // Inactive forums
        $inactiveforums = get_inactive_forums();
        if ($inactiveforums)
        {
            $this->where .= " AND fid NOT IN ($inactiveforums)";
        }
    }
    
    /**
     * Prepare LIMIT for search query
     *      
     */
    private function buildSQLLimit()
    {
        if (!$this->getConfig('StatusCounter'))
        {
            $this->limit = 1;
            return 1;        
        }
    
        $limit = (int) $this->getConfig('Limit');
        if (!$limit || $limit > 10000)
        {
            $limit = 500;
        }
        
        $this->limit = $limit;
        return $limit + 1;
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