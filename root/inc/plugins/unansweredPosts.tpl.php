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
 * Plugin Activator Class
 * 
 */
class unansweredPostsActivator
{

    private static $tpl = array();

    private static function getTpl()
    {
        global $db;

        self::$tpl[] = array(
            "tid" => NULL,
            "title" => 'unansweredPosts_link',
            "template" => $db->escape_string('
 | <a href="{$mybb->settings[\'bburl\']}/search.php?action=unanswered">{$lang->unansweredPostsLink}</a>'),
            "sid" => "-1",
            "version" => "1.0",
            "dateline" => TIME_NOW,
        );

        self::$tpl[] = array(
            "tid" => NULL,
            "title" => 'unansweredPosts_linkCounter',
            "template" => $db->escape_string('
 | <a href="{$mybb->settings[\'bburl\']}/search.php?action=unanswered">{$lang->unansweredPostsLink} {$unansweredPostsCounter}</a>'),
            "sid" => "-1",
            "version" => "1.0",
            "dateline" => TIME_NOW,
        );

        self::$tpl[] = array(
            "tid" => NULL,
            "title" => 'unansweredPosts_counter',
            "template" => $db->escape_string('
({$numThreads})'),
            "sid" => "-1",
            "version" => "1.0",
            "dateline" => TIME_NOW,
        );
    }

    public static function activate()
    {
        global $db;
        self::deactivate();

        for ($i = 0; $i < sizeof(self::$tpl); $i++)
        {
            $db->insert_query('templates', self::$tpl[$i]);
        }
        find_replace_templatesets('header_welcomeblock_member', '#' . preg_quote('{$lang->welcome_todaysposts}</a>') . '#', '{$lang->welcome_todaysposts}</a><!-- UNANSWEREDPOSTS_LINK -->');
    }

    public static function deactivate()
    {
        global $db;
        self::getTpl();

        for ($i = 0; $i < sizeof(self::$tpl); $i++)
        {
            $db->delete_query('templates', "title = '" . self::$tpl[$i]['title'] . "'");
        }

        include MYBB_ROOT . '/inc/adminfunctions_templates.php';
        find_replace_templatesets('header_welcomeblock_member', '#' . preg_quote('<!-- UNANSWEREDPOSTS_LINK -->') . '#', '');
    }

}
