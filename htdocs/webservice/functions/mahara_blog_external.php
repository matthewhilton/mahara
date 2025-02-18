<?php
/**
 *
 * @package    mahara
 * @subpackage auth-webservice
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

/**
 * External user API
 *
 * @package    auth
 * @subpackage webservice
 * @copyright  2009 Moodle Pty Ltd (http://moodle.com)
 * @copyright  Copyright (C) 2011 Catalyst IT Ltd (http://www.catalyst.net.nz)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Piers Harding
 */

require_once(get_config('docroot') . 'webservice/lib.php');
require_once(get_config('docroot') . 'webservice/rest/locallib.php');
require_once(get_config('docroot') . 'lib/user.php');
require_once(get_config('docroot') . 'api/xmlrpc/lib.php');
safe_require('artefact', 'blog');

global $WEBSERVICE_OAUTH_USER;
/**
* Class container for core Mahara user related API calls
*/
class mahara_blog_external extends external_api {

    static private $blogtypes = array('owner');

    /**
     * parameter definition for input of  get_blogs_for_user method
     *
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function get_blogs_for_user_parameters() {
       return new external_function_parameters(
            array(
                'users' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id'              => new external_value(PARAM_NUMBER, get_string('blogownerid', WEBSERVICE_LANG), VALUE_OPTIONAL, null, NULL_ALLOWED, 'id'),
                            'username'        => new external_value(PARAM_RAW, get_string('blogownerusername', WEBSERVICE_LANG), VALUE_OPTIONAL, null, NULL_ALLOWED, 'id'),
                            'remoteuser'      => new external_value(PARAM_RAW, get_string('blogownerremusername', WEBSERVICE_LANG), VALUE_OPTIONAL, null, NULL_ALLOWED, 'id'),
                            'email'           => new external_value(PARAM_RAW, get_string('blogowneremail', WEBSERVICE_LANG), VALUE_OPTIONAL, null, NULL_ALLOWED, 'id'),
                            )
                        )
                    )
                )
            );
    }

    /**
     * Check that a user exists
     *
     * @param array $user array('id' => .., 'username' => ..)
     * @return array() of user
     */
    private static function checkuser($user) {
        global $WEBSERVICE_INSTITUTION;

        if (isset($user['id'])) {
            $id = $user['id'];
        }
        else if (isset($user['userid'])) {
            $id = $user['userid'];
        }
        else if (isset($user['username'])) {
            $username = strtolower($user['username']);
            $dbuser = get_record('usr', 'username', $username);
            if (empty($dbuser)) {
                throw new WebserviceInvalidParameterException(get_string('invalidusername', 'auth.webservice', $user['username']));
            }
            $id = $dbuser->id;
        }
        else if (isset($user['email'])) {
            $email = strtolower($user['email']);
            $dbuser = get_record('usr', 'email', $email, null, null, null, null, '*', 0);
            if (empty($dbuser)) {
                throw new WebserviceInvalidParameterException(get_string('invalidusername', 'auth.webservice', $user['email']));
            }
            $id = $dbuser->id;
        }
        else if (isset($user['remoteuser'])) {
            $dbinstances = get_records_array('auth_instance', 'institution', $WEBSERVICE_INSTITUTION, 'active', 1);
            $dbuser = false;
            foreach ($dbinstances as $dbinstance) {
               $user_factory = new User;
               $dbuser = $user_factory->find_by_instanceid_username($dbinstance->id, $user['remoteuser'], true);
               if ($dbuser) {
                   break;
               }
            }
            if (empty($dbuser)) {
                throw new WebserviceInvalidParameterException(get_string('invalidremoteusername', 'auth.webservice', $user['username']));
            }
            $id = $dbuser->id;
        }
        else {
            throw new WebserviceInvalidParameterException(get_string('musthaveid', 'auth.webservice'));
        }
        // now get the user
        if ($user = get_user($id)) {
            if ($user->deleted) {
                throw new WebserviceInvalidParameterException(get_string('invaliduserid', 'auth.webservice', $id));
            }
            // get the remoteuser
            $user->remoteuser = get_field('auth_remote_user', 'remoteusername', 'authinstance', $user->authinstance, 'localusr', $user->id);
            return $user;
        }
        else {
            throw new WebserviceInvalidParameterException(get_string('invaliduserid', 'auth.webservice', $id));
        }
    }

