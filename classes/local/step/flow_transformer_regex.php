<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_dataflows\local\step;

/**
 * Flow regex (transformer step) class
 *
 * Alter the values being passed down a flow.
 *
 * @package    tool_dataflows
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  Catalyst IT, 2023
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flow_transformer_regex extends flow_transformer_step {

    /** @var int[] number of input flows (min, max). */
    protected $inputflows = [1, 1];

    /** @var int[] number of output flows (min, max). */
    protected $outputflows = [1, 1];

    /**
     * Return the definition of the fields available in this form.
     *
     * @return array
     */
    public static function form_define_fields(): array {
        return [
            'pattern' => ['type' => PARAM_RAW, 'required' => true],
            'field' => ['type' => PARAM_TEXT, 'required' => true],
            'replacenull' => ['type' => PARAM_BOOL, 'required' => true],
        ];
    }

    /**
     * Allows each step type to determine a list of optional/required form
     * inputs for their configuration
     *
     * It's recommended you prefix the additional config related fields to avoid
     * conflicts with any existing fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public function form_add_custom_inputs(\MoodleQuickForm &$mform) {
        $mform->addElement(
            'text',
            'config_pattern',
            get_string('flow_transformer_regex:pattern', 'tool_dataflows'),
            [
                'placeholder' => "/[abc]/",
                'size' => '60',
            ]
        );
        $mform->addElement(
            'static',
            'config_pattern_help',
            '',
            get_string('flow_transformer_regex:pattern_help', 'tool_dataflows')
        );
        $mform->addElement(
            'text',
            'config_field',
            get_string('flow_transformer_regex:field', 'tool_dataflows')
        );
        $mform->addElement(
            'static',
            'config_field_help',
            '',
            get_string('flow_transformer_regex:field_help', 'tool_dataflows')
        );
        $mform->addElement(
            'checkbox',
            'config_replacenull',
            get_string('flow_transformer_regex:replacenull', 'tool_dataflows')
        );
        $mform->addElement(
            'static',
            'config_replacenull_help',
            '',
            get_string('flow_transformer_regex:replacenull_help', 'tool_dataflows')
        );
    }

    /**
     * Apply the filter based on configuration
     *
     * @param  mixed $input
     * @return mixed The new value to be passed on to the next step.
     */
    public function execute($input = null) {
        $variables = $this->get_variables();
        $pattern = $this->stepdef->config->pattern;
        $field = $this->stepdef->config->field;
        $haystack = $variables->evaluate($field);
        $hasnamedcapturegroups = false;

        // Get options from pattern. SED style regex to seperate match and replace parameters.
        // TODO: add support for flags eg. /g /m (global, multi-line etc).
        [$replace, $pattern, $flags] = self::get_pattern_options($pattern);

        // Process either match or replace.
        if ($replace) {
            $result = self::regex_replace($pattern, $haystack);
        } else {
            [$hasnamedcapturegroups, $result] = self::regex_match($pattern, $haystack);
        }

        // Support named capture groups.
        // Otherwise set the first match (or null) to the field named as the step alias.
        if ($hasnamedcapturegroups) {
            $input = (object) array_merge(
                (array) $input, $result);
        } else {
            $uniquekey = $variables->get('alias');
            $input->$uniquekey = $result[0];
        }

        return $input;
    }

    /**
     * Gets match and replace parameters from SED style regex.
     * This determines if preg_match or preg_replace is used.
     * @param string $fullpattern SED style regex
     * @return array match and replace parameters
     */
    private function get_pattern_options(string $fullpattern): array {
        $matches = [];
        $pattern = '/(.*?)(\/.*\/)(.*)/';
        preg_match($pattern, $fullpattern, $matches);
        // Having a 's/' at the start of the pattern denotes search and replace.
        // eg 's/<search regex>/<replacement>/<flags>'.
        $replace = $matches[1] === 's';
        $pattern = $matches[2];
        $flags = $matches[3];

        return [$replace, $pattern, $flags];
    }

    /**
     * Gets the search and replace parameters from the fullpattern.
     * @param string $fullpattern SED style regex
     * @return array search and replace parameters
     */
    private function get_pattern_substitution(string $fullpattern): array {
        $matches = [];
        $pattern = '/(.*[^\\\\]\/)(.*)\//';
        preg_match($pattern, $fullpattern, $matches);
        $pattern = $matches[1];
        $substitution = $matches[2];

        return [$pattern, $substitution];
    }

    /**
     * Process regex search and replace and returns result.
     * @param string $pattern
     * @param string $haystack
     * @return array Results of the regex search and replace
     */
    private function regex_replace(string $pattern, string $haystack): array {
        $result = [];
        [$pattern, $substitution] = self::get_pattern_substitution($pattern);

        $result[] = preg_replace($pattern, $substitution, $haystack);
        return $result;
    }

    /**
     * Process regex matches and returns results.
     * @param string $pattern
     * @param string $haystack
     * @return array Results of the regex match
     */
    private function regex_match(string $pattern, string $haystack): array {
        $matches = [];
        $result = [];

        preg_match($pattern, $haystack, $matches);

        // Support named capture groups.
        $hasnamedcapturegroups = false;
        foreach ($matches as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            $hasnamedcapturegroups = true;
            $result[$key] = $value;
        }

        // Otherwise set the first match (or null) to the field named as the step alias.
        if (!$hasnamedcapturegroups) {
            // Capture the first matched string as a variable.
            $result[] = $matches[0] ?? null;
        }

        return [$hasnamedcapturegroups, $result];
    }
}
