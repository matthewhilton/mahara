<?php
/**
 * Create/edit a group.
 *
 * @package    mahara
 * @subpackage core
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

define('INTERNAL', 1);
define('MENUITEM', 'engage/index');
define('MENUITEM_SUBPAGE', 'info');
require(dirname(dirname(__FILE__)) . '/init.php');
require_once('group.php');
require_once(get_config('libroot') . 'antispam.php');
require_once('embeddedimage.php');

$cancreatecontrolled = $USER->get('admin') || $USER->get('staff')
    || $USER->is_institutional_admin() || $USER->is_institutional_staff();

if ($id = param_integer('id', null)) {
    define('TITLE', get_string('editgroup', 'group'));
    define('GROUP', $id);

    if (!group_user_can_configure($id)) {
        $SESSION->add_error_msg(get_string('canteditdontown', 'group'));
        redirect('/group/index.php');
    }

    $group_data = group_get_groups_for_editing(array($id));

    if (count($group_data) != 1) {
        throw new GroupNotFoundException(get_string('groupnotfound', 'group', $id));
    }

    $group_data = $group_data[0];
    define('SUBSECTIONHEADING', TITLE);
    // Fix dates to unix timestamps instead of formatted timestamps.
    $group_data->editwindowstart = isset($group_data->editwindowstart) ? strtotime($group_data->editwindowstart) : null;
    $group_data->editwindowend = isset($group_data->editwindowend) ? strtotime($group_data->editwindowend) : null;
}
else {
    define('TITLE', get_string('creategroup', 'group'));
    define('CREATEGROUP', true);

    if (!group_can_create_groups()) {
        throw new AccessDeniedException();
    }

    $group_data = (object) array(
        'id'             => null,
        'name'           => null,
        'description'    => null,
        'institution'    => 'mahara',
        'grouptype'      => 'standard',
        'open'           => 1,
        'controlled'     => 0,
        'request'        => 0,
        'category'       => 0,
        'public'         => 0,
        'usersautoadded' => 0,
        'viewnotify'     => GROUP_ROLES_ALL,
        'submittableto'  => 0,
        'allowarchives'  => 0,
        'editroles'      => 'all',
        'hidden'         => 0,
        'groupparticipationreports' => 0,
        'grouparchivereports' => 0,
        'invitefriends'  => 0,
        'suggestfriends' => 0,
        'urlid'          => null,
        'editwindowstart' => null,
        'editwindowend'  => null,
        'sendnow'        => 0,
        'feedbacknotify' => GROUP_ROLES_ALL,
        'hidemembers'    => GROUP_HIDE_NONE,
        'hidemembersfrommembers'  => GROUP_HIDE_NONE,
    );

    // If the user belongs to an institution we need to set the default institution as one of theirs
    $userinstitutions = array_keys($USER->get('institutions'));
    if (!empty($userinstitutions)) {
        $group_data->institution = $userinstitutions[0]; // assign the first one
    }
    $group_prefix = 'group_';
    if ($group_defaults = get_records_sql_array("SELECT * FROM {institution_config}
                                                 WHERE institution = ? AND field LIKE ? || '%'", array('mahara', $group_prefix))) {
        foreach ($group_defaults as $k => $v) {
            $item = preg_replace('/^' . $group_prefix . '/', '', $v->field);
            if (array_key_exists($item, $group_data)) {
                if ($item == 'controlled' && !$cancreatecontrolled) {
                    $v->value = 0;
                }
                if ($item == 'editwindowstart' || $item == 'editwindowend') {
                    $v->value = strtotime($v->value);
                }
                $group_data->$item = $v->value;
            }
        }
    }
    // If we are in institutions other than "mahara" check which institutions have reached their max group limit
    $userinsts = $USER->institutions;
    if (!empty($userinsts)) {
        $maxedoutgroups = 0;
        foreach ($USER->institutions as $inst) {
            if (group_max_reached($inst->institution, false)) {
                $maxedoutgroups++;
            }
        }
        // if all groups have reached max limit, prevent creating new group
        if ($maxedoutgroups === count($USER->institutions) && !$USER->get('admin')) {
            throw new AccessDeniedException(get_string('groupmaxreachednolink','group', $group_data->institution));
        }
    }
}

$namemaxlength = 128;

$form = array(
    'name'       => 'editgroup',
    'plugintype' => 'core',
    'pluginname' => 'groups',
    'elements'   => array(
        'name' => array(
            'type'         => 'text',
            'title'        => get_string('groupname', 'group'),
            'rules'        => array( 'required' => true, 'maxlength' => $namemaxlength),
            'defaultvalue' => $group_data->name,
        ),
        'shortname' => group_get_shortname_element($group_data),
        'institution' => array(
            'type'         => 'select',
            'title'        => get_string('associatewithinstitution', 'group'),
            'defaultvalue' => $group_data->institution,
            'collapseifoneoption' => true,
            'options'      => get_institutions_to_associate(),
        ),
        'description' => array(
            'type'         => 'wysiwyg',
            'title'        => get_string('groupdescription', 'group'),
            'rules'        => array('maxlength' => 1000000),
            'rows'         => 10,
            'cols'         => 55,
            'defaultvalue' => $group_data->description,
        ),
        'urlid' => array(
            'type'         => 'text',
            'title'        => get_string('groupurl', 'group'),
            'prehtml'      => '<span class="description">' . get_config('wwwroot') . get_config('cleanurlgroupdefault') . '/</span> ',
            'description'  => get_string('groupurldescription', 'group') . ' ' . get_string('cleanurlallowedcharacters'),
            'rules'        => array('maxlength' => 30, 'regex' => get_config('cleanurlvalidate')),
            'defaultvalue' => $group_data->urlid,
            'ignore'       => !$id || !get_config('cleanurls'),
        ),
        'settings' => array(
            'type'         => 'fieldset',
            'collapsible'  => true,
            'collapsed'    => false,
            'class'        => 'sectioned last',
            'legend'       => get_string('settings'),
            'elements'     => array(),
        ),
        'submit' => array(
            'type'         => 'submitcancel',
            'subclass'     => array('btn-primary'),
            'value'        => array(get_string('savegroup', 'group'), get_string('cancel')),
            'goto'         => get_config('wwwroot') . 'group/index.php',
        ),
    ),
);

$elements = array();

$elements['membership'] = array(
    'type'         => 'html',
    'value'        => '<h3>' . get_string('Membership', 'group') . '</h3>',
);

$elements['open'] = array(
    'type'         => 'switchbox',
    'title'        => get_string('Open', 'group'),
    'description'  => get_string('opendescription', 'group'),
    'defaultvalue' => $group_data->open,
    'disabled'     => !$cancreatecontrolled && $group_data->controlled,
);
if ($cancreatecontrolled || $group_data->controlled) {
    $elements['controlled'] = array(
        'type'         => 'switchbox',
        'title'        => get_string('Controlled', 'group'),
        'description'  => get_string('controlleddescription', 'group'),
        'defaultvalue' => $group_data->controlled,
        'disabled'     => !$cancreatecontrolled,
    );
}
else {
    $form['elements']['controlled'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->controlled,
    );
}
$elements['request'] = array(
    'type'         => 'switchbox',
    'title'        => get_string('request', 'group'),
    'description'  => get_string('requestdescription', 'group'),
    'defaultvalue' => !$group_data->open && $group_data->request,
    'disabled'     => $group_data->open,
);

// The grouptype determines the allowed roles
$grouptypeoptions = group_get_grouptype_options($group_data->grouptype);

// Hide the grouptype option if it was passed in as a parameter, if the user
// isn't allowed to change it, or if there's only one option.
if (!$id) {
    $grouptypeparam = param_alphanumext('grouptype', 0);
    if (isset($grouptypeoptions[$grouptypeparam])) {
        $group_data->grouptype = $grouptypeparam;
        $forcegrouptype = true;
    }
}
else if (!isset($grouptypeoptions[$group_data->grouptype])) {
    // The user can't create groups of this type.  Probably a non-staff user
    // who's been promoted to admin of a controlled group.
    $forcegrouptype = true;
}

if (!empty($forcegrouptype) || count($grouptypeoptions) < 2) {
    $form['elements']['grouptype'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->grouptype,
    );
}
else {
    $elements['grouptype'] = array(
        'type'         => 'select',
        'title'        => get_string('Roles', 'group'),
        'options'      => $grouptypeoptions,
        'defaultvalue' => $group_data->grouptype,
        'help'         => true
    );
}

$elements['invitefriends'] = array(
    'type'         => 'switchbox',
    'title'        => get_string('friendinvitations', 'group'),
    'description'  => get_string('invitefriendsdescription1', 'group'),
    'defaultvalue' => $group_data->invitefriends,
);

$elements['suggestfriends'] = array(
    'type'         => 'switchbox',
    'title'        => get_string('Recommendations', 'group'),
    'description'  => get_string('suggestfriendsdescription1', 'group'),
    'defaultvalue' => $group_data->suggestfriends && ($group_data->open || $group_data->request),
    'disabled'     => !$group_data->open && !$group_data->request,
);

$elements['pages'] = array(
    'type'         => 'html',
    'value'        => '<h3>' . get_string('content') . '</h3>',
);

$elements['editroles'] = array(
    'type'         => 'select',
    'options'      => group_get_editroles_options(),
    'title'        => get_string('editroles1', 'group'),
    'description'  => get_string('editrolesdescription2', 'group'),
    'defaultvalue' => $group_data->editroles,
    'help'         => true,
);

if ($cancreatecontrolled) {
    $elements['submittableto'] = array(
        'type'         => 'switchbox',
        'title'        => get_string('allowsubmissions', 'group'),
        'description'  => get_string('allowssubmissionsdescription1', 'group'),
        'defaultvalue' => $group_data->submittableto,
    );
    $elements['allowarchives'] = array(
        'type'         => 'switchbox',
        'title'        => get_string('allowsarchives', 'group'),
        'description'  => get_string('allowsarchivesdescription1', 'group'),
        'defaultvalue' => $group_data->allowarchives,
        'disabled'     => !$group_data->submittableto,
        'help'         => true,
    );
    $elements['grouparchivereports'] = array(
        'type'         => 'switchbox',
        'title'        => get_string('grouparchivereports', 'group'),
        'description'  => get_string('grouparchivereportsdesc', 'group'),
        'defaultvalue' => $group_data->grouparchivereports,
        'disabled'     => !$group_data->submittableto,
    );
}
else {
    $form['elements']['submittableto'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->submittableto,
    );
    $form['elements']['allowarchives'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->allowarchives,
    );
    $form['elements']['grouparchivereports'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->grouparchivereports,
    );
}

$publicallowed = group_can_create_public_groups() && !is_probationary_user();

if (!$id && !param_exists('pieform_editgroup')) {
    // If a 'public=0' parameter is passed on the first page load, hide the
    // public checkbox.  The only purpose of this is to allow custom create
    // group buttons/links which lead to a slightly simplified form.
    $publicparam = param_integer('public', null);
}

$ignorepublic = !$publicallowed || (isset($publicparam) && $publicparam === 0);

if ($cancreatecontrolled || !$ignorepublic) {
    $elements['visibility'] = array(
        'type'         => 'html',
        'value'        => '<h3>' .get_string('Visibility') . '</h3>',
    );
}

$elements['public'] = array(
    'type'         => 'switchbox',
    'title'        => get_string('publiclyviewablegroup', 'group'),
    'description'  => get_string('publiclyviewablegroupdescription1', 'group'),
    'defaultvalue' => $group_data->public,
    'help'         => true,
    'ignore'       => $ignorepublic,
);

if ($cancreatecontrolled) {
    $elements['hidden'] = array(
        'type'         => 'switchbox',
        'title'        => get_string('hiddengroup', 'group'),
        'description'  => get_string('hiddengroupdescription2', 'group'),
        'defaultvalue' => $group_data->hidden,
    );
    $elements['hidemembers'] = array(
        'type'         => 'select',
        'options'      => group_hide_members_options(),
        'title'        => get_string('hidemembers', 'group'),
        'description'  => get_string('hidemembersdescription', 'group'),
        'defaultvalue' => ($group_data->hidemembersfrommembers ? $group_data->hidemembersfrommembers : ($group_data->hidemembers ? $group_data->hidemembers : 0)),
        'disabled'     => $group_data->hidemembersfrommembers,
    );
    $elements['hidemembersfrommembers'] = array(
        'type'         => 'select',
        'options'      => group_hide_members_options(),
        'title'        => get_string('hidemembersfrommembers', 'group'),
        'description'  => get_string('hidemembersfrommembersdescription1', 'group'),
        'defaultvalue' => $group_data->hidemembersfrommembers,
    );
}
else {
    $form['elements']['hidden'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->hidden,
    );
    $form['elements']['hidemembers'] = array(
        'type'         => 'hidden',
        'value'        => ($group_data->hidemembersfrommembers ? $group_data->hidemembersfrommembers : ($group_data->hidemembers ? $group_data->hidemembers : 0)),
    );
    $form['elements']['hidemembersfrommembers'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->hidemembersfrommembers,
    );
}

$elements['groupparticipationreports'] = array(
    'type'         => 'switchbox',
    'title'        => get_string('groupparticipationreports', 'group'),
    'description'  => get_string('groupparticipationreportsdesc1', 'group'),
    'defaultvalue' => $group_data->groupparticipationreports,
);

$elements['editability'] = array(
    'type'         => 'html',
    'value'        => '<h3>' . get_string('editability', 'group') . '</h3>',
);

$currentdate = getdate();

$elements['editwindowstart'] = array (
    'type'         => 'calendar',
    'class'        => '',
    'title'        => get_string('windowstart', 'group'),
    'defaultvalue' => $group_data->editwindowstart,
    'description'  => get_string('windowstartdescription', 'group'),
    'minyear'      => $currentdate['year'],
    'maxyear'      => $currentdate['year'] + 20,
    'time'         => true,
    'caloptions'   => array(
        'showsTime'      => true,
    )
);

$elements['editwindowend'] = array (
    'type'         => 'calendar',
    'class'        => '',
    'title'        => get_string('windowend', 'group'),
    'defaultvalue' => $group_data->editwindowend,
    'description'  => get_string('windowenddescription', 'group'),
    'minyear'      => $currentdate['year'],
    'maxyear'      => $currentdate['year'] + 20,
    'time'         => true,
    'caloptions'   => array(
        'showsTime'      => true,
    )
);

$elements['general'] = array(
    'type'         => 'html',
    'value'        => '<h3>' . get_string('general') . '</h3>',
);

if (get_config('allowgroupcategories')
    && $groupcategories = get_records_menu('group_category','','','displayorder', 'id,title')
) {
    $elements['category'] = array(
                'type'         => 'select',
                'title'        => get_string('groupcategory', 'group'),
                'options'      => array('0'=>get_string('nocategoryselected', 'group')) + $groupcategories,
                'defaultvalue' => $group_data->category);

    // If it's a new group & the category was passed as a parameter, hide it in the form.
    $groupcategoryparam = param_integer('category', 0);
    if (!$id && isset($groupcategories[$groupcategoryparam])) {
        $form['elements']['category'] = array(
            'type'  => 'hidden',
            'value' => $groupcategoryparam,
        );
    }
}

$elements['usersautoadded'] = array(
            'type'         => 'switchbox',
            'title'        => get_string('usersautoadded', 'group'),
            'description'  => get_string('usersautoaddeddescription1', 'group'),
            'defaultvalue' => $group_data->usersautoadded,
            'help'         => true,
            'ignore'       => !$USER->get('admin'));
$notifyroles = array(get_string('none', 'admin')) + group_get_editroles_options(true);
$elements['viewnotify'] = array(
    'type' => 'select',
    'title' => get_string('viewnotify', 'group'),
    'options' => $notifyroles,
    'description' => get_string('viewnotifydescription3', 'group'),
    'defaultvalue' => $group_data->viewnotify
);
$elements['feedbacknotify'] = array(
    'type' => 'select',
    'title' => get_string('commentnotify', 'group'),
    'options' => $notifyroles,
    'description' => get_string('commentnotifydescription1', 'group'),
    'defaultvalue' => $group_data->feedbacknotify
);
if ($cancreatecontrolled) {
    $elements['sendnow'] = array(
        'type'         => 'switchbox',
        'title'        => get_string('allowsendnow', 'group'),
        'description'  => get_string('allowsendnowdescription1', 'group'),
        'defaultvalue' => $group_data->sendnow
    );
}
else {
    $form['elements']['sendnow'] = array(
        'type'         => 'hidden',
        'value'        => $group_data->sendnow,
    );
}
$form['elements']['settings']['elements'] = $elements;
$editgroup = pieform($form);


/**
 * Validate group setting changes
 *
 * @param  Pieform $form
 * @param  array $values
 * @return void
 */