    /**
     * Get user information for one or more users
     *
     * @param array $users  array of users
     * @return array An array of arrays describing users
     */
    public static function get_blogs_for_user($users) {
        global $WEBSERVICE_INSTITUTION, $WEBSERVICE_OAUTH_USER, $USER;

        $params = self::validate_parameters(self::get_blogs_for_user_parameters(),
                array('users' => $users));
        $result = array();

        log_debug('in get_blogs_for_user: ' . var_export($params, true));
        // if this is a get all users - then lets get them all
        if (empty($params['users'])) {
            return $result;
        }

        //TODO: check if there is any performance issue: we do one DB request to retrieve
        //  all user, then for each user the profile_load_data does at least two DB requests
        foreach ($params['users'] as $u) {
            $user = self::checkuser($u);
            // skip deleted users
            if (!empty($user->deleted)) {
                continue;
            }
            // check the institution
            if (!mahara_external_in_institution($user, $WEBSERVICE_INSTITUTION)) {
                continue;
            }

            $auth_instance = get_record('auth_instance', 'id', $user->authinstance, 'active', 1);
            $USER->reanimate($user->id, $user->authinstance);
            $data = new stdClass();
            list($data->count, $data->data) = ArtefactTypeBlog::get_blog_list(null, null);
            $blogs = array('count' => $data->count, 'ids' => array(), 'data' => array(), 'blogposts' => array());
            foreach ($data->data as $blog) {
                $blogid = $blog->id;
                $blogs['ids'][] = $blog->id;
                $bloginfo = get_record('artefact', 'id', $blog->id);
                $blog = array('title' => $blog->title,
                              'description' => $blog->description,
                              'postcount' => $blog->postcount,
                              'ctime' => $bloginfo->ctime,
                              'mtime' => $bloginfo->mtime,
                              'locked' => $blog->locked,
                              'id' => $blog->id,
                              'owner' => $bloginfo->owner,
                              'author' => $bloginfo->author,
                              'allowcomments' => $bloginfo->allowcomments,
                              'approvecomments' => $bloginfo->approvecomments,
                              'blogposts' => array(),
                              );

                $posts = ArtefactTypeBlogPost::get_posts($blogid, 100, 0, null);
                $blogposts = array('count' => $posts['count'], 'ids' => array(), 'data' => array());
                foreach ($posts['data'] as $post) {
                    $blogposts['ids'][] = $post->id;
                    $blogpost = array('title' => $post->title,
                                      'description' => $post->description,
                                      'blogid' => $blogid,
                                      'ctime' => $post->ctime,
                                      'mtime' => $post->mtime,
                                      'locked' => $post->locked,
                                      'id' => $post->id,
                                      'owner' => $post->owner,
                                      'author' => $post->author,
                                      'allowcomments' => $post->allowcomments,
                                      'approvecomments' => $post->approvecomments,
                                      );
                    $blogposts['data'][] = $blogpost;
                }
                $blogposts['ids'] = implode(',', $blogposts['ids']);
                $blogs['blogposts'] = $blogposts;
                $blogs['data'][] = $blog;
            }
            $blogs['ids'] = implode(',', $blogs['ids']);
            $userarray = array();
            // we want to return an array not an object
            $userarray['id'] = $user->id;
            $userarray['username'] = $user->username;
            $userarray['firstname'] = $user->firstname;
            $userarray['lastname'] = $user->lastname;
            $userarray['email'] = $user->email;
            $userarray['auth'] = $auth_instance->authname;
            $userarray['studentid'] = $user->studentid;
            $userarray['displayname'] = display_name($user);
            $userarray['institution'] = $auth_instance->institution;
            $userarray['blogs'] = $blogs;
            $result[] = $userarray;
        }

        log_debug('get_blogs_for_user Results: ' . var_export($result, true));
        return $result;
    }

