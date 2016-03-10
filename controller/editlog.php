<?php
/**
 *
 * Edit Log
 * @copyright (c) 2016 towen - [towenpa@gmail.com]
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace towen\editlog\controller;

class editlog
{
    /* @var \phpbb\auth\auth */
    protected $auth;

    /* @var \phpbb\config\config */
    protected $config;

    /* @var \phpbb\controller\helper */
    protected $helper;

    /* @var \phpbb\db\driver\driver_interface */
    protected $db;

    /* @var \phpbb\log\log */
    protected $log;

    /* @var \phpbb\request\request */
    private $request;

    /* @var \phpbb\template\template */
    protected $template;

    /* @var \phpbb\user */
    protected $user;

    /* @var string phpBB root path */
    protected $root_path;

    /* @var string phpEx */
    protected $php_ext;

    /* @var string */
    protected $table;

    /**
     * Constructor
     *
     * @param \phpbb\auth\auth $auth
     * @param \phpbb\config\config $config
     * @param \phpbb\controller\helper $helper
     * @param \phpbb\db\driver\driver_interface $db
     * @param \phpbb\log\log $log
     * @param \phpbb\request\request $request
     * @param \phpbb\template\template $template
     * @param \phpbb\user $user
     * @param string $root_path
     * @param string $php_ext
     * @param string $table
     *
     */
    public function __construct(\phpbb\auth\auth $auth, \phpbb\config\config $config, \phpbb\controller\helper $helper,
                                \phpbb\db\driver\driver_interface $db, \phpbb\log\log $log, \phpbb\request\request $request,
                                \phpbb\template\template $template, \phpbb\user $user,
                                $root_path, $php_ext, $table)
    {
        $this->auth = $auth;
        $this->config = $config;
        $this->helper = $helper;
        $this->db = $db;
        $this->log = $log;
        $this->request = $request;
        $this->template = $template;
        $this->user = $user;
        $this->root_path = $root_path;
        $this->php_ext = $php_ext;
        $this->table = $table;
    }

    /**
     *
     * @param $post
     * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
     */
    public function handle($post_id)
    {
        $post_id = (int) $post_id;

        $sql = 'SELECT forum_id, topic_id, post_subject FROM ' . POSTS_TABLE . "
            WHERE post_edit_count <> 0 AND post_id = {$post_id}";
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow();
        $this->db->sql_freeresult($result);

        if (empty($row))
        {
            trigger_error('NO_TOPIC', E_USER_WARNING);
        }

        $forum_id = $row['forum_id'];
        $topic_id = $row['topic_id'];
        $post_subject = censor_text($row['post_subject']);
        $post_url = append_sid("{$this->root_path}viewtopic.{$this->php_ext}", "f={$forum_id}&amp;t={$topic_id}&amp;p={$post_id}#p{$post_id}");
        $u_action = $this->helper->route('towen_editlog_controller', array('post_id' => $post_id));

        if (!$this->auth->acl_get('m_view_editlog', $forum_id))
        {
            trigger_error($this->user->lang('EDITLOG_NO_AUTH', $post_url), E_USER_WARNING);
        }

        // ACTION: compare
        if ($this->request->is_set_post('compare'))
        {
            $old_version = $this->request->variable('old', 0);
            $new_version = $this->request->variable('new', 0);

            if (($old_version && $new_version) && ($old_version != $new_version))
            {
                if ($new_version != -1 && $old_version > $new_version)
                {
                    $_tmp = $old_version;
                    $old_version = $new_version;
                    $new_version = $_tmp;
                    unset($_tmp);
                }

                $sql = 'SELECT old_text
				        FROM ' . $this->table . "
				        WHERE edit_id = {$old_version} AND post_id = {$post_id}";
                $result = $this->db->sql_query($sql);
                $old_text = $this->db->sql_fetchfield('old_text');
                $this->db->sql_freeresult($result);

                // -1 is the message in the posts table
                if ($new_version == -1)
                {
                    $sql = 'SELECT post_text, bbcode_uid
					        FROM ' . POSTS_TABLE . "
					        WHERE post_id = {$post_id}";
                    $result = $this->db->sql_query($sql);
                    $row = $this->db->sql_fetchrow($result);
                    $this->db->sql_freeresult($result);

                    decode_message($row['post_text'], $row['bbcode_uid']);
                    $new_text = $row['post_text'];
                }
                else
                {
                    $sql = 'SELECT old_text
					        FROM ' . $this->table . "
					        WHERE edit_id = {$new_version} AND post_id = {$post_id}";
                    $result = $this->db->sql_query($sql);
                    $new_text = $this->db->sql_fetchfield('old_text');
                    $this->db->sql_freeresult($result);
                }

                if (!$old_text || !$new_text)
                {
                    trigger_error($this->user->lang('NO_POST_LOG', $post_url), E_USER_WARNING);
                }

                include($this->root_path . 'includes/diff/diff.' . $this->php_ext);
                include($this->root_path . 'includes/diff/engine.' . $this->php_ext);
                include($this->root_path . 'includes/diff/renderer.' . $this->php_ext);

                $diff = new \diff($old_text, $new_text);
                $renderer = new \diff_renderer_inline();

                $content = preg_replace('#^<pre>(.*?)</pre>$#s', '$1', $renderer->get_diff_content($diff));
                $content = html_entity_decode($content);
            }
            elseif (!$old_version || !$new_version)
            {
                $content = $this->user->lang['NO_EDIT_OPTIONS'];
            }
            elseif ($old_version == $new_version)
            {
                $content = $this->user->lang['EDIT_OPTIONS_EQUALS'];
            }

            $this->template->assign_vars(array(
                'CONTENT' => $content,
                'OLD_POST' => $old_version,
                'NEW_POST' => $new_version,
            ));
        }

        // ACTION: delete
        if ($this->request->is_set_post('delete'))
        {
            if (!$this->auth->acl_get('m_delete_editlog', $forum_id))
            {
                trigger_error($this->user->lang('EDITLOG_NO_DELETE_AUTH', $u_action), E_USER_WARNING);
            }
            $edit_id_list = $this->request->variable('edit_delete', array(0=>0));

            if (sizeof($edit_id_list))
            {
                if (confirm_box(true))
                {
                    $sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('edit_id', $edit_id_list);
                    $this->db->sql_query($sql);

                    $log_array = array(
                        'forum_id' => $forum_id,
                        'topic_id' => $topic_id,
                        $post_url, $post_subject,
                    );
                    $this->log->add('mod', $this->user->data['user_id'], $this->user->data['user_ip'], 'LOG_EDITLOG_DELETE_SUCCESS', false, $log_array);

                    trigger_error($this->user->lang('EDITLOG_DELETE_SUCCESS', $u_action), E_USER_NOTICE);
                }
                else
                {
                    confirm_box(false, $this->user->lang('CONFIRM_OPERATION'), build_hidden_fields(array(
                        'edit_delete'	=> $edit_id_list,
                        'delete'		=> true,
                    )));
                }
            }
        }

        // ACTION: show list
        $sql_array = array(
            'SELECT' => 'e.edit_id, e.user_id, e.edit_time, e.edit_reason, p.topic_id, p.post_subject, u.username, u.user_colour',
            'FROM' => array(
                POSTS_TABLE => 'p',
                $this->table => 'e',
            ),
            'LEFT_JOIN' => array(
                array(
                    'FROM' => array(USERS_TABLE => 'u'),
                    'ON' => 'e.user_id = u.user_id',
                ),
            ),
            'WHERE' => "e.post_id = {$post_id} AND e.post_id = p.post_id",
        );

        $sql = $this->db->sql_build_query('SELECT', $sql_array);
        $result = $this->db->sql_query($sql);

        $post_have_log = false;

        while ($row = $this->db->sql_fetchrow($result))
        {
            $post_have_log = true;

            $this->template->assign_block_vars('edit', array(
                'EDIT_ID' => $row['edit_id'],
                'EDIT_TIME' => $this->user->format_date($row['edit_time']),
                'EDIT_REASON' => $row['edit_reason'],
                'USERNAME' => get_username_string('full', $row['user_id'], $row['username'], $row['user_colour']),
            ));
        }
        $this->db->sql_freeresult($result);

        if (!$post_have_log)
        {
            trigger_error($this->user->lang('NO_POST_LOG', $post_url), E_USER_WARNING);
        }

        // last version is in the posts table
        $sql_array = array(
            'SELECT' => 'p.post_edit_time, p.post_edit_reason, p.post_edit_user, p.topic_id, u.username, u.user_colour',
            'FROM' => array(
                POSTS_TABLE => 'p',
            ),
            'LEFT_JOIN' => array(
                array(
                    'FROM' => array(USERS_TABLE => 'u'),
                    'ON' => 'p.post_edit_user = u.user_id',
                ),
            ),
            'WHERE' => "p.post_id = {$post_id}",
        );

        $sql = $this->db->sql_build_query('SELECT', $sql_array);
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $this->template->assign_block_vars('edit', array(
            'EDIT_ID' => -1,
            'EDIT_TIME' => $this->user->format_date($row['post_edit_time']),
            'EDIT_REASON' => $row['post_edit_reason'],
            'USERNAME' => get_username_string('full', $row['post_edit_user'], $row['username'], $row['user_colour']),
        ));

        // build navlinks
        $sql = 'SELECT forum_id, forum_type, forum_name, forum_desc, forum_desc_uid, forum_desc_bitfield,
            forum_desc_options, forum_options, parent_id, forum_parents, left_id, right_id FROM ' . FORUMS_TABLE . "
            WHERE forum_id = {$forum_id}";
        $result = $this->db->sql_query_limit($sql, 1);
        $forum_data = $this->db->sql_fetchrow();
        $this->db->sql_freeresult($result);

        if (!function_exists('generate_forum_nav'))
        {
            include($this->root_path . 'includes/functions_display.' . $this->php_ext);
        }
        \generate_forum_nav($forum_data);

        $this->template->assign_vars(array(
            'POST_SUBJECT' => $post_subject,
            'U_POST' => $post_url,
            'U_ACTION' => $u_action,
            'S_DELETE' => $this->auth->acl_get('m_delete_editlog', $forum_id),
        ));

        return $this->helper->render('editlog_body.html', $this->user->lang['EDIT_LOG']);
    }
}