function editgroup_validate(Pieform $form, $values) {
    global $group_data, $namemaxlength;

    if ((empty($group_data->id) || $group_data->institution !== $values['institution']) && group_max_reached($values['institution'], true)) {
        $form->set_error('institution', get_string('groupmaxreached','group', get_config('wwwroot'), $values['institution']), false);
    }

    if ($group_data->name != $values['name']) {
        // This check has not always been case-insensitive; don't use get_record in case we get >1 row back.
        if ($ids = get_records_sql_array('SELECT id FROM {group} WHERE LOWER(TRIM(name)) = ?', array(strtolower(trim($values['name']))))) {
            if (count($ids) > 1 || $ids[0]->id != $group_data->id) {
                // the group name already exists so generate name group suggestion
                $suggestedname = group_generate_name($values['name'], $namemaxlength);
                $form->set_error('name', get_string('groupalreadyexistssuggest', 'group', $suggestedname));
            }
        }
    }

    if (isset($values['shortname']) && $group_data->id) {
        if (!preg_match('/^[a-z0-9_.-]{2,255}$/', $values['shortname'])) {
            $form->set_error('shortname', get_string('shortnameformat1', 'group'));
        }

        if ($group_data->shortname != $values['shortname']) {
            // This check has not always been case-insensitive; don't use get_record in case we get >1 row back.
            if ($ids = get_records_sql_array('SELECT id FROM {group} WHERE LOWER(TRIM(shortname)) = ?', array(strtolower(trim($values['shortname']))))) {
                if (count($ids) > 1 || $ids[0]->id != $group_data->id) {
                    $form->set_error('shortname', get_string('groupshortnamealreadyexists', 'group'));
                }
            }
        }
    }

    if (isset($values['urlid']) && get_config('cleanurls')) {
        $urlidlength = strlen($values['urlid']);
        if ($group_data->urlid != $values['urlid']) {
            if ($urlidlength && record_exists('group', 'urlid', $values['urlid'])) {
                $form->set_error('urlid', get_string('groupurltaken', 'group'));
            }
            else if (!$urlidlength) {
                // Once you've set a group url, there's no going back
                $form->set_error('urlid', get_string('rule.minlength.minlength', 'pieforms', 3));
            }
        }
        // If the urlid is empty, we'll generate it when creating a group, but if it's 1 or 2 characters
        // long, it's an error.
        if ($urlidlength > 0 && $urlidlength < 3) {
            $form->set_error('urlid', get_string('rule.minlength.minlength', 'pieforms', 3));
        }
    }

    if (!empty($values['open'])) {
        if (!empty($values['controlled'])) {
            $form->set_error('open', get_string('membershipopencontrolled', 'group'));
        }
        if (!empty($values['request'])) {
            $form->set_error('request', get_string('membershipopenrequest', 'group'));
        }
    }
    if (!empty($values['invitefriends']) && !empty($values['suggestfriends'])) {
        $form->set_error('invitefriends', get_string('suggestinvitefriends', 'group'));
    }
    if (!empty($values['suggestfriends']) && empty($values['open']) && empty($values['request'])) {
        $form->set_error('suggestfriends', get_string('suggestfriendsrequesterror', 'group'));
    }
    if (!empty($values['editwindowstart']) && !empty($values['editwindowend']) && ($values['editwindowstart'] >= $values['editwindowend'])) {
        $form->set_error('editwindowend', get_string('editwindowendbeforestart', 'group'));
    }
}