    /**
     * parameter definition for output of get_blogs_for_user method
     *
     * Returns description of method result value
     * @return external_description
     */
    public static function get_blogs_for_user_returns() {
        return new external_multiple_structure(
                new external_single_structure(
                    array(
                    'id'          => new external_value(PARAM_NUMBER, get_string('blogownerid', WEBSERVICE_LANG)),
                    'username'    => new external_value(PARAM_RAW, get_string('blogownerusername', WEBSERVICE_LANG)),
                    'firstname'   => new external_value(PARAM_NOTAGS, get_string('firstname', WEBSERVICE_LANG)),
                    'lastname'    => new external_value(PARAM_NOTAGS, get_string('lastname', WEBSERVICE_LANG)),
                    'email'       => new external_value(PARAM_TEXT, get_string('blogowneremail', WEBSERVICE_LANG)),
                    'auth'        => new external_value(PARAM_SAFEDIR, get_string('authplugins', WEBSERVICE_LANG)),
                    'studentid'   => new external_value(PARAM_RAW, get_string('studentidinst', WEBSERVICE_LANG)),
                    'institution' => new external_value(PARAM_SAFEDIR, get_string('institution', WEBSERVICE_LANG)),
                    'blogs'       => new external_single_structure(
                                        array(
                                            'count' => new external_value(PARAM_NUMBER, get_string('blogscount', WEBSERVICE_LANG)),
                                            'ids'   => new external_value(PARAM_RAW, get_string('blogsids', WEBSERVICE_LANG)),
                                            'data'  =>
                                        new external_multiple_structure(
                                            new external_single_structure(
                                                array(
                                                    'id'              => new external_value(PARAM_NUMBER, get_string('blogid', WEBSERVICE_LANG)),
                                                    'title'           => new external_value(PARAM_RAW, get_string('blogtitle', WEBSERVICE_LANG)),
                                                    'description'     => new external_value(PARAM_RAW, get_string('blogdesc', WEBSERVICE_LANG)),
                                                    'postcount'       => new external_value(PARAM_INTEGER, get_string('blogpostcount', WEBSERVICE_LANG)),
                                                    'mtime'           => new external_value(PARAM_RAW, get_string('blogmodtime', WEBSERVICE_LANG)),
                                                    'ctime'           => new external_value(PARAM_RAW, get_string('blogcreatetime', WEBSERVICE_LANG)),
                                                    'locked'          => new external_value(PARAM_BOOL, get_string('locked', WEBSERVICE_LANG)),
                                                    'owner'           => new external_value(PARAM_INTEGER, get_string('blogowner', WEBSERVICE_LANG)),
                                                    'author'          => new external_value(PARAM_INTEGER, get_string('blogauthor', WEBSERVICE_LANG)),
                                                    'allowcomments'   => new external_value(PARAM_BOOL, get_string('allowcomments', WEBSERVICE_LANG)),
                                                    'approvecomments' => new external_value(PARAM_BOOL, get_string('approvecomments', WEBSERVICE_LANG)),
                                                ),
                                                get_string('blog', WEBSERVICE_LANG))
                                         ),
                        'blogposts'   => new external_single_structure(
                                        array(
                                            'count' => new external_value(PARAM_NUMBER, get_string('blogpostcount', WEBSERVICE_LANG)),
                                            'ids'   => new external_value(PARAM_RAW, get_string('blogpostsids', WEBSERVICE_LANG)),
                                            'data' =>
                                        new external_multiple_structure(
                                            new external_single_structure(
                                                array(
                                                    'id'              => new external_value(PARAM_NUMBER, get_string('blogpostid', WEBSERVICE_LANG)),
                                                    'title'           => new external_value(PARAM_RAW, get_string('blogposttitle', WEBSERVICE_LANG)),
                                                    'description'     => new external_value(PARAM_RAW, get_string('blogpostdesc', WEBSERVICE_LANG)),
                                                    'mtime'           => new external_value(PARAM_RAW, get_string('blogpostmodtime', WEBSERVICE_LANG)),
                                                    'ctime'           => new external_value(PARAM_RAW, get_string('blogpostcreatetime', WEBSERVICE_LANG)),
                                                    'locked'          => new external_value(PARAM_BOOL, get_string('locked', WEBSERVICE_LANG)),
                                                    'owner'           => new external_value(PARAM_INTEGER, get_string('blogpostowner', WEBSERVICE_LANG)),
                                                    'author'          => new external_value(PARAM_INTEGER, get_string('blogpostauthor', WEBSERVICE_LANG)),
                                                    'allowcomments'   => new external_value(PARAM_BOOL, get_string('allowcomments', WEBSERVICE_LANG)),
                                                    'approvecomments' => new external_value(PARAM_BOOL, get_string('approvecomments', WEBSERVICE_LANG)),
                                                    'blogid'          => new external_value(PARAM_INTEGER, get_string('blogofparent', WEBSERVICE_LANG)),
                                                ),
                                                get_string('blogpost', WEBSERVICE_LANG))
                                        ),
                                     ), get_string('blogposts', WEBSERVICE_LANG))
                                 ), get_string('blogs', WEBSERVICE_LANG)),
                    )
                )
        );
    }

