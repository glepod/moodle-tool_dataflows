<?php
// This file is part of Moodle - http://moodle.org/
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

namespace tool_dataflows;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use tool_dataflows\application_trait;

defined('MOODLE_INTERNAL') || die();

// Include lib.php functions that aren't included automatically for Moodle 37- and below.
require_once(__DIR__ . '/application_trait.php');
require_once(__DIR__ . '/../lib.php');
// This is needed. File will not be automatically included.
require_once(__DIR__ . '/execution/array_in_type.php');

/**
 * Units tests for tool_dataflows
 *
 * @package    tool_dataflows
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2022
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_dataflows_test extends \advanced_testcase {
    use application_trait;

    /**
     * Set up before each test
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * @covers \tool_dataflows\step\debugging
     */
    public function test_debugging_step(): void {
        $step = new step\debugging();
        $input = [1, 2, 3, 4, 5];
        $output = $step->execute($input);

        // No changes are expected.
        $this->assertEquals($input, $output);

        // Debugging was called with the expected format.
        $this->assertDebuggingCalled(json_encode($input));
    }

    /**
     * @covers \tool_dataflows\dataflow
     */
    public function test_creating_empty_dataflow(): void {
        $name = 'test dataflow';
        $persistent = new \tool_dataflows\dataflow();
        $persistent
            ->set('name', $name)
            ->create();

        $this->assertEquals($name, $persistent->get('name'));
        $this->assertEquals($name, $persistent->name);
        $persistent->read();
        $this->assertEquals($name, $persistent->get('name'));
        $this->assertEquals($name, $persistent->name);
        $this->assertTrue($persistent->is_valid());

        // Get the id to confirm the record is stored and will load as expected.
        $id = $persistent->get('id');

        $anotherflow = new \tool_dataflows\dataflow($id);
        $this->assertEquals($name, $anotherflow->name);
    }

    /**
     * @covers \tool_dataflows\step
     * @covers \tool_dataflows\dataflow::get_dotscript
     */
    public function test_dependent_steps_and_dot_script(): void {
        $name = 'test dataflow';
        $dataflow = new \tool_dataflows\dataflow();
        $dataflow
            ->set('name', $name)
            ->create();

        $stepone = new \tool_dataflows\step();
        $stepone->name = 'step1';
        $stepone->type = execution\array_in_type::class;
        $dataflow->add_step($stepone);

        $steptwo = new \tool_dataflows\step();
        $steptwo->name = 'step2';
        $steptwo->type = step\debugging::class;
        $steptwo->depends_on([$stepone]);
        $dataflow->add_step($steptwo);

        $stepthree = new \tool_dataflows\step();
        $stepthree->name = 'step3';
        $stepthree->type = step\debugging::class;
        $stepthree->depends_on([$steptwo]);
        $dataflow->add_step($stepthree);

        $dotscript = $dataflow->get_dotscript();
        // PHPUnit backwards compatibility check.
        $this->compatible_assertStringContainsString($steptwo->name, $dotscript);
        $this->compatible_assertStringContainsString($stepone->name, $dotscript);

        // Ensure dependency chain exists.
        $this->compatible_assertMatchesRegularExpression("/{$stepone->name}.*->.*{$steptwo->name}/", $dotscript);
        $this->compatible_assertDoesNotMatchRegularExpression("/{$steptwo->name}.*->.*{$stepone->name}/", $dotscript);

        // Confirm that dataflow is valid.
        $validation = $dataflow->validate_dataflow();
        $this->assertTrue($validation);
    }

    /**
     * @covers \tool_dataflows\step
     * @covers \tool_dataflows\dataflow::validate_dataflow
     */
    public function test_dataflow_validation(): void {
        $name = 'test dataflow';
        $dataflow = new \tool_dataflows\dataflow();
        $dataflow
            ->set('name', $name)
            ->create();

        $stepone = new \tool_dataflows\step();
        $stepone->name = 'step1';
        $stepone->type = execution\array_in_type::class;
        $dataflow->add_step($stepone);

        $steptwo = new \tool_dataflows\step();
        $steptwo->name = 'step2';
        $steptwo->type = step\debugging::class;
        $steptwo->depends_on([$stepone]);
        $dataflow->add_step($steptwo);

        $stepthree = new \tool_dataflows\step();
        $stepthree->name = 'step3';
        $stepthree->type = step\debugging::class;
        $stepthree->depends_on([$steptwo]);
        $dataflow->add_step($stepthree);

        // Confirm that dataflow is valid.
        $validation = $dataflow->validate_dataflow();
        $this->assertTrue($validation);

        // Confirm the dataflow is now invalid (three->one->two->three - circular dependency).
        $stepone->depends_on([$stepthree])->upsert();
        $validation = $dataflow->validate_dataflow();
        $this->assertNotTrue($validation);
    }

    /**
     * Test custom step type based on the callback defined in the manager.
     *
     * @covers \tool_dataflows\dataflow\manager::get_steps_types
     */
    public function test_local_aws_custom_step() {
        // The test should only run if this condition is true.
        $localawsexampleclass = class_exists(\local_aws\step\example::class);
        if (!$localawsexampleclass) {
            $this->markTestSkipped('local_aws with custom step not located, skipping this specific test.');
            return;
        }

        $steptypes = manager::get_steps_types();
        $classnames = array_map(function ($class) {
            return get_class($class);
        }, $steptypes);
        $this->assertContains(\local_aws\step\example::class, $classnames);
    }

    /**
     * @covers \tool_dataflows\graph::is_dag
     */
    public function test_dag_check() {
        $edges = [
            [0, 1], [0, 3],
            [1, 2], [1, 3],
            [3, 2], [3, 4],
            [5, 6],
            [6, 3],
        ];
        $isdag = \tool_dataflows\graph::is_dag($edges);
        $this->assertTrue($isdag);

        // Introduce a back-edge, which will make the graph an invalid DAG.
        $edges[] = [3, 0];
        $isdag = \tool_dataflows\graph::is_dag($edges);
        $this->assertNotTrue($isdag);

        // Text based edges for certainty it works also with named edges.
        $edges = [
            ['a', 'b'], ['a', 'd'],
            ['b', 'c'], ['b', 'd'],
            ['d', 'c'], ['d', 'e'],
            ['f', 'g'],
            ['g', 'd'],
        ];
        $isdag = \tool_dataflows\graph::is_dag($edges);
        $this->assertTrue($isdag);

        // Test the ok (but weird) case if there are disconnected graphs inside a flow.
        $edges = [
            ['a', 'b'], ['a', 'd'],
            ['f', 'g'],
        ];
        $isdag = \tool_dataflows\graph::is_dag($edges);
        $this->assertTrue($isdag);
    }

    /**
     * Test the parsing and importing of a sample yaml file which could be used in an import.
     *
     * This test may likely expand to cover any specific cases used for dataflows.
     *
     * @covers \tool_dataflows\dataflow::import
     * @covers \tool_dataflows\dataflow::steps
     */
    public function test_dataflows_import() {
        // Import the sample dataflow file, containing 3 steps.
        $content = file_get_contents(dirname(__FILE__) . '/fixtures/sample.yml');
        $yaml = \Symfony\Component\Yaml\Yaml::parse($content);
        $dataflow = new dataflow();
        $dataflow->import($yaml);

        // Test the dataflow name.
        $this->assertEquals('Example Dataflow', $yaml['name']);
        $this->assertEquals($yaml['name'], $dataflow->name);

        // Test the dataflow imported steps.
        $steps = $dataflow->steps;
        $this->assertCount(3, (array) $steps);

        // Pull out the steps from the dataflow and check their values.
        foreach ($steps as $step) {
            switch ($step->alias) {
                case 'read':
                    $read = $step;
                    break;
                case 'debugging':
                    $debugging = $step;
                    break;
                case 'write':
                    $write = $step;
                    break;
                default:
                    break;
            }
        }
        $this->assertNotEmpty($read);
        $this->assertNotEmpty($debugging);
        $this->assertNotEmpty($write);

        // Test various yaml provided values to have confidence the import is working as expected.
        $this->assertEquals($yaml['steps']['read']['description'], $read->description);
        $this->assertEquals(json_encode($yaml['steps']['write']['config']), json_encode($write->config));
        $this->assertEquals($yaml['steps']['debugging']['type'], $debugging->type);
        // Check dependencies (read -> debugging -> write).
        $deps = $debugging->dependencies();
        $this->assertArrayHasKey($read->id, $deps);
        $deps = $write->dependencies();
        $this->assertArrayHasKey($debugging->id, $deps);
    }

    /**
     * @covers \tool_dataflows\dataflow::config
     * @covers \tool_dataflows\step::config
     * @covers \tool_dataflows\parser::evaluate
     * @covers \Symfony\Component\ExpressionLanguage\ExpressionLanguage
     */
    public function test_expressions_parsing() {
        // To register a custom function, we need to first register the provider
        // and pass it when constructing the expression language object.
        // See https://symfony.com/doc/current/components/expression_language/syntax.html#working-with-functions for details.
        $now = time();
        $variables = [
            'steps' => (object) [
                'read' => (object) ['timestamp' => $now],
                'debugging' => (object) [],
                'write' => (object) [],
            ],
        ];

        // Test reading a value directly.
        $expressionlanguage = new ExpressionLanguage();
        $result = $expressionlanguage->evaluate(
            'steps.read.timestamp',
            $variables
        );
        $this->assertEquals($now, $result);

        // Test evaluating an imported dataflow. The expressions would be
        // evaluated when relevant. For example, an import might have a few
        // expressions set, and the dataflow config will hold the expressions,
        // however only when it turns into an dataflow instance (e.g. via the
        // dataflow engine), will it attempt to evaluate the dataflow level
        // expressions from config. Similarly, expressions in steps will not be
        // evaluated until previous steps have run.
        $content = file_get_contents(dirname(__FILE__) . '/fixtures/sample-with-expressions.yml');
        $yaml = \Symfony\Component\Yaml\Yaml::parse($content);
        $dataflow = new dataflow();
        $dataflow->import($yaml);
        $config = $dataflow->config;
        $this->assertNotEquals($yaml['config']['expression'],  $config->expression);
        $this->assertEquals($dataflow->id,  $config->expression_test_id);
        $this->assertEquals($dataflow->id + 777,  $config->expression_math); // Adding an fixed number.
        $this->assertEquals('notifyread_value',  $config->expression_concat); // Using the ~ operator.
        $this->compatible_assertStringContainsString('steps notify and Read a value',  $config->expression);

        // TODO: Add tests for parsing during a dataflow run (via the dataflow engine).
    }

    /**
     * Tests adding steps and steps without dependencies attached to anything, and ensuring they are listed
     *
     * @covers \tool_dataflows\dataflow::get_steps
     */
    public function test_adding_and_listing_steps() {
        // Test multiple steps with one detached node.
        $dataflow = new \tool_dataflows\dataflow();
        $dataflow
            ->set('name', 'add detached steps')
            ->create();

        $stepone = new \tool_dataflows\step();
        $stepone->name = 'step1';
        $stepone->type = execution\array_in_type::class;
        $dataflow->add_step($stepone);

        $steptwo = new \tool_dataflows\step();
        $steptwo->name = 'step2';
        $steptwo->type = step\debugging::class;
        $steptwo->depends_on([$stepone]);
        $dataflow->add_step($steptwo);

        $stepthree = new \tool_dataflows\step();
        $stepthree->name = 'step3';
        $stepthree->type = step\debugging::class;
        $stepthree->depends_on([$steptwo]);
        $dataflow->add_step($stepthree);

        $stepdetached = new \tool_dataflows\step();
        $stepdetached->name = 'detachedstep';
        $stepdetached->type = step\debugging::class;
        $dataflow->add_step($stepdetached);

        $this->assertCount(4, (array) $dataflow->steps);

        // Test a dataflow with a single detached node.
        $dataflow = new \tool_dataflows\dataflow();
        $dataflow
            ->set('name', 'add detached step')
            ->create();

        $stepone = new \tool_dataflows\step();
        $stepone->name = 'alone';
        $stepone->type = execution\array_in_type::class;
        $dataflow->add_step($stepone);

        $this->assertCount(1, (array) $dataflow->steps);
    }

    /**
     * Tests export of a dataflow. The easiest way to do this is to have an
     * import file, that matches a would-be export file exactly.
     *
     * This should ensure all the required fields and parameters are displayed as expected.
     *
     * @covers \tool_dataflows\dataflow::get_export_data
     * @covers \tool_dataflows\step::get_export_data
     * @covers \tool_dataflows\exportable::export
     */
    public function test_export_of_dataflow() {
        $content = file_get_contents(dirname(__FILE__) . '/fixtures/sample-export.yml');
        $yaml = \Symfony\Component\Yaml\Yaml::parse($content);
        $dataflow = new dataflow();
        $dataflow->import($yaml);
        $exportdata = $dataflow->export();
        $this->assertEquals($content, $exportdata);
    }

    /**
     * Test deleting a step after adding it.
     *
     * Deletion should be possible via direct step deletion, and from the dataflow
     *
     * TODO: include special domain specific requirement:
     * - should rewire the before/after steps of the step removed if it is valid to do so
     *
     * @covers \tool_dataflows\step::before_delete
     * @covers \tool_dataflows\dataflow::remove_step
     */
    public function test_step_deletion() {
        $dataflow = new dataflow();

        $step1 = new \tool_dataflows\step();
        $step1->name = 'delete_via_step';
        $step1->type = execution\array_in_type::class;
        $dataflow->add_step($step1);

        $step2 = new \tool_dataflows\step();
        $step2->name = 'oneisalonelynumber';
        $step2->type = execution\array_in_type::class;
        $step2->depends_on([$step1]);
        $dataflow->add_step($step2);

        $step3 = new \tool_dataflows\step();
        $step3->name = 'delete_via_dataflow';
        $step3->type = execution\array_in_type::class;
        $step3->depends_on([$step2]);
        $dataflow->add_step($step3);

        // Check current step count.
        $this->assertCount(3, (array) $dataflow->steps);
        // Ensure the linked dependencies are present.
        $this->assertSeeInDatabase('tool_dataflows_step_depends', ['stepid' => $step2->id, 'dependson' => $step1->id]);

        // Remove step1, the count should be one less.
        $step1->delete();
        $this->assertCount(2, (array) $dataflow->steps);
        // Ensure the linked dependencies are no longer present, since the dependency has been removed.
        $this->assertNotSeeInDatabase('tool_dataflows_step_depends', ['dependson' => $step1->id]);

        // Remove step3, the count should be one less.
        $dataflow->remove_step($step3);
        $this->assertCount(1, (array) $dataflow->steps);
        // Confirm the dependency no longer exists, since this step is removed.
        $this->assertNotSeeInDatabase('tool_dataflows_step_depends', ['stepid' => $step3->id]);
    }
}