/**
 * Submit cancelling edits to group settings.
 *
 * @return void
 */
function editgroup_cancel_submit() {
    redirect('/group/index.php');
}

/**
 * Submit group setting edits
 *
 * @param  Pieform $form
 * @param  array $values
 * @return void
 */
function editgroup_submit(Pieform $form, $values) {
    global $USER, $SESSION, $group_data, $publicallowed;

    $values['public'] = (isset($values['public'])) ? $values['public'] : 0;
    $values['usersautoadded'] = (isset($values['usersautoadded'])) ? $values['usersautoadded'] : 0;
    $allowedinstitutions = get_institutions_to_associate();
    $institution = isset($allowedinstitutions[$values['institution']]) ? $values['institution'] : 'mahara';

    $newvalues = array(
        'name'           => $group_data->name == $values['name'] ? $values['name'] : trim($values['name']),
        'institution'    => $institution,
        'description'    => $values['description'],
        'grouptype'      => $values['grouptype'],
        'category'       => empty($values['category']) ? null : intval($values['category']),
        'open'           => intval($values['open']),
        'controlled'     => intval($values['controlled']),
        'request'        => intval($values['request']),
        'usersautoadded' => intval($values['usersautoadded']),
        'public'         => ($publicallowed ? intval($values['public']) : 0),
        'viewnotify'     => intval($values['viewnotify']),
        'submittableto'  => intval($values['submittableto']),
        'allowarchives'  => intval(!empty($values['allowarchives']) ? $values['allowarchives'] : 0),
        'editroles'      => $values['editroles'],
        'hidden'         => intval($values['hidden']),
        'hidemembers'    => (!empty($values['hidemembersfrommembers']) ? $values['hidemembersfrommembers'] : $values['hidemembers']),
        'hidemembersfrommembers' => intval($values['hidemembersfrommembers']),
        'groupparticipationreports' => intval($values['groupparticipationreports']),
        'grouparchivereports' => intval($values['grouparchivereports']),
        'invitefriends'  => intval($values['invitefriends']),
        'suggestfriends' => intval($values['suggestfriends']),
        'editwindowstart' => db_format_timestamp($values['editwindowstart']),
        'editwindowend'  => db_format_timestamp($values['editwindowend']),
        'sendnow'        => intval($values['sendnow']),
        'feedbacknotify'     => intval($values['feedbacknotify']),
    );

    // Check to see if the group's forum is being used as a landing page url and if the changes affect it
    $homepageredirecturl = get_config('homepageredirecturl');
    if ($group_data->id && get_config('homepageredirect') && !empty($homepageredirecturl)) {
        $landing = translate_landingpage_to_tags(array($homepageredirecturl));
        foreach ($landing as $land) {
            $forumgroup = get_field('interaction_instance', 'group', 'id', $land->typeid);
            if ($land->type == 'forum' && !empty($forumgroup) && $forumgroup == $group_data->id && empty($newvalues['public'])) {
                set_config('homepageredirecturl', null);
                notify_landing_removed($land);
                $SESSION->add_error_msg(get_string('landingpagegone', 'admin', $land->text));
            }
        }
    }

    // Only admins can only update shortname.
    if (isset($values['shortname']) && $USER->can_edit_group_shortname($group_data)) {
        $newvalues['shortname'] = $values['shortname'];
    }

    if (
            get_config('cleanurls')
            && isset($values['urlid'])
            && '' !== (string) $values['urlid']
    ) {
        $newvalues['urlid'] = $values['urlid'];
    }

    db_begin();

    if (!$group_data->id) {
        $newvalues['members'] = array($USER->get('id') => 'admin');
        $group_data->id = group_create($newvalues);
        $USER->reset_grouproles();
    }
    // Now update the description with any embedded image info
    $newvalues['description'] = EmbeddedImage::prepare_embedded_images($newvalues['description'], 'group', $group_data->id, $group_data->id);
    $newvalues['id'] = $group_data->id;
    unset($newvalues['members']);
    group_update((object)$newvalues);

    $SESSION->add_ok_msg(get_string('groupsaved', 'group'));

    db_commit();

    // Reload $group_data->urlid or else the redirect will fail
    if (get_config('cleanurls') && (!isset($values['urlid']) || $group_data->urlid != $values['urlid'])) {
        $group_data->urlid = get_field('group', 'urlid', 'id', $group_data->id);
    }

    redirect(group_homepage_url($group_data));
}

