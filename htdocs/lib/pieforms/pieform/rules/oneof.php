<?php
/**
 * Pieforms: Advanced web forms made easy
 * Copyright (C) 2006-2008 Catalyst IT Ltd (http://www.catalyst.net.nz)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    pieform
 * @subpackage rule
 * @author     Nigel McNie <nigel@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

/**
 * Checks whether the field has been specified.
 *
 * @param Pieform $form    The form the rule is being applied to
 * @param string  $value   The value of the field
 * @param array   $element The element to check
 * @param string  $check   The oneof value to match on
 * @return string         The error message, if the value is invalid.
 */
function pieform_rule_oneof(Pieform $form, $value, $element, $check) {/*{{{*/
    if ($check) {
        $oneof = false;
        foreach ($form->get_property('elements') as $name => $element) {
            foreach ($element as $opt => $val) {
                if ($opt === 'type' && $val === 'fieldset') {
                    $oneof = pieform_rule_oneof_fieldset_element($form, $element, $check, $oneof);
                }
                else if ($opt == 'rules' && isset($val['oneof']) && !empty($val['oneof']) && $val['oneof'] === $check) {
                    $refelement = $form->get_element($name);
                    $refvalue = $form->get_value($refelement);
                    if (is_bool($refvalue)) {
                        $refvalue = $refvalue ? 'true' : 'false';
                    }
                    if (!empty($refvalue)) {
                        $oneof = true;
                    }
                }
            }
        }
        if (!$oneof) {
            return $form->i18n('rule', 'oneof', 'oneof', $element);
        }
    }
    return '';
}/*}}}*/

/**
 * Checks whether the field has been specified inside a fieldset
 *
 * If we have a fieldset in our form we should look into their elements to see
 * if the 'oneof' rule exists there
 * Note: for sanity if we are having oneof fields in a fieldset all options should
 * live in the same fieldset
 *
 * @param Pieform $form     The form the rule is being applied to
 * @param array   $fieldset The fieldset to check
 * @param string  $check    The oneof value to match on
 * @param boolean $oneof    Whether one of the matching elements is not empty
 * @return boolean
 */
function pieform_rule_oneof_fieldset_element(Pieform $form, $fieldset, $check, $oneof) {

    foreach ($fieldset['elements'] as $name => $element) {
        foreach ($element as $opt => $val) {
            if ($opt === 'type' && $val === 'fieldset') {
                $oneof = pieform_rule_oneof_fieldset_element($form, $element, $check, $oneof);
            }
            else if ($opt == 'rules' && isset($val['oneof']) && !empty($val['oneof']) && $val['oneof'] === $check) {
                $refelement = $form->get_element($name);
                $refvalue = $form->get_value($refelement);
                if (is_bool($refvalue)) {
                    $refvalue = $refvalue ? 'true' : 'false';
                }
                if (!empty($refvalue)) {
                    $oneof = true;
                }
            }
        }
    }
    return $oneof;
}