    /**
     * parameter definition for input of create_blogpost method
     *
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function create_blogpost_parameters() {

        return new external_function_parameters(
        array(
            'blogposts' => new external_multiple_structure(
                             new external_single_structure(
                               array(
                                   'owner'           => new external_value(PARAM_INTEGER, get_string('blogowner', WEBSERVICE_LANG)),
                                   'blogid'          => new external_value(PARAM_INTEGER, get_string('blogofparent', WEBSERVICE_LANG)),
                                   'title'           => new external_value(PARAM_RAW, get_string('blogposttitle', WEBSERVICE_LANG)),
                                   'description'     => new external_value(PARAM_NOTAGS, get_string('blogpostdesc', WEBSERVICE_LANG)),
                                   'draft'           => new external_value(PARAM_BOOL, get_string('blogpostdraft', WEBSERVICE_LANG), VALUE_DEFAULT, '0'),
                                   'allowcomments'   => new external_value(PARAM_BOOL, get_string('allowcomments', WEBSERVICE_LANG), VALUE_DEFAULT, '1'),
                                   'tags'            => new external_multiple_structure(
                                                          new external_single_structure(
                                                            array(
                                                                'tag' => new external_value(PARAM_ALPHANUMEXT, get_string('tag', WEBSERVICE_LANG), VALUE_OPTIONAL),
                                                                 ), get_string('tags', WEBSERVICE_LANG))
                                                        ),
                                   )
                            )
                    )
            )
        );
    }

    /**
     * Create one or more blogposts
     *
     * @param array $blogposts  An array of blogposts to create.
     * @return array An array of arrays describing blogposts
     */
    public static function create_blogpost($blogposts) {
        global $USER, $WEBSERVICE_INSTITUTION;

        // Do basic automatic PARAM checks on incoming data, using params description
        $params = self::validate_parameters(self::create_blogpost_parameters(), array('blogposts' => $blogposts));
        db_begin();
        $blogids = array();
        foreach ($params['blogposts'] as $blogpost) {
            // Make sure that the blog exists, is owned by the owner, and that the owner is active
            $blog = false;
            if ($user = get_record('usr', 'id', $blogpost['owner'], 'deleted', 0)) {
                if (!$blog = get_record('artefact', 'artefacttype', 'blog', 'id', $blogpost['blogid'])) {
                    throw new WebserviceInvalidParameterException('create_blogpost | ' . get_string('notuserblog', 'auth.webservice', $user->username));
                }
                // Make sure auth is valid
                if (!$authinstance = get_record('auth_instance', 'id', $user->authinstance, 'active', 1)) {
                    throw new WebserviceInvalidParameterException(get_string('invalidauthtype', 'auth.webservice', $user->authinstance));
                }
                // check the institution is allowed
                // basic check authorisation to edit for the current institution of the user
                if (!$USER->can_edit_institution($authinstance->institution)) {
                    throw new WebserviceInvalidParameterException('create_blogpost | ' . get_string('accessdeniedforinstuser', 'auth.webservice', $authinstance->institution, $user->username));
                }
            }
            else {
                throw new WebserviceInvalidParameterException('create_blogpost | ' . get_string('erroruser', 'auth.webservice'));
            }

            $tags = array();
            $tagobj = !empty($blogpost['tags']) ? $blogpost['tags'] : array();
            foreach ($tagobj as $tag) {
                $tags[] = $tag['tag'];
            }
            $blogobj = new ArtefactTypeBlog($blog->id);
            // Create the blogpost
            $postobj = new ArtefactTypeBlogPost(0, null);
            $postobj->set('title', $blogpost['title']);
            $postobj->set('description', $blogpost['description']);
            $postobj->set('tags', $tags);
            $postobj->set('published', !$blogpost['draft']);
            $postobj->set('allowcomments', (int) $blogpost['allowcomments']);
            $postobj->set('parent', $blog->id);
            $postobj->set('owner', $blogpost['owner']);
            $postobj->commit();
            $id = $postobj->get('id');
            $blogids[] = array('id' => $id, 'title' => $blogpost['title']);
        }
        db_commit();
        return $blogids;
    }

    /**
     * parameter definition for output of create_blogpost method
     *
     * Returns description of method result value
     * @return external_multiple_structure
     */
    public static function create_blogpost_returns() {
        return new external_multiple_structure(
                   new external_single_structure(
                       array(
                           'id'       => new external_value(PARAM_INT, get_string('blogpostid', WEBSERVICE_LANG)),
                           'title'    => new external_value(PARAM_RAW, get_string('blogposttitle', WEBSERVICE_LANG)),
                       )
                   )
        );
    }
}