$js = '
jQuery(function($) {
    $("#editgroup_controlled").on("click", function() {
        if (this.checked) {
            $("#editgroup_request").prop("disabled", false);
            $("#editgroup_open").prop("checked", false);
            if (!$("#editgroup_request").attr("checked")) {
                $("#editgroup_suggestfriends").prop("checked", false);
                $("#editgroup_suggestfriends").prop("disabled", true);
            }
        }
    });
    $("#editgroup_open").on("click", function() {
        if (this.checked) {
            $("#editgroup_controlled").prop("checked", false);
            $("#editgroup_request").prop("checked", false);
            $("#editgroup_request").prop("disabled", true);
            $("#editgroup_suggestfriends").prop("disabled", false);
        }
        else {
            $("#editgroup_request").prop("disabled", false);
            if (!$("#editgroup_request").attr("checked")) {
                $("#editgroup_suggestfriends").prop("checked", false);
                $("#editgroup_suggestfriends").prop("disabled", true);
            }
        }
    });
    $("#editgroup_request").on("click", function() {
        if (this.checked) {
            $("#editgroup_suggestfriends").prop("disabled", false);
        }
        else {
            if (!$("#editgroup_open").attr("checked")) {
                $("#editgroup_suggestfriends").prop("checked", false);
                $("#editgroup_suggestfriends").prop("disabled", true);
            }
        }
    });
    $("#editgroup_invitefriends").on("click", function() {
        if (this.checked) {
            if ($("#editgroup_request").attr("checked") || $("#editgroup_open").attr("checked")) {
                $("#editgroup_suggestfriends").prop("disabled", false);
            }
            $("#editgroup_suggestfriends").prop("checked", false);
        }
    });
    $("#editgroup_suggestfriends").on("click", function() {
        if (this.checked) {
            $("#editgroup_invitefriends").prop("checked", false);
        }
    });
    $("#editgroup_hidemembersfrommembers").on("change", function() {
        if ($("#editgroup_hidemembersfrommembers option:selected").val() != "0") {
            $("#editgroup_hidemembers").prop("selectedIndex", $("#editgroup_hidemembersfrommembers option:selected").val());
            $("#editgroup_hidemembers").prop("disabled", "disabled");
        }
        else {
            $("#editgroup_hidemembers").prop("disabled", false);
        }
    });
    $("#editgroup_submittableto").on("click", function() {
        if (this.checked) {
            $("#editgroup_allowarchives").prop("disabled", false);
            $("#editgroup_grouparchivereports").prop("disabled", false);
        }
        else {
            $("#editgroup_allowarchives").prop("checked", false);
            $("#editgroup_allowarchives").prop("disabled", true);
            $("#editgroup_grouparchivereports").prop("checked", false);
            $("#editgroup_grouparchivereports").prop("disabled", true);
        }
    });
});
';

$smarty = smarty();
$smarty->assign('form', $editgroup);
$smarty->assign('PAGEHEADING', !empty($group_data->name) ? $group_data->name : TITLE);
$smarty->assign('INLINEJAVASCRIPT', $js);
$smarty->display('form.tpl');
